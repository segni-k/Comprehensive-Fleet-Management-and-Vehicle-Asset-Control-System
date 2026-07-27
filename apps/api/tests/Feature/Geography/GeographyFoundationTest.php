<?php

namespace Tests\Feature\Geography;

use App\Documents\Models\Document;
use App\Documents\Models\DocumentType;
use App\Documents\Models\DocumentVersion;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Geography\Models\PlaceCategory;
use App\Geography\Services\GeographyImportService;
use App\Geography\Services\GeographyRegistryService;
use App\Identity\Models\User;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

final class GeographyFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_place_master_preserves_hierarchy_policy_history_audit_and_outbox(): void
    {
        [$organization, $actor] = $this->context();
        $service = app(GeographyRegistryService::class);
        $request = Request::create('/api/v1/places', 'POST');
        $category = $this->category('CITY', true);
        $parentCategory = $this->category('REGION', false);
        $parent = $service->createPlace($this->placeData($organization, $parentCategory, 'OROMIA', null, null), $actor, null, $request);
        $place = $service->createPlace($this->placeData($organization, $category, 'FINFINNE', $parent->id, [
            'allowed_radius_m' => 250,
            'maximum_accuracy_m' => 100,
            'maximum_reading_age_seconds' => 120,
            'verifier_required' => false,
            'qr_required' => false,
            'photo_required' => false,
            'offline_allowed' => true,
            'maximum_offline_delay_minutes' => 1440,
            'effective_from' => now()->subMinute(),
        ]), $actor, null, $request);

        $this->assertSame('9.0107934', $place->latitude);
        $this->assertDatabaseHas('place_hierarchy_edges', ['parent_place_id' => $parent->id, 'child_place_id' => $place->id]);
        $this->assertDatabaseHas('place_addresses', ['place_id' => $place->id, 'country_code' => 'ET']);
        $this->assertDatabaseHas('place_organization_mappings', ['place_id' => $place->id, 'organization_id' => $organization->id]);
        $this->assertDatabaseHas('location_policy_versions', ['place_id' => $place->id, 'version' => 1, 'status' => 'draft']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'place.created.succeeded', 'subject_id' => $place->id]);
        $this->assertDatabaseHas('outbox_messages', ['topic' => 'place.created', 'aggregate_id' => $place->id]);

        $policy = $place->policies()->firstOrFail();
        $policy = $service->approveLocationPolicy($policy, 1, $actor, null, $request);
        $this->assertSame('approved', $policy->status);
        $this->assertNotNull($policy->approved_at);

        $place = $service->transitionPlace($place, 'active', 1, 'Approved geographic master.', $actor, null, $request);
        $this->assertSame('active', $place->status);
        $this->assertDatabaseCount('place_history', 3);

        try {
            $service->updatePlace($place, ['record_version' => 1, 'change_reason' => 'Stale edit.'], $actor, null, $request);
            $this->fail('A stale place update should be rejected.');
        } catch (ConflictException $exception) {
            $this->assertSame('GEOGRAPHY_RECORD_VERSION_CONFLICT', $exception->errorCode);
        }

        try {
            DB::transaction(fn () => $service->attachParent($parent, [
                'parent_place_id' => $place->id,
                'effective_from' => now(),
                'reason' => 'Invalid cycle.',
            ], $actor));
            $this->fail('A cyclic place hierarchy should be rejected.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('PLACE_HIERARCHY_CYCLE', $exception->errorCode);
        }
    }

    public function test_route_versions_require_continuous_segments_and_exact_totals_before_approval(): void
    {
        [$organization, $actor] = $this->context();
        $service = app(GeographyRegistryService::class);
        $request = Request::create('/api/v1/routes', 'POST');
        $category = $this->category('OPERATIONAL', true);
        $origin = $service->createPlace($this->placeData($organization, $category, 'ORIGIN'), $actor, null, $request);
        $stop = $service->createPlace($this->placeData($organization, $category, 'STOP'), $actor, null, $request);
        $destination = $service->createPlace($this->placeData($organization, $category, 'DESTINATION'), $actor, null, $request);
        $routeData = [
            'organization_id' => $organization->id,
            'code' => 'RTE-001',
            'name' => ['en' => 'Controlled service corridor', 'om' => 'Karaa tajaajilaa', 'am' => 'የአገልግሎት መስመር'],
            'origin_place_id' => $origin->id,
            'destination_place_id' => $destination->id,
            'directional' => true,
            'version' => [
                'alternative_label' => 'Primary corridor',
                'preferred' => true,
                'estimated_distance_km' => 42.5,
                'estimated_duration_minutes' => 75,
                'source_type' => 'bureau_matrix',
                'source_reference' => 'Approved test matrix',
                'effective_from' => now()->subMinute(),
                'segments' => [
                    ['sequence' => 1, 'origin_place_id' => $origin->id, 'destination_place_id' => $stop->id, 'distance_km' => 20, 'duration_minutes' => 30, 'mandatory_stop' => true],
                    ['sequence' => 2, 'origin_place_id' => $stop->id, 'destination_place_id' => $destination->id, 'distance_km' => 22.5, 'duration_minutes' => 45, 'mandatory_stop' => false],
                ],
            ],
        ];
        $route = $service->createRoute($routeData, $actor, null, $request);
        $this->assertDatabaseCount('route_segments', 2);
        $version = $route->versions->first();
        $approved = $service->approveRouteVersion($version, 1, $actor, null, $request);
        $this->assertSame('approved', $approved->status);
        $this->assertSame('active', $route->refresh()->status);

        [$otherOrganization] = $this->context();
        $otherDestination = $service->createPlace(
            $this->placeData($otherOrganization, $category, 'OUT_OF_SCOPE'),
            $actor,
            null,
            $request,
        );
        $crossScope = $routeData;
        $crossScope['code'] = 'RTE-CROSS-SCOPE';
        $crossScope['destination_place_id'] = $otherDestination->id;
        try {
            $service->createRoute($crossScope, $actor, null, $request);
            $this->fail('A route must not link an unmapped place from another organization.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('PLACE_ORGANIZATION_SCOPE_INVALID', $exception->errorCode);
        }

        $invalid = $routeData['version'];
        $invalid['alternative_label'] = 'Broken corridor';
        $invalid['estimated_distance_km'] = 41;
        $this->expectException(BusinessRuleException::class);
        $service->createRouteVersion($route, $invalid, $actor, null, $request);
    }

    public function test_distance_matrix_enforces_unique_legs_and_non_overlapping_approved_versions(): void
    {
        [$organization, $actor] = $this->context();
        $service = app(GeographyRegistryService::class);
        $request = Request::create('/api/v1/distance-references', 'POST');
        $category = $this->category('CITY_DISTANCE', true);
        $origin = $service->createPlace($this->placeData($organization, $category, 'A'), $actor, null, $request);
        $destination = $service->createPlace($this->placeData($organization, $category, 'B'), $actor, null, $request);
        $data = [
            'organization_id' => $organization->id,
            'code' => 'DIST-2026-A',
            'name' => 'Approved city distance matrix',
            'source_type' => 'bureau_matrix',
            'source_reference' => 'Controlled matrix A',
            'effective_from' => now()->subMinute(),
            'status' => 'draft',
            'legs' => [[
                'origin_place_id' => $origin->id,
                'destination_place_id' => $destination->id,
                'route_label' => 'Primary',
                'distance_km' => 101.25,
                'estimated_duration_minutes' => 140,
                'directional' => false,
                'tolerance_percent' => 5,
            ]],
        ];
        $reference = $service->createDistanceReference($data, $actor, null, $request);
        $reference = $service->approveDistanceReference($reference, 1, $actor, null, $request);
        $this->assertSame('approved', $reference->status);

        $data['code'] = 'DIST-2026-B';
        $data['source_reference'] = 'Controlled matrix B';
        $overlap = $service->createDistanceReference($data, $actor, null, $request);
        try {
            $service->approveDistanceReference($overlap, 1, $actor, null, $request);
            $this->fail('Overlapping approved matrices should be rejected.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('DISTANCE_REFERENCE_PERIOD_OVERLAP', $exception->errorCode);
        }

        $data['code'] = 'DIST-2026-C';
        $data['legs'][] = $data['legs'][0];
        $this->expectException(BusinessRuleException::class);
        $service->createDistanceReference($data, $actor, null, $request);
    }

    public function test_trusted_import_is_validated_independently_approved_applied_as_draft_and_reversibly_rolled_back(): void
    {
        Storage::fake('local');
        [$organization, $importer] = $this->context();
        $approver = User::factory()->create();
        $category = $this->category('IMPORT_CITY', true);
        $content = implode("\n", [
            'code,name_en,name_om,name_am,category_code,latitude,longitude,effective_from',
            "IMP_CITY,Imported City,Magaalaa Galfame,የገባ ከተማ,{$category->code},9.0200,38.7500,2026-01-01T00:00:00+03:00",
        ]);
        $document = $this->trustedImportDocument($organization, $importer, $content);
        $service = app(GeographyImportService::class);
        $request = Request::create('/api/v1/distance-imports', 'POST');

        $batch = $service->stage($organization->id, 'places', $document, $importer, null, $request);
        $this->assertSame('validated', $batch->status);
        $this->assertSame(1, (int) $batch->valid_row_count);
        $this->assertDatabaseHas('route_distance_import_rows', ['import_id' => $batch->id, 'status' => 'valid']);

        try {
            $service->approve($batch->id, $importer, null, $request);
            $this->fail('An importer must not approve their own batch.');
        } catch (BusinessRuleException $exception) {
            $this->assertSame('GEOGRAPHY_IMPORT_MAKER_CHECKER', $exception->errorCode);
        }

        $batch = $service->approve($batch->id, $approver, null, $request);
        $this->assertSame('approved_applied_draft', $batch->status);
        $this->assertDatabaseHas('places', [
            'owning_organization_id' => $organization->id,
            'code' => 'IMP_CITY',
            'status' => 'draft',
        ]);

        $batch = $service->rollback($batch->id, 'Controlled test rollback.', $approver, null, $request);
        $this->assertSame('rolled_back', $batch->status);
        $this->assertDatabaseHas('places', ['code' => 'IMP_CITY', 'status' => 'inactive']);
        $this->assertDatabaseHas('route_distance_import_rows', ['import_id' => $batch->id, 'status' => 'rolled_back']);
        $this->assertDatabaseHas('audit_events', ['event_type' => 'geography.import.rolled_back.succeeded', 'subject_id' => $batch->id]);

        $xlsx = $this->xlsx([
            ['code', 'name_en', 'name_om', 'name_am', 'category_code', 'latitude', 'longitude', 'effective_from'],
            ['IMP_XLSX', 'XLSX City', 'Magaalaa XLSX', 'የXLSX ከተማ', $category->code, '9.0300', '38.7600', '2026-01-01T00:00:00+03:00'],
        ]);
        $xlsxDocument = $this->trustedImportDocument($organization, $importer, $xlsx, 'geography-places.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $xlsxBatch = $service->stage($organization->id, 'places', $xlsxDocument, $importer, null, $request);
        $this->assertSame('validated', $xlsxBatch->status);
        $this->assertSame(1, (int) $xlsxBatch->valid_row_count);
    }

    /** @return array{Organization, User} */
    private function context(): array
    {
        $type = OrganizationType::query()->create([
            'code' => 'GEO_'.Str::random(6),
            'name_key' => 'test.geography.organization',
            'translations' => ['en' => 'Geography test organization'],
            'description' => 'Test-only organization type',
            'may_be_root' => true,
            'status' => 'active',
            'configuration_status' => 'approved',
            'effective_from' => now()->subDay(),
        ]);
        $organization = Organization::query()->create([
            'type_id' => $type->id,
            'code' => 'ORG_GEO_'.Str::random(5),
            'name' => ['en' => 'Geography test organization'],
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);

        return [$organization, User::factory()->create()];
    }

    private function category(string $code, bool $requiresCoordinates): PlaceCategory
    {
        return PlaceCategory::query()->create([
            'code' => $code.'_'.Str::random(5),
            'name' => ['en' => $code, 'om' => $code, 'am' => $code],
            'classification' => 'custom',
            'allows_children' => true,
            'requires_coordinates' => $requiresCoordinates,
            'system_defined' => false,
            'status' => 'active',
        ]);
    }

    private function trustedImportDocument(
        Organization $organization,
        User $actor,
        string $content,
        string $filename = 'geography-places.csv',
        string $mediaType = 'text/csv',
    ): Document {
        $type = DocumentType::query()->create([
            'code' => 'GEO_IMPORT_'.Str::random(6),
            'name' => ['en' => 'Geography import'],
            'allowed_mime_types' => [$mediaType],
            'maximum_bytes' => 1_000_000,
            'malware_scan_required' => true,
            'retention_class' => 'operational_master',
            'status' => 'active',
        ]);
        $document = Document::query()->create([
            'document_type_id' => $type->id,
            'organization_id' => $organization->id,
            'owner_type' => 'organization',
            'owner_id' => $organization->id,
            'category' => 'geography_import',
            'created_by' => $actor->id,
            'status' => 'trusted',
        ]);
        $storageKey = "tests/geography/{$document->id}/{$filename}";
        Storage::disk('local')->put($storageKey, $content);
        $version = DocumentVersion::query()->create([
            'document_id' => $document->id,
            'version_number' => 1,
            'storage_disk' => 'local',
            'storage_key' => $storageKey,
            'original_filename' => $filename,
            'media_type' => $mediaType,
            'size_bytes' => strlen($content),
            'checksum' => hash('sha256', $content),
            'uploaded_by' => $actor->id,
            'scan_status' => 'clean',
            'trust_status' => 'trusted',
            'trusted_at' => now(),
            'created_at' => now(),
        ]);
        $document->update(['current_version_id' => $version->id]);

        return $document->refresh();
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'geography-test-');
        if ($temporary === false) {
            $this->fail('Unable to prepare the XLSX test fixture.');
        }
        $archive = new ZipArchive;
        if ($archive->open($temporary, ZipArchive::OVERWRITE) !== true) {
            $this->fail('Unable to open the XLSX test fixture.');
        }
        $sheetRows = [];
        foreach ($rows as $rowNumber => $values) {
            $cells = [];
            foreach ($values as $column => $value) {
                $reference = chr(65 + $column).($rowNumber + 1);
                $cells[] = sprintf(
                    '<c r="%s" t="inlineStr"><is><t>%s</t></is></c>',
                    $reference,
                    htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                );
            }
            $sheetRows[] = '<row r="'.($rowNumber + 1).'">'.implode('', $cells).'</row>';
        }
        $archive->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $sheetRows).'</sheetData></worksheet>',
        );
        $archive->close();
        $content = file_get_contents($temporary);
        @unlink($temporary);
        if (! is_string($content)) {
            $this->fail('Unable to read the XLSX test fixture.');
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>|null  $policy
     * @return array<string, mixed>
     */
    private function placeData(
        Organization $organization,
        PlaceCategory $category,
        string $code,
        ?string $parentId = null,
        ?array $policy = null,
    ): array {
        $data = [
            'code' => $code.'_'.Str::random(4),
            'name' => ['en' => $code, 'om' => $code.' OM', 'am' => $code.' AM'],
            'place_category_id' => $category->id,
            'owning_organization_id' => $organization->id,
            'administrative_organization_id' => $organization->id,
            'latitude' => '9.0107934',
            'longitude' => '38.7612525',
            'timezone' => 'Africa/Addis_Ababa',
            'effective_from' => now()->subMinute(),
            'status' => 'draft',
            'address' => ['address_type' => 'physical', 'country_code' => 'ET', 'region' => 'Oromia'],
            'organization_mappings' => [[
                'organization_id' => $organization->id,
                'mapping_role' => 'owner',
                'is_primary' => true,
            ]],
        ];
        if ($parentId !== null) {
            $data['parent_place_id'] = $parentId;
        }
        if ($policy !== null) {
            $data['location_policy'] = $policy;
        }

        return $data;
    }
}
