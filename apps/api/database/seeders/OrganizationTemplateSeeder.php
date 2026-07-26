<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::parse('2026-07-25T00:00:00Z');
        $types = [
            ['REGION', 10, true],
            ['BUREAU', 20, false],
            ['ZONE', 30, false],
            ['WOREDA', 40, false],
            ['DEPARTMENT', 50, false],
            ['UNIT', 60, false],
        ];

        foreach ($types as [$code, $sortOrder, $mayBeRoot]) {
            $identity = ['code' => $code, 'effective_from' => $now];
            $values = [
                'name_key' => 'organization.type.'.strtolower($code),
                'translations' => json_encode([
                    'en' => $code,
                    'om' => '[review: '.$code.']',
                    'am' => '[review: '.$code.']',
                ], JSON_THROW_ON_ERROR),
                'description' => 'Inactive configurable starter template',
                'sort_order' => $sortOrder,
                'may_be_root' => $mayBeRoot,
                'status' => 'inactive',
                'configuration_status' => 'template',
                'record_version' => 1,
                'updated_at' => $now,
            ];
            DB::table('organization_types')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                ...$identity,
                ...$values,
                'created_at' => $now,
            ]);
        }

        $ids = DB::table('organization_types')->where('effective_from', $now)->pluck('id', 'code');
        foreach ([
            ['REGION', 'BUREAU'],
            ['REGION', 'ZONE'],
            ['BUREAU', 'DEPARTMENT'],
            ['BUREAU', 'UNIT'],
            ['ZONE', 'WOREDA'],
            ['ZONE', 'DEPARTMENT'],
            ['WOREDA', 'DEPARTMENT'],
            ['WOREDA', 'UNIT'],
            ['DEPARTMENT', 'UNIT'],
        ] as [$parent, $child]) {
            $identity = [
                'parent_type_id' => $ids[$parent],
                'child_type_id' => $ids[$child],
                'effective_from' => $now,
            ];
            DB::table('organization_type_rules')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                ...$identity,
                'status' => 'inactive',
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (['ADMINISTRATIVE_MANAGER', 'FLEET_MANAGER', 'FINANCE_MANAGER', 'MAINTENANCE_MANAGER', 'INVENTORY_MANAGER'] as $code) {
            DB::table('organization_manager_responsibilities')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'code' => $code,
                'name_key' => 'organization.manager.'.strtolower($code),
                'status' => 'inactive',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
