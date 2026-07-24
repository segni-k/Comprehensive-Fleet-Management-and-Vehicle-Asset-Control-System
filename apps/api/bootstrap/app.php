<?php

use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnforceRequestSize;
use App\Http\Middleware\LogRequest;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Http\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api_v1.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([
            AssignCorrelationId::class,
            EnforceRequestSize::class,
            LogRequest::class,
            SecurityHeaders::class,
        ]);

        $configuredProxies = getenv('TRUSTED_PROXIES');
        $proxies = is_string($configuredProxies)
            ? array_values(array_filter(array_map('trim', explode(',', $configuredProxies))))
            : [];

        if ($proxies !== []) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            return ProblemDetails::validation($request, $exception->errors());
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            return ProblemDetails::response($request, 401, 'AUTHENTICATION_REQUIRED', 'Authentication required');
        });

        $exceptions->render(function (AuthorizationException $exception, Request $request) {
            return ProblemDetails::response($request, 403, 'AUTHORIZATION_DENIED', 'The action is not permitted');
        });

        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            return ProblemDetails::response($request, 404, 'RESOURCE_NOT_FOUND', 'The requested resource was not found');
        });

        $exceptions->render(function (BusinessRuleException $exception, Request $request) {
            return ProblemDetails::response($request, 422, $exception->errorCode, $exception->getMessage());
        });

        $exceptions->render(function (ConflictException $exception, Request $request) {
            return ProblemDetails::response($request, 409, $exception->errorCode, $exception->getMessage());
        });

        $exceptions->render(function (HttpExceptionInterface $exception, Request $request) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                404 => 'RESOURCE_NOT_FOUND',
                413 => 'PAYLOAD_TOO_LARGE',
                429 => 'RATE_LIMITED',
                default => 'HTTP_ERROR',
            };

            return ProblemDetails::response(
                $request,
                $status,
                $code,
                $status === 429 ? 'Too many requests' : 'The request could not be completed',
            );
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemDetails::response(
                $request,
                500,
                'INTERNAL_ERROR',
                'An unexpected error occurred',
            );
        });
    })->create();
