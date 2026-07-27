<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Notifications\Models\InAppNotification;
use App\Notifications\Models\NotificationPreference;
use App\Notifications\Models\NotificationTemplate;
use App\Notifications\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuthorizationService $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:unread,read,acknowledged'],
            'severity' => ['nullable', 'in:information,success,warning,error,critical'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $notifications = InAppNotification::query()
            ->where('recipient_user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($data['severity'] ?? null, fn ($query, $value) => $query->where('severity', $value))
            ->latest()
            ->paginate($data['per_page'] ?? 25);

        return response()->json($notifications);
    }

    public function markRead(Request $request, InAppNotification $notification): JsonResponse
    {
        return response()->json(['data' => $this->notifications->markRead($notification, $request->user())]);
    }

    public function templates(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'code' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'in:in_app,email,sms'],
            'locale' => ['nullable', 'in:en,om,am'],
            'status' => ['nullable', 'in:draft,active,inactive'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id']);

        return response()->json(['data' => NotificationTemplate::query()
            ->where(fn ($query) => $query
                ->where('organization_id', $data['organization_id'])
                ->orWhereNull('organization_id'))
            ->when($data['code'] ?? null, fn ($query, $value) => $query->where('code', $value))
            ->when($data['channel'] ?? null, fn ($query, $value) => $query->where('channel', $value))
            ->when($data['locale'] ?? null, fn ($query, $value) => $query->where('locale', $value))
            ->when($data['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->latest()
            ->paginate()]);
    }

    public function createTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organization_id' => ['required', 'string', 'exists:organizations,id'],
            'code' => ['required', 'string', 'max:120'],
            'version_number' => ['required', 'integer', 'min:1'],
            'channel' => ['required', 'in:in_app,email,sms'],
            'locale' => ['required', 'in:en,om,am'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:4000'],
            'allowed_variables' => ['required', 'array'],
            'allowed_variables.*' => ['string', 'max:80'],
            'classification' => ['required', 'in:public,internal,confidential,restricted'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->authorizeOrganization($request, $data['organization_id']);
        $template = $this->notifications->createTemplate($data, $request->user());

        return response()->json(['data' => $template], 201);
    }

    public function activateTemplate(Request $request, NotificationTemplate $template): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        abort_if($template->organization_id === null, 403);
        $this->authorizeOrganization($request, $template->organization_id);

        return response()->json(['data' => $this->notifications->activateTemplate(
            $template,
            $request->user(),
            $data['reason'],
        )]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['data' => NotificationPreference::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('event_type')
            ->orderBy('channel')
            ->get()]);
    }

    public function updatePreference(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:190'],
            'channel' => ['required', 'in:in_app,email,sms'],
            'enabled' => ['required', 'boolean'],
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.start' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.end' => ['required_with:quiet_hours', 'date_format:H:i'],
            'quiet_hours.timezone' => ['required_with:quiet_hours', 'timezone'],
        ]);
        $preference = NotificationPreference::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'event_type' => $data['event_type'],
                'channel' => $data['channel'],
            ],
            [
                'enabled' => $data['enabled'],
                'quiet_hours' => $data['quiet_hours'] ?? null,
            ],
        );

        return response()->json(['data' => $preference]);
    }

    private function authorizeOrganization(Request $request, string $organizationId): void
    {
        $session = $request->attributes->get('identity_session');
        abort_unless($session instanceof UserSession && $this->authorization->allows(
            $request->user(), 'notification.template.manage', $organizationId, null, null, $session,
        ), 403);
    }
}
