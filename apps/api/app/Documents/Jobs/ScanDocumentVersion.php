<?php

namespace App\Documents\Jobs;

use App\Documents\Models\DocumentVersion;
use App\Documents\Services\DocumentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ScanDocumentVersion implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly string $documentVersionId) {}

    public function handle(DocumentService $documents): void
    {
        $version = DocumentVersion::query()->whereKey($this->documentVersionId)->firstOrFail();
        if ($version->scan_status === 'pending') {
            $documents->scan($version);
        }
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
