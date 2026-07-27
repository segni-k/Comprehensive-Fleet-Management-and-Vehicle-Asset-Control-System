<?php

namespace App\Geography\Services;

use App\Audit\Services\AuditService;
use App\Documents\Models\Document;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Outbox\Services\OutboxService;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use stdClass;
use ZipArchive;

final class GeographyImportService
{
    private const MAX_ROWS = 100_000;

    public function __construct(
        private readonly GeographyRegistryService $registry,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function stage(
        string $organizationId,
        string $importType,
        Document $document,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): stdClass {
        $document->load('currentVersion');
        $version = $document->currentVersion;
        if (
            $document->organization_id !== $organizationId ||
            $document->status !== 'trusted' ||
            $version === null ||
            $version->trust_status !== 'trusted'
        ) {
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_DOCUMENT_UNTRUSTED', 'Only a trusted scanned import document in the same organization may be staged.');
        }
        $content = Storage::disk($version->storage_disk)->get($version->storage_key);
        if (! is_string($content) || $content === '') {
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_CONTENT_UNAVAILABLE', 'The trusted import content is unavailable.');
        }
        $extension = strtolower(pathinfo($version->original_filename, PATHINFO_EXTENSION));
        $rows = match ($extension) {
            'csv' => $this->parseCsv($content),
            'xlsx' => $this->parseXlsx($content),
            default => throw new BusinessRuleException('GEOGRAPHY_IMPORT_FORMAT_UNSUPPORTED', 'Only CSV and XLSX geography imports are supported.'),
        };
        if ($rows === [] || count($rows) > self::MAX_ROWS) {
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_ROW_LIMIT', 'The import must contain between one and 100,000 data rows.');
        }
        [$validatedRows, $summary] = $this->validateRows($importType, $rows, $organizationId);
        $id = (string) Str::ulid();

        DB::transaction(function () use ($id, $organizationId, $importType, $version, $validatedRows, $summary, $actor, $session, $request): void {
            $now = now();
            DB::table('route_distance_imports')->insert([
                'id' => $id,
                'organization_id' => $organizationId,
                'import_type' => $importType,
                'source_name' => $version->original_filename,
                'source_checksum' => $version->checksum,
                'document_id' => $version->document_id,
                'row_count' => $summary['row_count'],
                'valid_row_count' => $summary['valid_row_count'],
                'invalid_row_count' => $summary['invalid_row_count'],
                'validation_summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                'status' => $summary['invalid_row_count'] === 0 ? 'validated' : 'validation_failed',
                'imported_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($validatedRows as $row) {
                DB::table('route_distance_import_rows')->insert([
                    'id' => (string) Str::ulid(),
                    'import_id' => $id,
                    'row_number' => $row['row_number'],
                    'normalized_payload' => json_encode($row['payload'], JSON_THROW_ON_ERROR),
                    'validation_errors' => $row['errors'] === [] ? null : json_encode($row['errors'], JSON_THROW_ON_ERROR),
                    'status' => $row['errors'] === [] ? 'valid' : 'invalid',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->record('geography.import.staged', 'stage', $id, $organizationId, $actor, $session, $request, [
                'import_type' => $importType,
                'source_checksum' => $version->checksum,
                'row_count' => $summary['row_count'],
                'invalid_row_count' => $summary['invalid_row_count'],
            ]);
        });

        return DB::table('route_distance_imports')->where('id', $id)->firstOrFail();
    }

    public function approve(
        string $importId,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): stdClass {
        return DB::transaction(function () use ($importId, $actor, $session, $request): stdClass {
            $batch = DB::table('route_distance_imports')->where('id', $importId)->lockForUpdate()->first();
            if ($batch === null) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_NOT_FOUND', 'The import batch was not found.');
            }
            if ($batch->status !== 'validated' || (int) $batch->invalid_row_count !== 0) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_NOT_APPROVABLE', 'Only a fully valid staged import may be approved.');
            }
            if ($batch->imported_by === $actor->id) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_MAKER_CHECKER', 'The importer cannot approve their own batch.');
            }
            $rows = DB::table('route_distance_import_rows')->where('import_id', $importId)->where('status', 'valid')->orderBy('row_number')->get();
            $applied = match ($batch->import_type) {
                'places' => $this->applyPlaces($batch, $rows, $actor, $session, $request),
                'distance_matrix' => $this->applyDistanceMatrix($batch, $rows, $actor, $session, $request),
                'routes' => $this->applyRoutes($batch, $rows, $actor, $session, $request),
                default => throw new BusinessRuleException('GEOGRAPHY_IMPORT_TYPE_INVALID', 'The import type is not supported.'),
            };
            DB::table('route_distance_imports')->where('id', $importId)->update([
                'status' => 'approved_applied_draft',
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
            $this->record('geography.import.approved', 'approve', $importId, $batch->organization_id, $actor, $session, $request, [
                'import_type' => $batch->import_type,
                'applied_records' => $applied,
            ]);

            return DB::table('route_distance_imports')->where('id', $importId)->firstOrFail();
        }, 3);
    }

    public function rollback(
        string $importId,
        string $reason,
        User $actor,
        ?UserSession $session,
        Request $request,
    ): stdClass {
        return DB::transaction(function () use ($importId, $reason, $actor, $session, $request): stdClass {
            $batch = DB::table('route_distance_imports')->where('id', $importId)->lockForUpdate()->first();
            if ($batch === null || $batch->status !== 'approved_applied_draft') {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_NOT_ROLLBACKABLE', 'Only an applied draft import may be rolled back.');
            }
            $rows = DB::table('route_distance_import_rows')->where('import_id', $importId)->where('status', 'applied')->get();
            foreach ($rows as $row) {
                match ($row->applied_entity_type) {
                    'place' => DB::table('places')->where('id', $row->applied_entity_id)->where('status', 'draft')->update(['status' => 'inactive', 'updated_at' => now()]),
                    'route_master' => DB::table('route_masters')->where('id', $row->applied_entity_id)->where('status', 'draft')->update(['status' => 'inactive', 'updated_at' => now()]),
                    'distance_reference_version' => DB::table('distance_reference_versions')->where('id', $row->applied_entity_id)->where('status', 'draft')->update(['status' => 'retired', 'updated_at' => now()]),
                    default => 0,
                };
            }
            DB::table('route_distance_import_rows')->where('import_id', $importId)->where('status', 'applied')->update(['status' => 'rolled_back', 'updated_at' => now()]);
            DB::table('route_distance_imports')->where('id', $importId)->update([
                'status' => 'rolled_back',
                'rolled_back_by' => $actor->id,
                'rolled_back_at' => now(),
                'rollback_reason' => $reason,
                'updated_at' => now(),
            ]);
            $this->record('geography.import.rolled_back', 'rollback', $importId, $batch->organization_id, $actor, $session, $request, [
                'reason' => $reason,
                'retained_evidence_rows' => $rows->count(),
            ]);

            return DB::table('route_distance_imports')->where('id', $importId)->firstOrFail();
        }, 3);
    }

    /** @return list<array<string, string>> */
    private function parseCsv(string $content): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_PARSE_FAILED', 'The CSV import could not be opened.');
        }
        fwrite($stream, $content);
        rewind($stream);
        $header = fgetcsv($stream, 0, ',', '"', '\\');
        if (! is_array($header)) {
            fclose($stream);
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_HEADER_MISSING', 'The import header row is missing.');
        }
        $header = array_map(fn ($value): string => $this->normalizeHeader((string) $value), $header);
        $rows = [];
        while (($values = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if (count(array_filter($values, fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $values = array_pad(array_map(fn ($value): string => trim((string) $value), $values), count($header), '');
            $rows[] = array_combine($header, array_slice($values, 0, count($header)));
        }
        fclose($stream);

        return $rows;
    }

    /** @return list<array<string, string>> */
    private function parseXlsx(string $content): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'geo-import-');
        if ($temporary === false || file_put_contents($temporary, $content) === false) {
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_PARSE_FAILED', 'The XLSX import could not be prepared.');
        }
        $archive = new ZipArchive;
        try {
            if ($archive->open($temporary) !== true) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_PARSE_FAILED', 'The XLSX archive is invalid.');
            }
            $shared = $this->xlsxSharedStrings($archive);
            $sheetXml = $archive->getFromName('xl/worksheets/sheet1.xml');
            if (! is_string($sheetXml)) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_SHEET_MISSING', 'The first XLSX worksheet is missing.');
            }
            $document = new DOMDocument;
            if (! $document->loadXML($sheetXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_PARSE_FAILED', 'The XLSX worksheet is invalid.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $matrix = [];
            foreach ($xpath->query('//s:sheetData/s:row') ?: [] as $row) {
                if (! $row instanceof DOMElement) {
                    continue;
                }
                $values = [];
                foreach ($xpath->query('s:c', $row) ?: [] as $cell) {
                    if (! $cell instanceof DOMElement) {
                        continue;
                    }
                    $column = preg_replace('/\d+/', '', $cell->getAttribute('r'));
                    $index = is_string($column) ? $this->columnIndex($column) : 0;
                    $raw = trim($cell->textContent);
                    $values[$index] = $cell->getAttribute('t') === 's' ? ($shared[(int) $raw] ?? '') : $raw;
                }
                if ($values !== []) {
                    ksort($values);
                    $matrix[] = $values;
                }
            }
            if ($matrix === []) {
                throw new BusinessRuleException('GEOGRAPHY_IMPORT_HEADER_MISSING', 'The XLSX worksheet is empty.');
            }
            $headerValues = array_shift($matrix);
            $lastColumn = max(array_keys($headerValues));
            $header = [];
            for ($index = 0; $index <= $lastColumn; $index++) {
                $header[$index] = $this->normalizeHeader($headerValues[$index] ?? '');
            }
            $rows = [];
            foreach ($matrix as $values) {
                $row = [];
                foreach ($header as $index => $name) {
                    $row[$name] = trim((string) ($values[$index] ?? ''));
                }
                if (count(array_filter($row, fn ($value): bool => $value !== '')) > 0) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            $archive->close();
            @unlink($temporary);
        }
    }

    /** @return list<string> */
    private function xlsxSharedStrings(ZipArchive $archive): array
    {
        $xml = $archive->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }
        $document = new DOMDocument;
        if (! $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            return [];
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $strings = [];
        foreach ($xpath->query('//s:si') ?: [] as $item) {
            if ($item instanceof DOMElement) {
                $strings[] = trim($item->textContent);
            }
        }

        return $strings;
    }

    private function columnIndex(string $column): int
    {
        $index = 0;
        foreach (str_split($column) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function normalizeHeader(string $header): string
    {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $header)));
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return array{list<array{row_number:int,payload:array<string,string>,errors:list<string>}>,array<string,mixed>}
     */
    private function validateRows(string $importType, array $rows, string $organizationId): array
    {
        $required = match ($importType) {
            'places' => ['code', 'name_en', 'name_om', 'name_am', 'category_code', 'effective_from'],
            'distance_matrix' => ['version_code', 'version_name', 'source_reference', 'effective_from', 'origin_code', 'destination_code', 'distance_km', 'directional'],
            'routes' => ['code', 'name_en', 'name_om', 'name_am', 'origin_code', 'destination_code', 'distance_km', 'duration_minutes', 'source_reference', 'effective_from'],
            default => throw new BusinessRuleException('GEOGRAPHY_IMPORT_TYPE_INVALID', 'The import type is not supported.'),
        };
        $seen = [];
        $validated = [];
        $errorCodes = [];
        $distanceMetadata = null;
        foreach ($rows as $offset => $payload) {
            $errors = [];
            foreach ($required as $field) {
                if (! isset($payload[$field]) || trim($payload[$field]) === '') {
                    $errors[] = 'REQUIRED_'.$field;
                }
            }
            $businessKey = $importType === 'distance_matrix'
                ? implode('|', [$payload['version_code'] ?? '', $payload['origin_code'] ?? '', $payload['destination_code'] ?? '', $payload['route_label'] ?? ''])
                : ($payload['code'] ?? '');
            if ($businessKey !== '' && isset($seen[$businessKey])) {
                $errors[] = 'DUPLICATE_BUSINESS_KEY';
            }
            $seen[$businessKey] = true;
            if (($payload['effective_from'] ?? '') !== '') {
                try {
                    new \DateTimeImmutable($payload['effective_from']);
                } catch (Exception) {
                    $errors[] = 'EFFECTIVE_FROM_INVALID';
                }
            }
            if ($importType === 'places') {
                $this->validatePlaceRow($payload, $organizationId, $errors);
            } else {
                $this->validateRouteDistanceRow($payload, $organizationId, $errors);
            }
            if ($importType === 'routes' && ($payload['code'] ?? '') !== ''
                && DB::table('route_masters')->where('code', $payload['code'])->exists()) {
                $errors[] = 'ROUTE_CODE_EXISTS';
            }
            if ($importType === 'distance_matrix') {
                $metadata = implode('|', [
                    $payload['version_code'] ?? '',
                    $payload['version_name'] ?? '',
                    $payload['source_reference'] ?? '',
                    $payload['effective_from'] ?? '',
                ]);
                $distanceMetadata ??= $metadata;
                if ($metadata !== $distanceMetadata) {
                    $errors[] = 'DISTANCE_VERSION_METADATA_MISMATCH';
                }
                if (($payload['version_code'] ?? '') !== ''
                    && DB::table('distance_reference_versions')->where('code', $payload['version_code'])->exists()) {
                    $errors[] = 'DISTANCE_VERSION_CODE_EXISTS';
                }
            }
            foreach ($errors as $error) {
                $errorCodes[$error] = ($errorCodes[$error] ?? 0) + 1;
            }
            $validated[] = ['row_number' => $offset + 2, 'payload' => $payload, 'errors' => array_values(array_unique($errors))];
        }
        $invalid = count(array_filter($validated, fn ($row): bool => $row['errors'] !== []));

        return [$validated, [
            'row_count' => count($validated),
            'valid_row_count' => count($validated) - $invalid,
            'invalid_row_count' => $invalid,
            'error_code_counts' => $errorCodes,
            'validated_at' => now()->toISOString(),
        ]];
    }

    /** @param array<string, string> $payload
     * @param  list<string>  $errors
     */
    private function validatePlaceRow(array $payload, string $organizationId, array &$errors): void
    {
        if (($payload['code'] ?? '') !== '' && DB::table('places')->where('code', $payload['code'])->exists()) {
            $errors[] = 'PLACE_CODE_EXISTS';
        }
        if (($payload['category_code'] ?? '') !== '' && ! DB::table('place_categories')->where('code', $payload['category_code'])->where('status', 'active')->exists()) {
            $errors[] = 'PLACE_CATEGORY_UNKNOWN';
        }
        $latitude = $payload['latitude'] ?? '';
        $longitude = $payload['longitude'] ?? '';
        if (($latitude === '') xor ($longitude === '')) {
            $errors[] = 'COORDINATE_PAIR_REQUIRED';
        }
        if ($latitude !== '' && (! is_numeric($latitude) || (float) $latitude < -90 || (float) $latitude > 90)) {
            $errors[] = 'LATITUDE_OUT_OF_RANGE';
        }
        if ($longitude !== '' && (! is_numeric($longitude) || (float) $longitude < -180 || (float) $longitude > 180)) {
            $errors[] = 'LONGITUDE_OUT_OF_RANGE';
        }
        if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
            $errors[] = 'ORGANIZATION_UNKNOWN';
        }
    }

    /** @param array<string, string> $payload
     * @param  list<string>  $errors
     */
    private function validateRouteDistanceRow(array $payload, string $organizationId, array &$errors): void
    {
        if (($payload['origin_code'] ?? '') === ($payload['destination_code'] ?? '')) {
            $errors[] = 'ENDPOINTS_IDENTICAL';
        }
        foreach (['origin_code', 'destination_code'] as $field) {
            if (($payload[$field] ?? '') !== '' && ! DB::table('places')->where('code', $payload[$field])
                ->where('owning_organization_id', $organizationId)->where('status', 'active')->exists()) {
                $errors[] = strtoupper($field).'_UNKNOWN';
            }
        }
        if (($payload['distance_km'] ?? '') !== '' && (! is_numeric($payload['distance_km']) || (float) $payload['distance_km'] <= 0)) {
            $errors[] = 'DISTANCE_INVALID';
        }
        if (isset($payload['duration_minutes']) && $payload['duration_minutes'] !== ''
            && (! ctype_digit($payload['duration_minutes']) || (int) $payload['duration_minutes'] < 1)) {
            $errors[] = 'DURATION_INVALID';
        }
        if (isset($payload['directional']) && ! in_array(strtolower($payload['directional']), ['1', '0', 'true', 'false', 'yes', 'no'], true)) {
            $errors[] = 'DIRECTIONAL_INVALID';
        }
    }

    /** @param Collection<int, stdClass> $rows */
    private function applyPlaces(stdClass $batch, Collection $rows, User $actor, ?UserSession $session, Request $request): int
    {
        foreach ($rows as $row) {
            $payload = $this->payload($row->normalized_payload);
            $categoryId = DB::table('place_categories')->where('code', $payload['category_code'])->value('id');
            $place = $this->registry->createPlace([
                'code' => $payload['code'],
                'name' => ['en' => $payload['name_en'], 'om' => $payload['name_om'], 'am' => $payload['name_am']],
                'place_category_id' => $categoryId,
                'owning_organization_id' => $batch->organization_id,
                'administrative_organization_id' => $batch->organization_id,
                'latitude' => $payload['latitude'] !== '' ? $payload['latitude'] : null,
                'longitude' => $payload['longitude'] !== '' ? $payload['longitude'] : null,
                'timezone' => 'Africa/Addis_Ababa',
                'effective_from' => $payload['effective_from'],
                'status' => 'draft',
                'organization_mappings' => [[
                    'organization_id' => $batch->organization_id,
                    'mapping_role' => 'owner',
                    'is_primary' => true,
                ]],
            ], $actor, $session, $request);
            $this->markApplied($row->id, 'place', $place->id);
        }

        return $rows->count();
    }

    /** @param Collection<int, stdClass> $rows */
    private function applyDistanceMatrix(stdClass $batch, Collection $rows, User $actor, ?UserSession $session, Request $request): int
    {
        $payloads = $rows->map(fn (stdClass $row): array => $this->payload($row->normalized_payload));
        $first = $payloads->first();
        $reference = $this->registry->createDistanceReference([
            'organization_id' => $batch->organization_id,
            'code' => $first['version_code'],
            'name' => $first['version_name'],
            'source_type' => 'bureau_matrix',
            'source_reference' => $first['source_reference'],
            'source_document_id' => $batch->document_id,
            'effective_from' => $first['effective_from'],
            'status' => 'draft',
            'legs' => $payloads->map(fn ($payload) => [
                'origin_place_id' => DB::table('places')->where('code', $payload['origin_code'])->value('id'),
                'destination_place_id' => DB::table('places')->where('code', $payload['destination_code'])->value('id'),
                'route_label' => $payload['route_label'] ?? null,
                'distance_km' => $payload['distance_km'],
                'estimated_duration_minutes' => ($payload['duration_minutes'] ?? '') !== '' ? (int) $payload['duration_minutes'] : null,
                'directional' => $this->boolean($payload['directional']),
                'tolerance_percent' => ($payload['tolerance_percent'] ?? '') !== '' ? $payload['tolerance_percent'] : null,
            ])->all(),
        ], $actor, $session, $request);
        foreach ($rows as $row) {
            $this->markApplied($row->id, 'distance_reference_version', $reference->id);
        }

        return $rows->count();
    }

    /** @param Collection<int, stdClass> $rows */
    private function applyRoutes(stdClass $batch, Collection $rows, User $actor, ?UserSession $session, Request $request): int
    {
        foreach ($rows as $row) {
            $payload = $this->payload($row->normalized_payload);
            $origin = DB::table('places')->where('code', $payload['origin_code'])->value('id');
            $destination = DB::table('places')->where('code', $payload['destination_code'])->value('id');
            $route = $this->registry->createRoute([
                'organization_id' => $batch->organization_id,
                'code' => $payload['code'],
                'name' => ['en' => $payload['name_en'], 'om' => $payload['name_om'], 'am' => $payload['name_am']],
                'origin_place_id' => $origin,
                'destination_place_id' => $destination,
                'directional' => $this->boolean($payload['directional'] ?? 'true'),
                'version' => [
                    'alternative_label' => $payload['route_label'] ?? 'Primary',
                    'preferred' => true,
                    'estimated_distance_km' => $payload['distance_km'],
                    'estimated_duration_minutes' => (int) $payload['duration_minutes'],
                    'source_type' => 'bureau_matrix',
                    'source_reference' => $payload['source_reference'],
                    'source_document_id' => $batch->document_id,
                    'effective_from' => $payload['effective_from'],
                    'segments' => [[
                        'sequence' => 1,
                        'origin_place_id' => $origin,
                        'destination_place_id' => $destination,
                        'distance_km' => $payload['distance_km'],
                        'duration_minutes' => (int) $payload['duration_minutes'],
                        'mandatory_stop' => $this->boolean($payload['mandatory_stop'] ?? 'false'),
                    ]],
                ],
            ], $actor, $session, $request);
            $this->markApplied($row->id, 'route_master', $route->id);
        }

        return $rows->count();
    }

    private function markApplied(string $rowId, string $entityType, string $entityId): void
    {
        DB::table('route_distance_import_rows')->where('id', $rowId)->update([
            'status' => 'applied',
            'applied_entity_type' => $entityType,
            'applied_entity_id' => $entityId,
            'updated_at' => now(),
        ]);
    }

    private function boolean(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    /** @return array<string, string> */
    private function payload(string $json): array
    {
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new BusinessRuleException('GEOGRAPHY_IMPORT_EVIDENCE_INVALID', 'A normalized import row is invalid.');
        }

        return array_map(static fn (mixed $value): string => (string) $value, $payload);
    }

    /** @param array<string, mixed> $after */
    private function record(
        string $event,
        string $action,
        string $id,
        string $organizationId,
        User $actor,
        ?UserSession $session,
        Request $request,
        array $after,
    ): void {
        $this->audit->record(
            $event.'.succeeded', 'geography', $action, 'succeeded', 'route_distance_import', $id,
            $organizationId, $actor, $session, after: $after, request: $request,
        );
        $this->outbox->enqueue(
            $event, 'route_distance_import', $id, ['id' => $id, 'organization_id' => $organizationId],
            $event.':'.$id, $organizationId,
            $request->attributes->get('correlation_id'), $request->attributes->get('causation_id'),
        );
    }
}
