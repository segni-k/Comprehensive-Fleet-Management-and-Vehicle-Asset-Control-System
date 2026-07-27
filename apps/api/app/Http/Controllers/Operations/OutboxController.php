<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Models\OutboxDeadLetter;
use App\Outbox\Models\OutboxMessage;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OutboxController extends Controller
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,processing,published,retryable_failure,terminal_failure'],
            'topic' => ['nullable', 'string', 'max:120'],
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'outbox.view', $data['organization_id']);
        $messages = OutboxMessage::query()
            ->select([
                'id', 'topic', 'aggregate_type', 'aggregate_id', 'organization_id',
                'payload_version', 'correlation_id', 'causation_id', 'status',
                'attempts', 'maximum_attempts', 'available_at', 'next_attempt_at',
                'published_at', 'failed_at', 'last_error_code', 'created_at', 'updated_at',
            ])
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($data['topic'] ?? null, fn ($query, $value) => $query->where('topic', $value))
            ->where('organization_id', $data['organization_id'])
            ->latest()
            ->paginate($data['per_page'] ?? 25);

        return response()->json($messages);
    }

    public function deadLetters(Request $request): JsonResponse
    {
        $data = $request->validate(['organization_id' => ['required', 'string', 'exists:organizations,id']]);
        $this->authorizeOrganization($request, 'outbox.view', $data['organization_id']);

        return response()->json(OutboxDeadLetter::query()
            ->whereHas('message', fn ($query) => $query->where('organization_id', $data['organization_id']))
            ->latest('failed_at')
            ->paginate());
    }

    public function replay(Request $request, OutboxDeadLetter $deadLetter): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $deadLetter->load('message');
        abort_if($deadLetter->message->organization_id === null, 403);
        $this->authorizeOrganization($request, 'outbox.replay', $deadLetter->message->organization_id);
        abort_unless($deadLetter->replayed_at === null, 409);

        return response()->json(['data' => $this->outbox->replay($deadLetter, $request->user()->id, $data['reason'])]);
    }

    public function process(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->authorizeOrganization($request, 'outbox.replay', $data['organization_id']);

        return response()->json(['data' => ['processed' => $this->outbox->processDue(
            $data['limit'] ?? 25,
            organizationId: $data['organization_id'],
        )]]);
    }

    private function authorizeOrganization(Request $request, string $permission, string $organizationId): void
    {
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession && $this->authorization->allows(
            $request->user(), $permission, $organizationId, null, null, $session,
        ), 403);
    }
}
