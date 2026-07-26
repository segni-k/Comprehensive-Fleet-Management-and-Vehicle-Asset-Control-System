<?php

namespace App\Http\Middleware;

use App\Exceptions\ConflictException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EnforceIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if (strlen($key) < 16 || strlen($key) > 100) {
            throw new ConflictException('IDEMPOTENCY_KEY_REQUIRED', 'A valid Idempotency-Key is required');
        }
        $actor = (string) $request->attributes->get('actor_reference');
        $route = '/'.ltrim($request->path(), '/');
        $payloadHash = hash('sha256', (string) $request->getContent());

        return DB::transaction(function () use ($request, $next, $key, $actor, $route, $payloadHash): Response {
            $existing = DB::table('idempotency_keys')
                ->where('actor_type', 'organization-adapter')
                ->where('actor_id', $actor)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash) || $existing->route !== $route) {
                    throw new ConflictException('IDEMPOTENCY_PAYLOAD_MISMATCH', 'Idempotency key was reused with a different command');
                }
                if ($existing->state !== 'completed') {
                    throw new ConflictException('IDEMPOTENCY_IN_PROGRESS', 'The original command is still processing');
                }

                return new JsonResponse(
                    json_decode((string) $existing->response_body, true, flags: JSON_THROW_ON_ERROR),
                    (int) $existing->response_status,
                    ['Idempotent-Replay' => 'true'],
                );
            }
            $id = (string) Str::ulid();
            DB::table('idempotency_keys')->insert([
                'id' => $id,
                'key' => $key,
                'actor_type' => 'organization-adapter',
                'actor_id' => $actor,
                'route' => $route,
                'payload_hash' => $payloadHash,
                'state' => 'processing',
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $response = $next($request);
            DB::table('idempotency_keys')->where('id', $id)->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'state' => 'completed',
                'updated_at' => now(),
            ]);

            return $response;
        }, 3);
    }
}
