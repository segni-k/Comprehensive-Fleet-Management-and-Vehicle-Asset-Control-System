<?php

namespace App\Http\Controllers\Operations;

use App\Documents\Models\Document;
use App\Documents\Models\DocumentType;
use App\Documents\Services\DocumentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'size:26'],
            'status' => ['nullable', 'string', 'max:30'],
            'category' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'document.view', $data['organization_id']);
        $documents = Document::query()->with(['type', 'currentVersion'])
            ->where('organization_id', $data['organization_id'])
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($data['category'] ?? null, fn ($query, $value) => $query->where('category', $value))
            ->latest()
            ->paginate($data['per_page'] ?? 25);

        return response()->json(DocumentResource::collection($documents)->response()->getData(true));
    }

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $this->authorizeOrganization(
            $request,
            'document.upload',
            $request->string('organization_id')->toString(),
        );
        $this->assertFleetOwnerScope(
            $request->string('owner_type')->toString(),
            $request->string('owner_id')->toString(),
            $request->string('organization_id')->toString(),
        );
        $type = DocumentType::query()->where('code', $request->string('document_type_code'))->where('status', 'active')->firstOrFail();
        abort_unless(
            (! str_starts_with($type->code, 'VEHICLE_') || $request->string('owner_type')->toString() === 'vehicle')
            && (! str_starts_with($type->code, 'DRIVER_') || $request->string('owner_type')->toString() === 'driver'),
            422,
        );
        $document = $this->documents->upload(
            $request->file('file'),
            $type,
            $request->user(),
            $request->string('organization_id')->toString(),
            $request->string('owner_type')->toString(),
            $request->string('owner_id')->toString(),
            $request->string('category')->toString(),
            $request->string('classification')->toString(),
            $request->date('expires_at'),
        );

        return (new DocumentResource($document))->response()->setStatusCode(202);
    }

    public function show(Request $request, Document $document): DocumentResource
    {
        $this->authorizeDocument($request, $document, 'document.view');

        return new DocumentResource($document->load(['type', 'currentVersion']));
    }

    public function history(Request $request, Document $document): JsonResponse
    {
        $this->authorizeDocument($request, $document, 'document.view');

        return response()->json(['data' => $document->versions()
            ->select(['id', 'document_id', 'version_number', 'supersedes_version_id', 'original_filename', 'media_type', 'size_bytes', 'checksum_algorithm', 'checksum', 'scan_status', 'trust_status', 'trusted_at', 'created_at'])
            ->with('scanAttempts')
            ->latest('version_number')
            ->get()]);
    }

    public function replace(Request $request, Document $document): DocumentResource
    {
        $this->authorizeDocument($request, $document, 'document.replace');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);

        return new DocumentResource($this->documents->replace($document, $data['file'], $request->user(), $data['record_version']));
    }

    public function archive(Request $request, Document $document): DocumentResource
    {
        $this->authorizeDocument($request, $document, 'document.archive');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'record_version' => ['required', 'integer', 'min:1'],
        ]);

        return new DocumentResource($this->documents->archive($document, $request->user(), $data['reason'], $data['record_version']));
    }

    public function download(Request $request, Document $document): StreamedResponse
    {
        $this->authorizeDocument($request, $document, 'document.download');
        $session = $request->attributes->get('identity_session');

        return $this->documents->download($document->load('currentVersion'), $request->user(), $session instanceof UserSession ? $session : null);
    }

    private function authorizeDocument(Request $request, Document $document, string $permission): void
    {
        $this->authorizeOrganization($request, $permission, $document->organization_id, $document->id);
    }

    private function assertFleetOwnerScope(string $ownerType, string $ownerId, string $organizationId): void
    {
        if ($ownerType === 'vehicle') {
            abort_unless(DB::table('vehicles')->where('id', $ownerId)
                ->where('custodian_organization_id', $organizationId)->exists(), 404);
        }
        if ($ownerType === 'driver') {
            abort_unless(DB::table('drivers')->where('id', $ownerId)
                ->where('organization_id', $organizationId)->exists(), 404);
        }
    }

    private function authorizeOrganization(
        Request $request,
        string $permission,
        string $organizationId,
        ?string $documentId = null,
    ): void {
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession && $this->authorization->allows(
            $request->user(),
            $permission,
            $organizationId,
            $documentId === null ? null : 'document',
            $documentId,
            $session,
        ), 403);
    }
}
