<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IdentityPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['identity.user.view', 'identity', ['current_node', 'node_and_descendants'], false, false, false],
            ['identity.user.manage', 'identity', ['current_node', 'node_and_descendants'], false, true, true],
            ['identity.role.view', 'identity', ['current_node', 'node_and_descendants'], false, false, false],
            ['identity.role.manage', 'identity', ['current_node', 'node_and_descendants'], false, true, true],
            ['identity.role_assignment.view', 'identity', ['current_node', 'node_and_descendants', 'selected_child'], false, false, false],
            ['identity.role_assignment.request', 'identity', ['current_node', 'node_and_descendants', 'selected_child'], false, true, true],
            ['identity.role_assignment.approve', 'identity', ['current_node', 'node_and_descendants', 'selected_child'], false, true, true],
            ['identity.delegation.view', 'identity', ['current_node', 'node_and_descendants', 'selected_child'], true, false, false],
            ['identity.delegation.request', 'identity', ['current_node', 'node_and_descendants', 'selected_child', 'explicit_record'], true, true, true],
            ['identity.delegation.approve', 'identity', ['current_node', 'node_and_descendants', 'selected_child'], false, true, true],
            ['identity.access_review.manage', 'identity', ['current_node', 'node_and_descendants'], false, true, true],
            ['identity.audit.view', 'identity', ['current_node', 'node_and_descendants'], false, true, false],
            ['identity.break_glass.request', 'identity', ['current_node', 'node_and_descendants'], false, true, false],
            ['identity.break_glass.review', 'identity', ['current_node', 'node_and_descendants'], false, true, true],
        ];

        foreach ($definitions as [$code, $domain, $scopeModes, $delegable, $requiresMfa, $makerChecker]) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::ulid(),
                'code' => $code,
                'domain' => $domain,
                'description' => str_replace('.', ' ', ucfirst($code)),
                'allowed_scope_modes' => json_encode($scopeModes, JSON_THROW_ON_ERROR),
                'resource_types' => null,
                'delegable' => $delegable,
                'requires_mfa' => $requiresMfa,
                'requires_step_up' => $requiresMfa,
                'maker_checker_required' => $makerChecker,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
