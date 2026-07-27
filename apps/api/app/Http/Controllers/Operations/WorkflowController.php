<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\ConfigureWorkflowRequest;
use App\Http\Requests\Workflow\TransitionWorkflowRequest;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Workflow\Models\WorkflowDefinition;
use App\Workflow\Models\WorkflowInstance;
use App\Workflow\Services\WorkflowCollaborationService;
use App\Workflow\Services\WorkflowDefinitionService;
use App\Workflow\Services\WorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkflowController extends Controller
{
    public function __construct(
        private readonly WorkflowDefinitionService $definitions,
        private readonly WorkflowService $workflows,
        private readonly WorkflowCollaborationService $collaboration,
        private readonly AuthorizationService $authorization,
    ) {}

    public function definitions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'process_type' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:30'],
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
        ]);
        $this->authorizeOrganization($request, 'workflow.view', $data['organization_id']);

        return response()->json(['data' => WorkflowDefinition::query()
            ->with(['states', 'transitions'])
            ->when($data['process_type'] ?? null, fn ($query, $value) => $query->where('process_type', $value))
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->where(fn ($query) => $query
                ->where('organization_id', $data['organization_id'])
                ->orWhereNull('organization_id'))
            ->latest()
            ->paginate()]);
    }

    public function createDefinition(ConfigureWorkflowRequest $request): JsonResponse
    {
        $this->authorizeOrganization(
            $request,
            'workflow.configure',
            $request->string('organization_id')->toString(),
        );

        return response()->json(['data' => $this->definitions->create($request->validated(), $request->user())], 201);
    }

    public function publishDefinition(Request $request, WorkflowDefinition $definition): JsonResponse
    {
        $data = $request->validate(['record_version' => ['required', 'integer', 'min:1']]);
        abort_if($definition->organization_id === null, 403);
        $this->authorizeOrganization($request, 'workflow.approve', $definition->organization_id);

        return response()->json(['data' => $this->definitions->publish(
            $definition,
            $data['record_version'],
            $request->user(),
        )]);
    }

    public function instances(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'status' => ['nullable', 'string', 'max:30'],
            'assigned_to_me' => ['nullable', 'boolean'],
        ]);
        $this->authorizeOrganization($request, 'workflow.view', $data['organization_id']);
        $instances = WorkflowInstance::query()
            ->with(['definition', 'currentState'])
            ->where('organization_id', $data['organization_id'])
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($data['assigned_to_me'] ?? false, fn ($query) => $query->whereHas(
                'assignments',
                fn ($assignment) => $assignment->where('assigned_user_id', $request->user()->id)->where('status', 'open'),
            ))
            ->latest()
            ->paginate();

        return response()->json($instances);
    }

    public function show(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $this->authorizeOrganization($request, 'workflow.view', $instance->organization_id);

        return response()->json(['data' => $instance->load([
            'definition.states', 'definition.transitions', 'currentState', 'actions', 'assignments', 'comments',
        ])]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workflow_definition_id' => ['required', 'exists:workflow_definitions,id'],
            'organization_id' => ['required', 'exists:organizations,id'],
            'subject_type' => ['required', 'string', 'max:100'],
            'subject_id' => ['required', 'string', 'size:26'],
            'context' => ['required', 'array'],
        ]);
        $definition = WorkflowDefinition::query()->whereKey($data['workflow_definition_id'])->firstOrFail();
        abort_unless($definition->organization_id === null || $definition->organization_id === $data['organization_id'], 422);
        $this->authorizeOrganization($request, 'workflow.view', $data['organization_id']);
        $instance = $this->workflows->start(
            $definition, $request->user(), $data['organization_id'],
            $data['subject_type'], $data['subject_id'], $data['context'],
        );

        return response()->json(['data' => $instance], 201);
    }

    public function transition(TransitionWorkflowRequest $request, WorkflowInstance $instance): JsonResponse
    {
        $transition = $instance->definition()->firstOrFail()->transitions()
            ->where('code', $request->string('transition_code'))
            ->firstOrFail();
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession, 401);
        $action = $this->workflows->transition(
            $instance, $transition, $request->user(), $session,
            $request->string('reason')->toString(),
            $request->string('idempotency_key')->toString(),
            $request->integer('expected_record_version'),
            $request->input('context', []),
        );

        return response()->json(['data' => $action], 201);
    }

    public function comment(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:4000'],
            'document_id' => ['nullable', 'string', 'exists:documents,id'],
        ]);
        $this->authorizeOrganization($request, 'workflow.view', $instance->organization_id);

        return response()->json(['data' => $this->collaboration->comment(
            $instance,
            $request->user(),
            $data['body'],
            $data['document_id'] ?? null,
        )], 201);
    }

    public function reassign(Request $request, WorkflowInstance $instance): JsonResponse
    {
        $data = $request->validate([
            'assigned_user_id' => ['required', 'string', 'exists:users,id'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'expected_record_version' => ['required', 'integer', 'min:1'],
        ]);
        $this->authorizeOrganization($request, 'workflow.approve', $instance->organization_id);
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession, 401);

        return response()->json(['data' => $this->collaboration->reassign(
            $instance,
            $data['assigned_user_id'],
            $request->user(),
            $session,
            $data['reason'],
            $data['expected_record_version'],
        )]);
    }

    private function authorizeOrganization(Request $request, string $permission, string $organizationId): void
    {
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession && $this->authorization->allows(
            $request->user(), $permission, $organizationId, null, null, $session,
        ), 403);
    }
}
