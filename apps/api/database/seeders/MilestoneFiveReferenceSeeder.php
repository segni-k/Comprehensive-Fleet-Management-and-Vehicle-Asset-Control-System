<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MilestoneFiveReferenceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['VEHICLE_PHOTOGRAPH', ['en' => 'Vehicle photograph', 'om' => 'Suuraa konkolaataa', 'am' => 'የተሽከርካሪ ፎቶ'], ['image/jpeg', 'image/png']],
            ['VEHICLE_COMPLIANCE', ['en' => 'Vehicle compliance document', 'om' => 'Sanada ulaagaa konkolaataa', 'am' => 'የተሽከርካሪ ተገዢነት ሰነድ'], ['application/pdf', 'image/jpeg', 'image/png']],
            ['VEHICLE_ATTACHMENT', ['en' => 'Vehicle supporting attachment', 'om' => 'Faayila deeggarsa konkolaataa', 'am' => 'የተሽከርካሪ ደጋፊ አባሪ'], ['application/pdf', 'image/jpeg', 'image/png']],
            ['DRIVER_LICENCE', ['en' => 'Driver licence', 'om' => 'Hayyama konkolaachisummaa', 'am' => 'የመንጃ ፈቃድ'], ['application/pdf', 'image/jpeg', 'image/png']],
            ['DRIVER_DOCUMENT', ['en' => 'Driver supporting document', 'om' => 'Sanada deeggarsa konkolaachisaa', 'am' => 'የአሽከርካሪ ደጋፊ ሰነድ'], ['application/pdf', 'image/jpeg', 'image/png']],
        ] as [$code, $name, $mimeTypes]) {
            $id = DB::table('document_types')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('document_types')->upsert([[
                'id' => $id,
                'code' => $code,
                'name' => json_encode($name, JSON_THROW_ON_ERROR),
                'allowed_mime_types' => json_encode($mimeTypes, JSON_THROW_ON_ERROR),
                'maximum_bytes' => 20_971_520,
                'malware_scan_required' => true,
                'retention_class' => 'fleet_operational_record',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], ['name', 'allowed_mime_types', 'maximum_bytes', 'malware_scan_required', 'retention_class', 'status', 'updated_at']);
        }

        $categories = [
            'PASSENGER' => ['en' => 'Passenger', 'om' => 'Imaltootaa', 'am' => 'መንገደኛ'],
            'UTILITY' => ['en' => 'Utility', 'om' => 'Tajaajila hojii', 'am' => 'የሥራ አገልግሎት'],
            'HEAVY' => ['en' => 'Heavy duty', 'om' => 'Hojii ulfaataa', 'am' => 'ከባድ ተሽከርካሪ'],
            'SPECIAL' => ['en' => 'Special purpose', 'om' => 'Kaayyoo addaa', 'am' => 'ልዩ ዓላማ'],
        ];
        foreach ($categories as $code => $name) {
            $id = DB::table('vehicle_categories')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('vehicle_categories')->upsert([[
                'id' => $id,
                'code' => $code,
                'name' => json_encode($name, JSON_THROW_ON_ERROR),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], ['name', 'status', 'updated_at']);
        }

        $licenceClasses = [
            'A' => ['en' => 'Class A', 'om' => 'Gosa A', 'am' => 'ደረጃ A'],
            'B' => ['en' => 'Class B', 'om' => 'Gosa B', 'am' => 'ደረጃ B'],
            'C' => ['en' => 'Class C', 'om' => 'Gosa C', 'am' => 'ደረጃ C'],
            'D' => ['en' => 'Class D', 'om' => 'Gosa D', 'am' => 'ደረጃ D'],
        ];
        foreach ($licenceClasses as $code => $name) {
            $id = DB::table('driver_licence_classes')->where('code', $code)->value('id') ?? (string) Str::ulid();
            DB::table('driver_licence_classes')->upsert([[
                'id' => $id,
                'code' => $code,
                'name' => json_encode($name, JSON_THROW_ON_ERROR),
                'status' => 'active',
                'effective_from' => now()->startOfDay(),
                'effective_to' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['id'], ['name', 'status', 'effective_from', 'effective_to', 'updated_at']);
        }
    }
}
