<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\ConflictException;
use App\Http\Resources\Organization\OrganizationResource;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class OrganizationApiController
{
    protected function respond(Request $request, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $this->resourceData($request, $data),
            'meta' => ['correlation_id' => $request->attributes->get('correlation_id')],
        ], $status);
    }

    private function resourceData(Request $request, mixed $data): mixed
    {
        if ($data instanceof Model) {
            return (new OrganizationResource($data))->resolve($request);
        }

        if ($data instanceof EloquentCollection) {
            return $data
                ->map(fn (Model $model): array => (new OrganizationResource($model))->resolve($request))
                ->values()
                ->all();
        }

        return $data;
    }

    protected function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'), '"W/ ');
        if ($value === '' || ! ctype_digit($value)) {
            throw new ConflictException('CONCURRENCY_TOKEN_REQUIRED', 'A valid If-Match record version is required');
        }

        return (int) $value;
    }

    protected function actor(Request $request): string
    {
        return (string) $request->attributes->get('actor_reference');
    }
}
