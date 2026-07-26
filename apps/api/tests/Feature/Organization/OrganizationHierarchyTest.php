<?php

namespace Tests\Feature\Organization;

use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationHierarchyEdge;
use App\Organization\Models\OrganizationSettingDefinition;
use App\Organization\Models\OrganizationSettingValue;
use App\Organization\Models\OrganizationType;
use App\Organization\Models\OrganizationTypeRule;
use App\Organization\Services\HierarchyService;
use App\Organization\Services\OrganizationStatusTransitionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrganizationHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationType $parentType;

    private OrganizationType $childType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parentType = $this->type('TEST_PARENT', true);
        $this->childType = $this->type('TEST_CHILD', false);
        OrganizationTypeRule::query()->create([
            'parent_type_id' => $this->parentType->id,
            'child_type_id' => $this->childType->id,
            'status' => 'active',
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_prevents_self_parent_cycles_and_invalid_type_relationships(): void
    {
        $root = $this->organization('ROOT', $this->parentType);
        $child = $this->organization('CHILD', $this->childType);
        $this->edge($root, $child, '2026-01-01 00:00:00');
        $service = app(HierarchyService::class);
        $at = CarbonImmutable::parse('2026-06-01');

        $this->assertContains('SELF_PARENT', $service->validateRelationship($root->id, $root->id, $at)['blockers']);
        $this->assertContains('HIERARCHY_CYCLE', $service->validateRelationship($root->id, $child->id, $at)['blockers']);

        $otherParent = $this->organization('OTHER', $this->childType);
        $this->assertContains('PARENT_CHILD_TYPE_NOT_ALLOWED', $service->validateRelationship($child->id, $otherParent->id, $at)['blockers']);
        $this->assertSame($root->id, $service->parentId($child->id, $at));
        $this->assertSame([$child->id], $service->descendantIds($root->id, $at));
    }

    public function test_reconstructs_historical_hierarchy_without_rewriting_history(): void
    {
        $oldParent = $this->organization('OLD', $this->parentType);
        $newParent = $this->organization('NEW', $this->parentType);
        $child = $this->organization('CHILD', $this->childType);
        $this->edge($oldParent, $child, '2026-01-01 00:00:00', '2026-06-01 00:00:00', 'ended');
        $this->edge($newParent, $child, '2026-06-01 00:00:00');
        $service = app(HierarchyService::class);

        $this->assertSame($oldParent->id, $service->parentId($child->id, CarbonImmutable::parse('2026-05-01')));
        $this->assertSame($newParent->id, $service->parentId($child->id, CarbonImmutable::parse('2026-07-01')));
    }

    public function test_resolves_local_and_inherited_settings_effectively(): void
    {
        $root = $this->organization('ROOT', $this->parentType);
        $child = $this->organization('CHILD', $this->childType);
        $this->edge($root, $child, '2026-01-01 00:00:00');
        $definition = OrganizationSettingDefinition::query()->create([
            'key' => 'test.policy',
            'name_key' => 'settings.test.policy',
            'value_type' => 'string',
            'inheritable' => true,
            'sensitive' => false,
            'status' => 'active',
        ]);
        OrganizationSettingValue::query()->create([
            'organization_id' => $root->id,
            'setting_definition_id' => $definition->id,
            'value' => ['value' => 'parent'],
            'effective_from' => '2026-01-01 00:00:00',
        ]);
        $service = app(HierarchyService::class);
        $at = CarbonImmutable::parse('2026-07-01');

        $inherited = $service->effectiveSetting($child->id, $definition->id, $at);
        $this->assertSame('inherited', $inherited['override_status']);
        $this->assertSame($root->id, $inherited['source_organization_id']);

        $override = OrganizationSettingValue::query()->create([
            'organization_id' => $child->id,
            'setting_definition_id' => $definition->id,
            'value' => ['value' => 'local'],
            'effective_from' => '2026-06-01 00:00:00',
        ]);
        $this->assertSame('local', $service->effectiveSetting($child->id, $definition->id, $at)['override_status']);
        $override->update(['effective_to' => '2026-06-30 00:00:00']);
        $this->assertSame('inherited', $service->effectiveSetting($child->id, $definition->id, $at)['override_status']);
    }

    public function test_preview_reports_scope_warnings_and_audit_evidence(): void
    {
        $oldParent = $this->organization('OLD', $this->parentType);
        $newParent = $this->organization('NEW', $this->parentType);
        $child = $this->organization('CHILD', $this->childType);
        $this->edge($oldParent, $child, '2026-01-01 00:00:00');
        $preview = app(HierarchyService::class)->createPreview(
            $child->id,
            $newParent->id,
            CarbonImmutable::parse('2026-08-01'),
            'Controlled test move',
            'test-requester',
            (string) Str::uuid(),
        );

        $this->assertContains('PERMISSION_EXPANSION_REVIEW_REQUIRED', $preview['warnings']);
        $this->assertContains('PERMISSION_LOSS_REVIEW_REQUIRED', $preview['warnings']);
        $this->assertDatabaseHas('organization_hierarchy_change_history', [
            'event_type' => 'organization.hierarchy.preview.created',
            'subject_id' => $preview['id'],
        ]);
    }

    public function test_unique_codes_and_effective_edge_constraints_are_enforced(): void
    {
        $this->organization('UNIQUE', $this->parentType);
        $this->expectException(QueryException::class);
        $this->organization('UNIQUE', $this->parentType);
    }

    public function test_api_enforces_auth_permission_scope_and_concurrency(): void
    {
        $organization = $this->organization('SECURE', $this->parentType);
        $url = "/api/v1/organizations/{$organization->id}";

        $this->getJson($url)->assertUnauthorized();
        $this->withHeaders($this->headers('organization.node.view'))->getJson($url)->assertForbidden();
        $this->withHeaders($this->headers('organization.node.view', [$organization->id]))->getJson($url)->assertOk();
        $this->withHeaders([...$this->headers('organization.node.update', [$organization->id]), 'If-Match' => '99'])
            ->patchJson($url, ['description' => 'Hidden field attempt', 'status' => 'active'])
            ->assertConflict()
            ->assertJsonPath('code', 'STALE_RECORD_VERSION');
        $this->assertSame('active', $organization->fresh()->status);
    }

    public function test_deactivation_is_blocked_by_active_children(): void
    {
        $root = $this->organization('ROOT', $this->parentType);
        $child = $this->organization('CHILD', $this->childType);
        $this->edge($root, $child, '2026-01-01 00:00:00');

        $this->withHeaders([
            ...$this->headers('organization.node.deactivate', [$root->id]),
            'If-Match' => '1',
            'Idempotency-Key' => 'deactivate-root-test-0001',
        ])->postJson("/api/v1/organizations/{$root->id}/deactivate", [
            'reason' => 'Controlled deactivation test',
            'effective_at' => now()->toISOString(),
        ])->assertOk()->assertJsonPath('data.status', 'blocked')->assertJsonPath('data.blockers.0', 'ACTIVE_CHILDREN');
    }

    public function test_type_updates_create_a_new_effective_dated_version(): void
    {
        $response = $this->withHeaders([
            ...$this->headers('organization.type.update'),
            'If-Match' => '1',
        ])->patchJson("/api/v1/organization-types/{$this->parentType->id}", [
            'description' => 'Superseding fictional test type',
            'effective_from' => '2026-09-01T00:00:00Z',
        ])->assertOk()
            ->assertJsonPath('data.code', 'TEST_PARENT')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.record_version', 1);

        $newId = $response->json('data.id');
        $this->assertNotSame($this->parentType->id, $newId);
        $this->assertSame('superseded', $this->parentType->fresh()->configuration_status);
        $this->assertNotNull($this->parentType->fresh()->effective_to);
        $this->assertDatabaseHas('organization_hierarchy_change_history', [
            'event_type' => 'organization.type.superseded',
            'subject_id' => $newId,
        ]);
    }

    public function test_node_creation_evaluates_parent_child_rule_at_effective_date(): void
    {
        $root = $this->organization('DATE_ROOT', $this->parentType);
        OrganizationTypeRule::query()->update(['effective_to' => '2026-05-01 00:00:00']);

        $this->withHeaders([
            ...$this->headers('organization.node.create', [$root->id]),
            'Idempotency-Key' => 'effective-rule-node-0001',
        ])->postJson('/api/v1/organizations', [
            'type_id' => $this->childType->id,
            'code' => 'OUTSIDE_RULE',
            'name' => ['en' => 'Outside rule', 'om' => 'Outside rule', 'am' => 'Outside rule'],
            'description' => 'Fictional organization outside effective rule window',
            'parent_id' => $root->id,
            'effective_from' => '2026-06-01T00:00:00Z',
        ])->assertUnprocessable()->assertJsonPath('code', 'PARENT_CHILD_TYPE_NOT_ALLOWED');
    }

    public function test_future_activation_is_scheduled_instead_of_applied_early(): void
    {
        $organization = $this->organization('FUTURE_STATUS', $this->parentType);

        $this->withHeaders([
            ...$this->headers('organization.node.deactivate', [$organization->id]),
            'If-Match' => '1',
            'Idempotency-Key' => 'future-deactivation-0001',
        ])->postJson("/api/v1/organizations/{$organization->id}/deactivate", [
            'reason' => 'Future status test',
            'effective_at' => now()->addDay()->toISOString(),
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('active', $organization->fresh()->status);
        $this->assertDatabaseHas('organization_status_transitions', [
            'subject_id' => $organization->id,
            'target_status' => 'inactive',
            'status' => 'scheduled',
        ]);

        DB::table('organization_status_transitions')
            ->where('subject_id', $organization->id)
            ->update(['effective_at' => now()->subSecond()]);
        $this->assertSame(1, app(OrganizationStatusTransitionService::class)->applyDue());
        $this->assertSame('inactive', $organization->fresh()->status);
        $this->assertDatabaseHas('organization_status_transitions', [
            'subject_id' => $organization->id,
            'status' => 'applied',
        ]);
    }

    public function test_move_is_previewed_approved_scheduled_and_applied_with_maker_checker(): void
    {
        $oldParent = $this->organization('OLD', $this->parentType);
        $newParent = $this->organization('NEW', $this->parentType);
        $child = $this->organization('CHILD', $this->childType);
        $this->edge($oldParent, $child, '2026-01-01 00:00:00');
        $scope = [$oldParent->id, $newParent->id, $child->id];
        $previewResponse = $this->withHeaders([
            ...$this->headers('organization.hierarchy.preview', $scope),
            'Idempotency-Key' => 'preview-workflow-test-0001',
        ])->postJson('/api/v1/organization-move-previews', [
            'source_organization_id' => $child->id,
            'proposed_parent_organization_id' => $newParent->id,
            'requested_effective_at' => now()->subMinute()->toISOString(),
            'reason' => 'Controlled workflow test',
        ])->assertCreated()->assertJsonPath('data.blockers', []);

        $moveResponse = $this->withHeaders([
            ...$this->headers('organization.hierarchy.move.request', $scope),
            'Idempotency-Key' => 'move-request-test-0001',
        ])->postJson('/api/v1/organization-moves', [
            'preview_id' => $previewResponse->json('data.id'),
            'preview_version' => 1,
            'requested_effective_at' => now()->subMinute()->toISOString(),
            'reason' => 'Controlled workflow test',
        ])->assertCreated();
        $moveId = $moveResponse->json('data.id');

        $this->withHeaders([
            ...$this->headers('organization.hierarchy.move.approve', $scope),
            'X-Actor-Reference' => 'independent-approver',
            'If-Match' => '1',
            'Idempotency-Key' => 'move-approve-test-0001',
        ])->postJson("/api/v1/organization-moves/{$moveId}/approve", [
            'reason' => 'Independent approval',
        ])->assertOk()->assertJsonPath('data.approval_status', 'approved');

        $this->withHeaders([
            ...$this->headers('organization.hierarchy.move.apply', $scope),
            'X-Actor-Reference' => 'independent-applier',
            'If-Match' => '2',
            'Idempotency-Key' => 'move-schedule-test-0001',
        ])->postJson("/api/v1/organization-moves/{$moveId}/schedule", [
            'effective_at' => now()->subSecond()->toISOString(),
            'reason' => 'Scheduled test application',
        ])->assertOk()->assertJsonPath('data.application_status', 'scheduled');

        $apply = $this->withHeaders([
            ...$this->headers('organization.hierarchy.move.apply', $scope),
            'X-Actor-Reference' => 'independent-applier',
            'If-Match' => '3',
            'Idempotency-Key' => 'move-apply-test-0001',
        ])->postJson("/api/v1/organization-moves/{$moveId}/apply", [
            'reason' => 'Apply due test move',
        ])->assertOk()->assertJsonPath('data.status', 'applied');

        $this->assertSame(
            $newParent->id,
            app(HierarchyService::class)->parentId($child->id, CarbonImmutable::now()),
        );
        $this->assertDatabaseHas('organization_hierarchy_change_history', [
            'event_type' => 'organization.hierarchy.move.applied',
            'subject_id' => $moveId,
        ]);

        $this->withHeaders([
            ...$this->headers('organization.hierarchy.move.apply', $scope),
            'X-Actor-Reference' => 'independent-applier',
            'If-Match' => '3',
            'Idempotency-Key' => 'move-apply-test-0001',
        ])->postJson("/api/v1/organization-moves/{$moveId}/apply", [
            'reason' => 'Apply due test move',
        ])->assertOk()->assertHeader('Idempotent-Replay', 'true')
            ->assertJsonPath('data.new_edge_id', $apply->json('data.new_edge_id'));
    }

    /**
     * @param  list<string>  $scope
     * @return array<string, string>
     */
    private function headers(string $permission, array $scope = []): array
    {
        return [
            'X-Actor-Reference' => 'test-actor',
            'X-Permissions' => $permission,
            'X-Organization-Scope' => implode(',', $scope),
        ];
    }

    private function type(string $code, bool $root): OrganizationType
    {
        return OrganizationType::query()->create([
            'code' => $code,
            'name_key' => 'test.'.$code,
            'translations' => ['en' => $code, 'om' => $code, 'am' => $code],
            'description' => 'Fictional test type',
            'sort_order' => 1,
            'may_be_root' => $root,
            'status' => 'active',
            'configuration_status' => 'approved',
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    private function organization(string $code, OrganizationType $type): Organization
    {
        return Organization::query()->create([
            'type_id' => $type->id,
            'code' => $code,
            'name' => ['en' => "Fictional {$code}", 'om' => "[review {$code}]", 'am' => "[review {$code}]"],
            'status' => 'active',
            'effective_from' => '2026-01-01 00:00:00',
        ]);
    }

    private function edge(Organization $parent, Organization $child, string $from, ?string $to = null, string $status = 'active'): OrganizationHierarchyEdge
    {
        return OrganizationHierarchyEdge::query()->create([
            'parent_id' => $parent->id,
            'child_id' => $child->id,
            'status' => $status,
            'effective_from' => $from,
            'effective_to' => $to,
        ]);
    }
}
