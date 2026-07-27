<?php

namespace App\Outbox\Services;

use App\Audit\Services\AuditService;
use App\Exceptions\BusinessRuleException;
use App\Identity\Models\User;
use App\Outbox\Contracts\OutboxPublisher;
use App\Outbox\Models\OutboxConsumerReceipt;
use App\Outbox\Models\OutboxDeadLetter;
use App\Outbox\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OutboxService
{
    public function __construct(
        private readonly OutboxPublisher $publisher,
        private readonly AuditService $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(
        string $topic,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        string $deduplicationKey,
        ?string $organizationId = null,
        ?string $correlationId = null,
        ?string $causationId = null,
        int $payloadVersion = 1,
    ): OutboxMessage {
        return OutboxMessage::query()->firstOrCreate(
            ['deduplication_key' => $deduplicationKey],
            [
                'topic' => $topic,
                'idempotency_key' => $deduplicationKey,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'organization_id' => $organizationId,
                'payload' => $payload,
                'payload_version' => $payloadVersion,
                'correlation_id' => $correlationId,
                'causation_id' => $causationId,
                'status' => 'pending',
                'available_at' => now(),
                'next_attempt_at' => now(),
            ],
        );
    }

    public function processDue(int $limit = 100, ?string $workerId = null, ?string $organizationId = null): int
    {
        $worker = $workerId ?? (gethostname() ?: 'worker').'-'.Str::random(8);
        $processed = 0;
        $ids = OutboxMessage::query()
            ->whereIn('status', ['pending', 'retryable_failure', 'processing'])
            ->when($organizationId, fn ($query, $value) => $query->where('organization_id', $value))
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $claimed = OutboxMessage::query()->whereKey($id)
                ->whereIn('status', ['pending', 'retryable_failure', 'processing'])
                ->where(fn ($query) => $query->whereNull('locked_until')->orWhere('locked_until', '<', now()))
                ->update(['status' => 'processing', 'lock_owner' => $worker, 'locked_until' => now()->addMinutes(2)]);
            if ($claimed !== 1) {
                continue;
            }
            /** @var OutboxMessage $message */
            $message = OutboxMessage::query()->whereKey($id)->firstOrFail();
            try {
                $this->publisher->publish($message);
                $message->forceFill([
                    'status' => 'published',
                    'published_at' => now(),
                    'lock_owner' => null,
                    'locked_until' => null,
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();
            } catch (Throwable $exception) {
                $this->fail($message, $exception);
            }
            $processed++;
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $resultMetadata
     */
    public function recordConsumerReceipt(
        string $consumer,
        OutboxMessage $message,
        string $idempotencyKey,
        array $resultMetadata = [],
    ): OutboxConsumerReceipt {
        return OutboxConsumerReceipt::query()->firstOrCreate(
            ['consumer' => $consumer, 'idempotency_key' => $idempotencyKey],
            [
                'outbox_message_id' => $message->id,
                'processed_at' => now(),
                'result_metadata' => $resultMetadata,
            ],
        );
    }

    public function replay(OutboxDeadLetter $deadLetter, string $actorId, string $reason): OutboxMessage
    {
        return DB::transaction(function () use ($deadLetter, $actorId, $reason): OutboxMessage {
            /** @var OutboxDeadLetter $lockedDeadLetter */
            $lockedDeadLetter = OutboxDeadLetter::query()->whereKey($deadLetter->id)->lockForUpdate()->firstOrFail();
            if ($lockedDeadLetter->replayed_at !== null) {
                throw new BusinessRuleException('OUTBOX_REPLAY_CONFLICT', 'The dead letter has already been replayed.');
            }
            /** @var OutboxMessage $message */
            $message = OutboxMessage::query()->whereKey($lockedDeadLetter->outbox_message_id)->lockForUpdate()->firstOrFail();
            $message->forceFill([
                'status' => 'pending',
                'attempts' => 0,
                'failed_at' => null,
                'next_attempt_at' => now(),
                'available_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
            $lockedDeadLetter->forceFill(['replayed_at' => now(), 'replayed_by' => $actorId, 'replay_reason' => $reason])->save();
            $this->audit->record(
                'outbox.dead_letter.replayed.succeeded', 'outbox', 'replay', 'succeeded',
                'outbox_message', $message->id, $message->organization_id,
                User::query()->find($actorId), reason: $reason,
                before: ['status' => 'terminal_failure'],
                after: ['status' => 'pending'],
                metadata: ['dead_letter_id' => $lockedDeadLetter->id],
                severity: 'warning',
                priority: 'high',
            );

            return $message;
        });
    }

    private function fail(OutboxMessage $message, Throwable $exception): void
    {
        $attempts = $message->attempts + 1;
        $terminal = $attempts >= $message->maximum_attempts;
        $message->forceFill([
            'attempts' => $attempts,
            'status' => $terminal ? 'terminal_failure' : 'retryable_failure',
            'next_attempt_at' => $terminal ? null : now()->addSeconds(min(3600, 2 ** $attempts * 15)),
            'failed_at' => $terminal ? now() : null,
            'lock_owner' => null,
            'locked_until' => null,
            'last_error_code' => class_basename($exception),
            'last_error_message' => 'Publisher reported a classified failure. Review protected worker logs using the correlation identifier.',
        ])->save();
        if ($terminal) {
            $deadLetter = OutboxDeadLetter::query()->firstOrCreate(
                ['outbox_message_id' => $message->id],
                [
                    'failure_class' => class_basename($exception),
                    'safe_diagnostic' => 'Publication failed after maximum attempts.',
                    'attempts' => $attempts,
                    'failed_at' => now(),
                ],
            );
            if ($deadLetter->wasRecentlyCreated) {
                $this->audit->record(
                    'outbox.message.terminal_failure', 'outbox', 'publish', 'failed',
                    'outbox_message', $message->id, $message->organization_id,
                    reason: 'Publisher exhausted the configured maximum attempts.',
                    after: ['status' => 'terminal_failure', 'attempts' => $attempts],
                    metadata: ['failure_class' => class_basename($exception)],
                    severity: 'error',
                    priority: 'high',
                );
            }
        }
    }
}
