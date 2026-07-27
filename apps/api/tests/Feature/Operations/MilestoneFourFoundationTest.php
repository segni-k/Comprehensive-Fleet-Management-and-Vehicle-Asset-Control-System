<?php

namespace Tests\Feature\Operations;

use App\Audit\Services\AuditService;
use App\Documents\Models\DocumentType;
use App\Documents\Services\DocumentService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\Permission;
use App\Identity\Models\Role;
use App\Identity\Models\User;
use App\Identity\Models\UserRoleAssignment;
use App\Identity\Models\UserSession;
use App\Identity\Services\SessionService;
use App\Notifications\Models\NotificationTemplate;
use App\Notifications\Services\NotificationService;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationType;
use App\Outbox\Contracts\OutboxPublisher;
use App\Outbox\Models\OutboxDeadLetter;
use App\Outbox\Models\OutboxMessage;
use App\Outbox\Services\OutboxService;
use App\Workflow\Services\WorkflowCollaborationService;
use App\Workflow\Services\WorkflowDefinitionService;
use App\Workflow\Services\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MilestoneFourFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_chain_redacts_sensitive_values_and_is_immutable(): void
    {
        [$organization, $actor] = $this->context();
        $audit = app(AuditService::class);
        $event = $audit->record(
            'neutral.record.updated.succeeded', 'business', 'update', 'succeeded',
            'neutral_record', $actor->id, $organization->id, $actor, null, 'Controlled update',
            ['status' => 'draft', 'password' => 'never-store-me'],
            ['status' => 'submitted', 'access_token' => 'never-store-me'],
        );

        $this->assertSame('[REDACTED]', $event->before_snapshot['password']);
        $this->assertSame('[REDACTED]', $event->after_snapshot['access_token']);
        $this->assertSame(['status', 'password', 'access_token'], $event->changed_fields);
        $this->assertTrue($audit->verify($organization->id)['valid']);
        DB::table('audit_events')->where('id', $event->id)->update(['outcome' => 'altered']);
        $this->assertFalse($audit->verify($organization->id)['valid']);
        $this->assertSame(1, $audit->verify($organization->id)['first_invalid_sequence']);
        $this->expectException(\LogicException::class);
        $event->refresh()->update(['outcome' => 'altered-again']);
    }

    public function test_document_upload_is_private_versioned_checksummed_and_quarantined(): void
    {
        Storage::fake('local');
        [$organization, $actor] = $this->context();
        $type = DocumentType::query()->create([
            'code' => 'NEUTRAL_EVIDENCE',
            'name' => ['en' => 'Neutral evidence'],
            'allowed_mime_types' => ['application/pdf'],
            'maximum_bytes' => 1_000_000,
            'malware_scan_required' => true,
            'retention_class' => 'business_record',
            'status' => 'active',
        ]);
        $file = UploadedFile::fake()->create('evidence.pdf', 12, 'application/pdf');
        $document = app(DocumentService::class)->upload(
            $file, $type, $actor, $organization->id, 'neutral_record', $actor->id,
            'supporting_evidence', 'internal',
        );

        $this->assertSame('quarantined', $document->status);
        $this->assertSame(1, $document->currentVersion->version_number);
        $this->assertSame(hash_file('sha256', $file->getRealPath()), $document->currentVersion->checksum);
        Storage::disk('local')->assertExists($document->currentVersion->storage_key);
        $this->assertDatabaseHas('outbox_messages', ['topic' => 'documents.scan.requested']);
    }

    public function test_workflow_enforces_maker_checker_version_and_idempotency(): void
    {
        [$organization, $maker] = $this->context();
        $checker = User::factory()->create();
        $permission = Permission::query()->create([
            'code' => 'neutral.approve',
            'domain' => 'neutral',
            'description' => 'Approve neutral test workflow',
            'allowed_scope_modes' => ['current_node'],
            'delegable' => true,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'code' => 'NEUTRAL_CHECKER',
            'name' => ['en' => 'Neutral checker'],
            'description' => 'Test-only configurable checker',
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);
        $role->permissions()->attach($permission->id);
        $authorityAssignment = UserRoleAssignment::query()->create([
            'user_id' => $checker->id,
            'role_id' => $role->id,
            'organization_id' => $organization->id,
            'scope_mode' => 'current_node',
            'effective_from' => now()->subDay(),
            'requested_by' => $maker->id,
            'approved_by' => $checker->id,
            'assigned_by' => $maker->id,
            'assignment_authority_snapshot' => ['test' => true],
            'status' => 'active',
            'reason' => 'Test authority',
        ]);
        $session = $this->userSession($checker);
        $definition = app(WorkflowDefinitionService::class)->create($this->workflowDefinition());
        $definition->forceFill(['status' => 'active'])->save();
        $instance = app(WorkflowService::class)->start(
            $definition->refresh(), $maker, $organization->id, 'neutral_record', $maker->id, ['classification' => 'internal'],
        );
        $comment = app(WorkflowCollaborationService::class)->comment(
            $instance,
            $maker,
            'Evidence package prepared for independent review.',
            null,
        );
        $replacementChecker = User::factory()->create();
        $assignment = app(WorkflowCollaborationService::class)->reassign(
            $instance,
            $replacementChecker->id,
            $checker,
            $session,
            'Balanced review workload.',
            1,
        );
        $transition = $definition->transitions()->where('code', 'approve')->firstOrFail();
        $service = app(WorkflowService::class);
        $action = $service->transition($instance, $transition, $checker, $session, 'Independently checked', 'decision-0001', 2);
        $duplicate = $service->transition($instance, $transition, $checker, $session, 'Independently checked', 'decision-0001', 2);

        $this->assertTrue($action->is($duplicate));
        $this->assertSame('open', $assignment->status);
        $this->assertDatabaseHas('workflow_comments', ['id' => $comment->id]);
        $this->assertSame('completed', $instance->refresh()->status);
        $this->assertDatabaseCount('workflow_actions', 1);
        $this->assertSame($authorityAssignment->id, $action->role_assignment_id);
        $this->assertNull($action->delegation_id);
        $this->assertDatabaseHas('outbox_messages', ['deduplication_key' => 'workflow-action:'.$action->id]);
    }

    public function test_notification_deduplication_and_outbox_dead_letter_replay_are_safe(): void
    {
        [$organization, $recipient] = $this->context();
        NotificationTemplate::query()->create([
            'code' => 'NEUTRAL_DECISION',
            'version_number' => 1,
            'channel' => 'in_app',
            'locale' => 'en',
            'subject' => 'Decision ready',
            'body' => 'Reference {{reference}} is ready.',
            'allowed_variables' => ['reference'],
            'classification' => 'internal',
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);
        $service = app(NotificationService::class);
        $first = $service->create(
            $recipient, 'NEUTRAL_DECISION', 'neutral.decision', 'neutral:1',
            ['reference' => 'REF-001'], ['reference' => 'REF-001', 'token' => 'excluded'],
            $organization->id,
        );
        $second = $service->create(
            $recipient, 'NEUTRAL_DECISION', 'neutral.decision', 'neutral:1',
            ['reference' => 'REF-001'], [], $organization->id,
        );
        $this->assertTrue($first->is($second));
        $this->assertArrayNotHasKey('token', $first->safe_payload);

        $this->app->instance(OutboxPublisher::class, new class implements OutboxPublisher
        {
            public function publish(OutboxMessage $message): void
            {
                throw new \RuntimeException('provider secret must not be stored');
            }
        });
        $outbox = app(OutboxService::class);
        $message = $outbox->enqueue('neutral.failed', 'neutral', $first->id, [], 'terminal-test');
        $message->forceFill(['maximum_attempts' => 1])->save();
        $outbox->processDue();
        $deadLetter = OutboxDeadLetter::query()->where('outbox_message_id', $message->id)->firstOrFail();
        $this->assertSame('Publication failed after maximum attempts.', $deadLetter->safe_diagnostic);
        $this->assertStringNotContainsString('provider secret', $message->refresh()->last_error_message);
        $outbox->replay($deadLetter, $recipient->id, 'Adapter recovered and replay was independently authorized.');
        $this->assertSame('pending', $message->refresh()->status);
        $receipt = $outbox->recordConsumerReceipt('neutral-projection', $message, 'neutral-consumer-0001');
        $duplicateReceipt = $outbox->recordConsumerReceipt('neutral-projection', $message, 'neutral-consumer-0001');
        $this->assertTrue($receipt->is($duplicateReceipt));

        $this->app->instance(OutboxPublisher::class, new class implements OutboxPublisher
        {
            public function publish(OutboxMessage $message): void {}
        });
        $recoveredOutbox = app(OutboxService::class);
        $stale = $recoveredOutbox->enqueue('neutral.stale', 'neutral', $first->id, [], 'stale-lock-test');
        $stale->forceFill([
            'status' => 'processing',
            'lock_owner' => 'crashed-worker',
            'locked_until' => now()->subMinute(),
        ])->save();
        $this->assertGreaterThanOrEqual(1, $recoveredOutbox->processDue(100));
        $this->assertSame('published', $stale->refresh()->status);

        $this->expectException(BusinessRuleException::class);
        $outbox->replay($deadLetter, $recipient->id, 'A second replay must be rejected under the locked service boundary.');
    }

    public function test_audit_api_denies_cross_organization_direct_object_access(): void
    {
        [$allowedOrganization, $actor] = $this->context();
        [$otherOrganization] = $this->context();
        $event = app(AuditService::class)->record(
            'neutral.record.viewed.succeeded', 'business', 'view', 'succeeded',
            'neutral_record', $actor->id, $otherOrganization->id, $actor,
        );
        $permission = Permission::query()->create([
            'code' => 'audit.event.view',
            'domain' => 'audit',
            'description' => 'View audit evidence',
            'allowed_scope_modes' => ['current_node'],
            'delegable' => false,
            'status' => 'active',
        ]);
        $documentPermission = Permission::query()->create([
            'code' => 'document.view',
            'domain' => 'document',
            'description' => 'View document metadata',
            'allowed_scope_modes' => ['current_node'],
            'delegable' => false,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'code' => 'NEUTRAL_AUDITOR',
            'name' => ['en' => 'Neutral auditor'],
            'description' => 'Test-only auditor',
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);
        $role->permissions()->attach([$permission->id, $documentPermission->id]);
        UserRoleAssignment::query()->create([
            'user_id' => $actor->id,
            'role_id' => $role->id,
            'organization_id' => $allowedOrganization->id,
            'scope_mode' => 'current_node',
            'effective_from' => now()->subDay(),
            'requested_by' => $actor->id,
            'approved_by' => $actor->id,
            'assigned_by' => $actor->id,
            'assignment_authority_snapshot' => ['test' => true],
            'status' => 'active',
            'reason' => 'Test organization scope',
        ]);
        $token = app(SessionService::class)->create(
            $actor,
            Request::create('/test', 'GET'),
            true,
        )['access_token'];

        $this->withToken($token)
            ->getJson("/api/v1/audit-events/{$event->id}")
            ->assertForbidden();
        $this->withToken($token)
            ->getJson('/api/v1/audit-events?organization_id='.$allowedOrganization->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->withToken($token)
            ->getJson('/api/v1/documents?organization_id='.$otherOrganization->id)
            ->assertForbidden();
    }

    /** @return array{Organization, User} */
    private function context(): array
    {
        $type = OrganizationType::query()->create([
            'code' => 'TEST_'.fake()->unique()->numerify('####'),
            'name_key' => 'test.organization',
            'translations' => ['en' => 'Test organization'],
            'description' => 'Test-only organization type',
            'may_be_root' => true,
            'status' => 'active',
            'configuration_status' => 'approved',
            'effective_from' => now()->subDay(),
        ]);
        $organization = Organization::query()->create([
            'type_id' => $type->id,
            'code' => 'ORG_'.fake()->unique()->numerify('####'),
            'name' => ['en' => 'Test organization'],
            'status' => 'active',
            'effective_from' => now()->subDay(),
        ]);

        return [$organization, User::factory()->create()];
    }

    private function userSession(User $user): UserSession
    {
        return UserSession::query()->create([
            'user_id' => $user->id,
            'access_token_hash' => hash('sha256', fake()->uuid()),
            'refresh_token_hash' => hash('sha256', fake()->uuid()),
            'refresh_family_id' => (string) Str::ulid(),
            'access_expires_at' => now()->addHour(),
            'refresh_expires_at' => now()->addDay(),
            'last_seen_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function workflowDefinition(): array
    {
        return [
            'code' => 'NEUTRAL_REVIEW',
            'version_number' => 1,
            'name' => ['en' => 'Neutral review'],
            'process_type' => 'neutral_record',
            'applicability_rules' => [],
            'assignment_rules' => ['permission' => 'neutral.approve'],
            'maker_checker_required' => true,
            'effective_from' => now()->subDay(),
            'states' => [
                ['code' => 'submitted', 'name' => ['en' => 'Submitted'], 'state_type' => 'initial', 'sort_order' => 1, 'is_initial' => true, 'is_terminal' => false],
                ['code' => 'approved', 'name' => ['en' => 'Approved'], 'state_type' => 'terminal', 'sort_order' => 2, 'is_initial' => false, 'is_terminal' => true],
            ],
            'transitions' => [
                ['code' => 'approve', 'from_state' => 'submitted', 'to_state' => 'approved', 'required_permission' => 'neutral.approve'],
            ],
        ];
    }
}
