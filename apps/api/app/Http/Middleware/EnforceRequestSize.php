<?php

namespace App\Http\Middleware;

use App\Support\Http\ProblemDetails;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $contentLength = $request->server('CONTENT_LENGTH');
        $maximum = (int) config('platform.max_request_bytes');

        if (is_numeric($contentLength) && (int) $contentLength > $maximum) {
            return ProblemDetails::response(
                $request,
                413,
                'PAYLOAD_TOO_LARGE',
                'The request exceeds the configured size limit',
            );
        }

        return $next($request);
    }
}
