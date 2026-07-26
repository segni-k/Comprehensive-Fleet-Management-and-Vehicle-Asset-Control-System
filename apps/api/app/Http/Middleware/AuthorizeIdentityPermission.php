<?php

namespace App\Http\Middleware;

use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeIdentityPermission
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $organization = $request->route('organization') ?? $request->input('organization_id');
        if (is_object($organization) && method_exists($organization, 'getKey')) {
            $organization = $organization->getKey();
        }
        $session = $request->attributes->get('identity_session');
        if (! $session instanceof UserSession || ! $this->authorization->allows(
            $request->user(),
            $permission,
            is_string($organization) ? $organization : null,
            $request->input('resource_type'),
            $request->input('resource_id'),
            $session,
        )) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
