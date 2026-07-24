<?php

namespace App\Http\Controllers\Platform;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PlatformVersionController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'type' => 'platform-version',
                'attributes' => [
                    'application' => config('app.name'),
                    'version' => config('platform.application_version'),
                    'api_version' => 'v1',
                    'environment' => app()->environment(),
                    'time' => now()->toIso8601String(),
                ],
            ],
            'meta' => [
                'correlation_id' => $request->attributes->get('correlation_id'),
            ],
        ]);
    }
}
