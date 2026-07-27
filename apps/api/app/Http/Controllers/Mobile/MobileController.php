<?php

namespace App\Http\Controllers\Mobile;

use App\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use App\Identity\Models\User;
use App\Identity\Models\UserSession;
use App\Identity\Services\AuthorizationService;
use App\Outbox\Services\OutboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileController extends Controller
{
    public function __construct(
        protected readonly AuthorizationService $authorization,
        protected readonly AuditService $audit,
        protected readonly OutboxService $outbox,
    ) {}

    protected function actor(Request $request): User
    {
        return $request->user();
    }

    protected function session(Request $request): ?UserSession
    {
        return $request->attributes->get('user_session');
    }

    protected function authorizeOrganization(
        Request $request,
        string $permission,
        string $organizationId,
        ?string $resourceType = null,
        ?string $resourceId = null,
    ): void {
        $user    = $this->actor($request);
        $session = $this->session($request);
        if (! $this->authorization->allows($user, $permission, $organizationId, $resourceType, $resourceId, $session)) {
            abort(403, 'Insufficient permission: ' . $permission);
        }
    }

    protected function problem(string $title, string $code, int $status = 422, ?string $detail = null): JsonResponse
    {
        return response()->json([
            'type'   => "https://fleet.oromia.gov.et/errors/{$code}",
            'title'  => $title,
            'status' => $status,
            'code'   => $code,
            'detail' => $detail,
        ], $status);
    }
}
