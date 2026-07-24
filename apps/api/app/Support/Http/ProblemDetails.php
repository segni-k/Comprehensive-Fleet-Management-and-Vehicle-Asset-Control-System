<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProblemDetails
{
    /**
     * @param  array<string, list<string>>|null  $errors
     */
    public static function response(
        Request $request,
        int $status,
        string $code,
        string $title,
        ?array $errors = null,
    ): JsonResponse {
        $body = [
            'type' => sprintf('https://fleet.oromia.gov.et/problems/%s', strtolower($code)),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'correlation_id' => (string) $request->attributes->get('correlation_id'),
        ];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json(
            $body,
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validation(Request $request, array $errors): JsonResponse
    {
        return self::response($request, 422, 'VALIDATION_FAILED', 'Validation failed', $errors);
    }
}
