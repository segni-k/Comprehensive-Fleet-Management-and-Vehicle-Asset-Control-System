<?php

namespace App\Notifications\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\User;
use App\Notifications\Models\InAppNotification;
use App\Notifications\Models\NotificationDeliveryAttempt;
use App\Notifications\Models\NotificationTemplate;
use App\Outbox\Services\OutboxService;
use Illuminate\Support\Facades\DB;

final class NotificationService
{
    public function __construct(
        private readonly OutboxService $outbox,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, bool|float|int|string|null>  $variables
     * @param  array<string, mixed>  $safePayload
     */
    public function create(
        User $recipient,
        string $templateCode,
        string $eventType,
        string $deduplicationKey,
        array $variables,
        array $safePayload = [],
        ?string $organizationId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        string $severity = 'information',
    ): InAppNotification {
        return DB::transaction(function () use ($recipient, $templateCode, $eventType, $deduplicationKey, $variables, $safePayload, $organizationId, $subjectType, $subjectId, $severity): InAppNotification {
            $template = NotificationTemplate::query()
                ->where('code', $templateCode)
                ->where('channel', 'in_app')
                ->where(fn ($query) => $query
                    ->where('organization_id', $organizationId)
                    ->orWhereNull('organization_id'))
                ->whereIn('locale', [$recipient->preferred_locale, 'en'])
                ->where('status', 'active')
                ->where('effective_from', '<=', now())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()))
                ->orderByRaw('organization_id = ? desc', [$organizationId])
                ->orderByRaw('locale = ? desc', [$recipient->preferred_locale])
                ->latest('version_number')
                ->first();
            if ($template === null) {
                throw new BusinessRuleException('NOTIFICATION_TEMPLATE_UNAVAILABLE', 'No active notification template is available.');
            }
            $this->assertVariables($template, $variables);
            $notification = InAppNotification::query()->firstOrCreate(
                ['recipient_user_id' => $recipient->id, 'deduplication_key' => $deduplicationKey],
                [
                    'organization_id' => $organizationId,
                    'template_id' => $template->id,
                    'event_type' => $eventType,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'title' => $this->render($template->subject ?? '', $variables),
                    'body' => $this->render($template->body, $variables),
                    'safe_payload' => $this->safePayload($safePayload),
                    'severity' => $severity,
                    'status' => 'unread',
                ],
            );
            $this->outbox->enqueue(
                'notification.created', 'notification', $notification->id,
                ['notification_id' => $notification->id, 'recipient_user_id' => $recipient->id],
                'notification-created:'.$notification->id, $organizationId,
            );

            return $notification;
        });
    }

    public function markRead(InAppNotification $notification, User $recipient): InAppNotification
    {
        if ($notification->recipient_user_id !== $recipient->id) {
            throw new BusinessRuleException('NOTIFICATION_NOT_FOUND', 'The notification was not found.');
        }
        $notification->forceFill(['status' => 'read', 'read_at' => now()])->save();

        return $notification->refresh();
    }

    /** @param array<string, mixed> $data */
    public function createTemplate(array $data, User $actor): NotificationTemplate
    {
        return DB::transaction(function () use ($data, $actor): NotificationTemplate {
            $template = NotificationTemplate::query()->create([...$data, 'status' => 'draft']);
            $this->audit->record(
                'notification.template.created.succeeded', 'notification', 'create_template', 'succeeded',
                'notification_template', $template->id, $template->organization_id, $actor,
                reason: 'Notification template draft created.',
                after: ['code' => $template->code, 'version_number' => $template->version_number, 'locale' => $template->locale],
            );

            return $template;
        });
    }

    public function activateTemplate(NotificationTemplate $template, User $actor, string $reason): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $actor, $reason): NotificationTemplate {
            $updated = NotificationTemplate::query()
                ->whereKey($template->id)
                ->where('status', 'draft')
                ->update(['status' => 'active', 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new BusinessRuleException('NOTIFICATION_TEMPLATE_CONFLICT', 'The template is no longer an activatable draft.');
            }
            $activated = $template->refresh();
            $this->audit->record(
                'notification.template.activated.succeeded', 'notification', 'activate_template', 'succeeded',
                'notification_template', $activated->id, $activated->organization_id, $actor,
                reason: $reason,
                before: ['status' => 'draft'],
                after: ['status' => 'active'],
            );
            $this->outbox->enqueue(
                'notification.template.activated', 'notification_template', $activated->id,
                ['notification_template_id' => $activated->id, 'code' => $activated->code],
                'notification-template-activated:'.$activated->id, $activated->organization_id,
            );

            return $activated;
        });
    }

    public function recordDelivery(
        InAppNotification $notification,
        string $channel,
        string $adapter,
        string $status,
        ?string $failureClass = null,
    ): NotificationDeliveryAttempt {
        return NotificationDeliveryAttempt::query()->create([
            'notification_id' => $notification->id,
            'channel' => $channel,
            'adapter' => $adapter,
            'attempt_number' => $notification->deliveryAttempts()->where('channel', $channel)->count() + 1,
            'status' => $status,
            'failure_class' => $failureClass,
            'safe_diagnostic' => $status === 'failed' ? 'Delivery adapter reported a classified failure.' : null,
            'next_attempt_at' => $status === 'retryable_failure' ? now()->addMinutes(5) : null,
            'attempted_at' => now(),
        ]);
    }

    /** @param array<string, bool|float|int|string|null> $variables */
    private function assertVariables(NotificationTemplate $template, array $variables): void
    {
        $unexpected = array_diff(array_keys($variables), $template->allowed_variables);
        if ($unexpected !== []) {
            throw new BusinessRuleException('NOTIFICATION_VARIABLE_NOT_ALLOWED', 'The notification contains an unapproved variable.');
        }
    }

    /** @param array<string, bool|float|int|string|null> $variables */
    private function render(string $template, array $variables): string
    {
        foreach ($variables as $name => $value) {
            $template = str_replace('{{'.$name.'}}', e((string) $value), $template);
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, bool|float|int|string|null>
     */
    private function safePayload(array $payload): array
    {
        $allowed = [];
        foreach ($payload as $key => $value) {
            if (in_array(mb_strtolower((string) $key), ['token', 'secret', 'password', 'document_content'], true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $allowed[$key] = $value;
            }
        }

        return $allowed;
    }
}
