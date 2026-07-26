<?php

namespace App\Http\Middleware;

use App\Organization\Models\HierarchyMoveRequest;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceOrganizationPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new AuthenticationException;
        }

        $actor = trim((string) $request->header('X-Actor-Reference'));
        if ($actor === '') {
            throw new AuthenticationException;
        }

        $permissions = array_filter(array_map('trim', explode(',', (string) $request->header('X-Permissions'))));
        if (! in_array($permission, $permissions, true)) {
            throw new AuthorizationException;
        }

        $scopedOrganizations = array_filter(array_map('trim', explode(',', (string) $request->header('X-Organization-Scope'))));
        $move = $request->route('move');
        $requiresNodeScope = ! str_starts_with($permission, 'organization.type.')
            && ($request->route('organization') !== null || $move instanceof HierarchyMoveRequest);
        if ($requiresNodeScope && $scopedOrganizations === []) {
            throw new AuthorizationException;
        }
        foreach (['organizationId', 'organization', 'source_organization_id', 'proposed_parent_organization_id'] as $key) {
            $target = $request->route($key) ?? $request->input($key);
            if (is_object($target) && method_exists($target, 'getKey')) {
                $target = $target->getKey();
            }
            if ($target !== null && $scopedOrganizations !== [] && ! in_array((string) $target, $scopedOrganizations, true)) {
                throw new AuthorizationException;
            }
        }
        if ($move instanceof HierarchyMoveRequest) {
            foreach (['source_organization_id', 'proposed_parent_id'] as $attribute) {
                $target = $move->getAttribute($attribute);
                if ($target !== null && ! in_array((string) $target, $scopedOrganizations, true)) {
                    throw new AuthorizationException;
                }
            }
        }
        $request->attributes->set('actor_reference', $actor);

        return $next($request);
    }
}
