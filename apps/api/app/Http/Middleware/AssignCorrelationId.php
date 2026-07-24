<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignCorrelationId
{
    public const HEADER = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->header(self::HEADER);
        $correlationId = is_string($candidate) && Str::isUuid($candidate)
            ? strtolower($candidate)
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $correlationId);
        Context::add('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
