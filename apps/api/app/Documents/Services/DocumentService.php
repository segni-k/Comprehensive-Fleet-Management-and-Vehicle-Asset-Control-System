<?php

namespace App\Documents\Services;

use App\Audit\Services\AuditService;
use App\Documents\Contracts\MalwareScanner;
use App\Documents\Models\Document;
use App\Documents\Models\DocumentScanAttempt;
use App\Documents\Models\DocumentType;
use App\Documents\Models\DocumentVersion;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentService
{
    public function __construct(
        private readonly MalwareScanner $scanner,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function upload(
        UploadedFile $file,
        DocumentType $type,
        User $actor,
        string $organizationId,
        string $ownerType,
        string $ownerId,
        string $category,
        string $classification,
        ?\DateTimeInterface $expiresAt = null,
    ): Document {
        $mediaType = $file->getMimeType() ?: 'application/octet-stream';
        if (! in_array($mediaType, $type->allowed_mime_types, true)) {
            throw ValidationException::withMessages(['file' => ['The detected file type is not permitted.']]);
        }
        if (($file->getSize() ?: 0) < 1 || ($file->getSize() ?: 0) > $type->maximum_bytes) {
            throw ValidationException::withMessages(['file' => ['The file size is outside the permitted range.']]);
        }

        return DB::transaction(function () use ($file, $type, $actor, $organizationId, $ownerType, $ownerId, $category, $classification, $expiresAt, $mediaType): Document {
            $document = Document::query()->create([
                'document_type_id' => $type->id,
                'organization_id' => $organizationId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'category' => $category,
                'classification' => $classification,
                'created_by' => $actor->id,
                'expires_at' => $expiresAt,
                'status' => 'quarantined',
            ]);
            $version = $this->storeVersion($document, $file, $actor, null, $mediaType);
            $document->forceFill(['current_version_id' => $version->id])->save();
            $document->links()->create([
                'linked_entity_type' => $ownerType,
                'linked_entity_id' => $ownerId,
                'purpose' => $category,
                'linked_by' => $actor->id,
            ]);
            $this->audit->record(
                'document.uploaded.succeeded', 'document', 'upload', 'succeeded',
                'document', $document->id, $organizationId, $actor, null,
                'Document uploaded to quarantine.', null,
                ['status' => 'quarantined', 'media_type' => $mediaType, 'size_bytes' => $version->size_bytes, 'checksum' => $version->checksum],
                null, 'information', 'normal',
            );
            $this->outbox->enqueue(
                'documents.scan.requested', 'document_version', $version->id,
                ['document_version_id' => $version->id, 'document_id' => $document->id],
                'document-scan:'.$version->id, $organizationId,
            );

            return $document->load(['type', 'currentVersion', 'links']);
        });
    }

    public function replace(Document $document, UploadedFile $file, User $actor, int $expectedVersion): Document
    {
        return DB::transaction(function () use ($document, $file, $actor, $expectedVersion): Document {
            /** @var Document $locked */
            $locked = Document::query()->with(['type', 'currentVersion'])->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->record_version !== $expectedVersion || $locked->status === 'archived') {
                throw new BusinessRuleException('DOCUMENT_VERSION_CONFLICT', 'The document changed or is no longer replaceable.');
            }
            $mediaType = $file->getMimeType() ?: 'application/octet-stream';
            $size = $file->getSize() ?: 0;
            if (! in_array($mediaType, $locked->type->allowed_mime_types, true) || $size < 1 || $size > $locked->type->maximum_bytes) {
                throw ValidationException::withMessages(['file' => ['The replacement does not meet the document policy.']]);
            }
            $before = [
                'version_id' => $locked->currentVersion->id,
                'status' => $locked->currentVersion->trust_status,
            ];
            $version = $this->storeVersion($locked, $file, $actor, $locked->currentVersion, $mediaType);
            $locked->currentVersion->forceFill(['trust_status' => 'superseded'])->save();
            $locked->forceFill([
                'current_version_id' => $version->id,
                'status' => 'quarantined',
                'record_version' => $locked->record_version + 1,
            ])->save();
            $this->outbox->enqueue(
                'documents.scan.requested', 'document_version', $version->id,
                ['document_version_id' => $version->id, 'document_id' => $locked->id],
                'document-scan:'.$version->id, $locked->organization_id,
            );
            $this->audit->record(
                'document.replaced.succeeded', 'document', 'replace', 'succeeded',
                'document', $locked->id, $locked->organization_id, $actor,
                reason: 'Document version replaced.',
                before: $before,
                after: ['version_id' => $version->id, 'status' => $version->trust_status],
            );

            return $locked->refresh()->load(['type', 'currentVersion', 'versions']);
        });
    }

    public function scan(DocumentVersion $version): DocumentVersion
    {
        $started = now();
        $result = $this->scanner->scan($version);

        return DB::transaction(function () use ($version, $started, $result): DocumentVersion {
            $version->load('document');
            DocumentScanAttempt::query()->create([
                'document_version_id' => $version->id,
                'scanner_adapter' => $this->scanner::class,
                'scanner_reference' => $result['reference'],
                'outcome' => $result['outcome'],
                'failure_class' => $result['failure_class'],
                'safe_metadata' => ['adapter_result' => $result['outcome']],
                'started_at' => $started,
                'completed_at' => $result['outcome'] === 'pending' ? null : now(),
            ]);
            if ($result['outcome'] === 'clean') {
                $version->forceFill(['scan_status' => 'clean', 'trust_status' => 'trusted', 'trusted_at' => now()])->save();
                $version->document()->update(['status' => 'trusted']);
            } elseif ($result['outcome'] === 'infected') {
                $version->forceFill(['scan_status' => 'infected', 'trust_status' => 'rejected'])->save();
                $version->document()->update(['status' => 'rejected']);
            } elseif ($result['outcome'] === 'failed') {
                $version->forceFill(['scan_status' => 'failed'])->save();
            }
            $this->audit->record(
                'document.scan.'.$result['outcome'], 'document', 'scan', $result['outcome'],
                'document_version', $version->id, $version->document->organization_id,
                reason: 'Document trust scan completed.',
                after: ['scan_status' => $version->scan_status, 'trust_status' => $version->trust_status],
                severity: in_array($result['outcome'], ['infected', 'failed'], true) ? 'warning' : 'information',
            );
            $this->outbox->enqueue(
                'documents.scan.completed', 'document_version', $version->id,
                ['document_version_id' => $version->id, 'outcome' => $result['outcome']],
                'document-scan-completed:'.$version->id.':'.$result['outcome'],
                $version->document->organization_id,
            );

            return $version->refresh();
        });
    }

    public function archive(Document $document, User $actor, string $reason, int $expectedVersion): Document
    {
        return DB::transaction(function () use ($document, $actor, $reason, $expectedVersion): Document {
            /** @var Document $locked */
            $locked = Document::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($locked->record_version !== $expectedVersion || $locked->status === 'archived') {
                throw new BusinessRuleException('DOCUMENT_VERSION_CONFLICT', 'The document changed after it was loaded.');
            }
            $before = ['status' => $locked->status, 'record_version' => $locked->record_version];
            $locked->forceFill([
                'status' => 'archived',
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'archive_reason' => $reason,
                'record_version' => $locked->record_version + 1,
            ])->save();
            $this->audit->record(
                'document.archived.succeeded', 'document', 'archive', 'succeeded',
                'document', $locked->id, $locked->organization_id, $actor,
                reason: $reason,
                before: $before,
                after: ['status' => 'archived', 'record_version' => $locked->record_version],
            );
            $this->outbox->enqueue(
                'document.archived', 'document', $locked->id,
                ['document_id' => $locked->id],
                'document-archived:'.$locked->id, $locked->organization_id,
            );

            return $locked->refresh();
        });
    }

    public function download(Document $document, User $actor, ?UserSession $session = null): StreamedResponse
    {
        $version = $document->currentVersion;
        if ($document->status !== 'trusted' || $version === null || $version->trust_status !== 'trusted') {
            throw new BusinessRuleException('DOCUMENT_NOT_TRUSTED', 'Only trusted document versions may be downloaded.');
        }
        if (! Storage::disk($version->storage_disk)->exists($version->storage_key)) {
            throw new BusinessRuleException('DOCUMENT_CONTENT_UNAVAILABLE', 'Document content is unavailable.');
        }
        $this->audit->record(
            'document.downloaded.succeeded', 'document', 'download', 'succeeded',
            'document', $document->id, $document->organization_id, $actor, $session,
            'Authorized document download.', null, null,
            ['version_id' => $version->id, 'classification' => $document->classification],
            'information', 'normal',
        );

        return Storage::disk($version->storage_disk)->download(
            $version->storage_key,
            $version->original_filename,
            ['Content-Type' => $version->media_type, 'Cache-Control' => 'private, no-store'],
        );
    }

    private function storeVersion(
        Document $document,
        UploadedFile $file,
        User $actor,
        ?DocumentVersion $superseded,
        string $mediaType,
    ): DocumentVersion {
        $storageDisk = (string) config('documents.private_disk', 'local');
        $storageKey = trim((string) config('documents.quarantine_prefix', 'quarantine'), '/').'/'.now()->format('Y/m').'/'.Str::ulid();
        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false || ! Storage::disk($storageDisk)->put($storageKey, $stream)) {
            throw new BusinessRuleException('DOCUMENT_STORAGE_FAILED', 'The document could not be stored.');
        }
        if (is_resource($stream)) {
            fclose($stream);
        }

        return DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version_number' => $superseded === null ? 1 : $superseded->version_number + 1,
            'supersedes_version_id' => $superseded?->id,
            'storage_disk' => $storageDisk,
            'storage_key' => $storageKey,
            'original_filename' => basename($file->getClientOriginalName()),
            'media_type' => $mediaType,
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'uploaded_by' => $actor->id,
            'scan_status' => 'pending',
            'trust_status' => 'quarantined',
            'created_at' => now(),
        ]);
    }
}
