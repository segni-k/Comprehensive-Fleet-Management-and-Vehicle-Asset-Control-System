<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MilestoneSixReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['REGION', 'administrative', true, false, ['en' => 'Region', 'om' => 'Naannoo', 'am' => 'ክልል']],
            ['ADMINISTRATIVE_ZONE', 'administrative', true, false, ['en' => 'Administrative zone', 'om' => 'Godina bulchiinsaa', 'am' => 'አስተዳደራዊ ዞን']],
            ['WOREDA', 'administrative', true, false, ['en' => 'Woreda', 'om' => 'Aanaa', 'am' => 'ወረዳ']],
            ['CITY', 'administrative', true, true, ['en' => 'City', 'om' => 'Magaalaa', 'am' => 'ከተማ']],
            ['TOWN', 'administrative', true, true, ['en' => 'Town', 'om' => 'Magaalaa xiqqaa', 'am' => 'ንዑስ ከተማ']],
            ['VILLAGE', 'administrative', false, true, ['en' => 'Village', 'om' => 'Ganda', 'am' => 'መንደር']],
            ['DEPOT', 'facility', false, true, ['en' => 'Depot', 'om' => 'Buufata kuusaa', 'am' => 'ዴፖ']],
            ['GARAGE', 'facility', false, true, ['en' => 'Garage', 'om' => 'Gaaraajii', 'am' => 'ጋራዥ']],
            ['FUEL_STATION', 'facility', false, true, ['en' => 'Fuel station', 'om' => 'Buufata bobaʼaa', 'am' => 'ነዳጅ ማደያ']],
            ['WORKSHOP', 'facility', false, true, ['en' => 'Workshop', 'om' => 'Workshoppii', 'am' => 'ወርክሾፕ']],
            ['PARKING_FACILITY', 'facility', false, true, ['en' => 'Parking facility', 'om' => 'Iddoo dhaabbii', 'am' => 'የመኪና ማቆሚያ']],
            ['WAREHOUSE', 'facility', false, true, ['en' => 'Warehouse', 'om' => 'Mana kuusaa', 'am' => 'መጋዘን']],
            ['GOVERNMENT_COMPOUND', 'facility', true, true, ['en' => 'Government compound', 'om' => 'Mooraa mootummaa', 'am' => 'የመንግስት ግቢ']],
            ['OFFICE', 'facility', false, true, ['en' => 'Office', 'om' => 'Waajjira', 'am' => 'ቢሮ']],
            ['SERVICE_CENTER', 'facility', false, true, ['en' => 'Service center', 'om' => 'Giddugala tajaajilaa', 'am' => 'አገልግሎት ማዕከል']],
            ['OPERATIONAL_LOCATION', 'operational', true, true, ['en' => 'Operational location', 'om' => 'Iddoo hojii', 'am' => 'የሥራ ቦታ']],
        ];
        foreach ($categories as [$code, $classification, $allowsChildren, $requiresCoordinates, $name]) {
            $id = DB::table('place_categories')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('place_categories')->upsert([[
                'id' => $id,
                'code' => $code,
                'name' => json_encode($name, JSON_THROW_ON_ERROR),
                'classification' => $classification,
                'allows_children' => $allowsChildren,
                'requires_coordinates' => $requiresCoordinates,
                'system_defined' => true,
                'status' => 'active',
                'record_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], ['name', 'classification', 'allows_children', 'requires_coordinates', 'status', 'updated_at']);
        }

        foreach ([
            ['FEDERAL_TRUNK', 10, ['en' => 'Federal trunk road', 'om' => 'Daandii federaalaa', 'am' => 'ፌዴራል ዋና መንገድ']],
            ['REGIONAL', 20, ['en' => 'Regional road', 'om' => 'Daandii naannoo', 'am' => 'ክልላዊ መንገድ']],
            ['RURAL_ACCESS', 30, ['en' => 'Rural access road', 'om' => 'Daandii baadiyyaa', 'am' => 'የገጠር መዳረሻ መንገድ']],
            ['URBAN', 40, ['en' => 'Urban road', 'om' => 'Daandii magaalaa', 'am' => 'የከተማ መንገድ']],
            ['UNCLASSIFIED', 100, ['en' => 'Unclassified road', 'om' => 'Daandii hin ramadamne', 'am' => 'ያልተመደበ መንገድ']],
        ] as [$code, $priority, $name]) {
            $id = DB::table('road_classifications')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('road_classifications')->upsert([[
                'id' => $id, 'code' => $code, 'name' => json_encode($name, JSON_THROW_ON_ERROR),
                'priority' => $priority, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]], ['id'], ['name', 'priority', 'status', 'updated_at']);
        }

        foreach ([
            ['GOOD', 0, ['en' => 'Good', 'om' => 'Gaarii', 'am' => 'ጥሩ']],
            ['FAIR', 1, ['en' => 'Fair', 'om' => 'Giddu galeessa', 'am' => 'መካከለኛ']],
            ['POOR', 2, ['en' => 'Poor', 'om' => 'Dadhabaa', 'am' => 'ደካማ']],
            ['SEASONAL', 3, ['en' => 'Seasonal', 'om' => 'Yeroo murtaaʼaa', 'am' => 'ወቅታዊ']],
            ['CLOSED', 4, ['en' => 'Closed', 'om' => 'Cufame', 'am' => 'ዝግ']],
        ] as [$code, $severity, $name]) {
            $id = DB::table('road_conditions')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('road_conditions')->upsert([[
                'id' => $id, 'code' => $code, 'name' => json_encode($name, JSON_THROW_ON_ERROR),
                'severity' => $severity, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]], ['id'], ['name', 'severity', 'status', 'updated_at']);
        }

        $documentTypeId = DB::table('document_types')->where('code', 'GEOGRAPHY_IMPORT')->value('id') ?? (string) Str::ulid();
        DB::table('document_types')->upsert([[
            'id' => $documentTypeId,
            'code' => 'GEOGRAPHY_IMPORT',
            'name' => json_encode([
                'en' => 'Geography master-data import',
                'om' => 'Galchii deetaa buʼuuraa teessuma lafaa',
                'am' => 'የጂኦግራፊ ዋና ውሂብ ማስገቢያ',
            ], JSON_THROW_ON_ERROR),
            'allowed_mime_types' => json_encode([
                'text/csv',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], JSON_THROW_ON_ERROR),
            'maximum_bytes' => 20_971_520,
            'malware_scan_required' => true,
            'retention_class' => 'geography_master_data',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['id'], ['name', 'allowed_mime_types', 'maximum_bytes', 'status', 'updated_at']);
    }
}
