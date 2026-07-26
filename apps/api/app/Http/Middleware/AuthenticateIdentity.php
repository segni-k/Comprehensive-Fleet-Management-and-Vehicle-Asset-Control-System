<?php

namespace App\Http\Middleware;

use App\Identity\Services\SessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateIdentity
{
    public function __construct(private readonly SessionService $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = $this->sessions->authenticate($request);
        $request->setUserResolver(fn () => $session->user);
        $request->attributes->set('identity_session', $session);
        $request->attributes->set('actor_reference', $session->user_id);

        return $next($request);
    }
}
