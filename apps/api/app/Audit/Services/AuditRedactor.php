<?php

namespace App\Audit\Services;

final class AuditRedactor
{
    /** @var list<string> */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'secret', 'token', 'authorization', 'cookie', 'mfa',
        'recovery_code', 'storage_key', 'document_content', 'private_key',
    ];

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public function redact(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $redacted = [];
        foreach ($payload as $key => $value) {
            $normalized = mb_strtolower((string) $key);
            if ($this->isSensitive($normalized)) {
                $redacted[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $redacted[$key] = $this->redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $redacted[$key] = $value;
            } else {
                $redacted[$key] = '[NON_SCALAR]';
            }
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<string>
     */
    public function changedFields(?array $before, ?array $after): array
    {
        $keys = array_unique([...array_keys($before ?? []), ...array_keys($after ?? [])]);

        return array_values(array_filter($keys, fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null)));
    }

    private function isSensitive(string $key): bool
    {
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
