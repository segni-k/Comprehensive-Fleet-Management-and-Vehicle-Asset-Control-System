<?php

use App\Http\Controllers\Identity\AuthenticationController;
use App\Http\Controllers\Organization\HierarchyController;
use App\Http\Controllers\Organization\HierarchyMoveController;
use App\Http\Controllers\Organization\OrganizationConfigurationController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\OrganizationTypeController;
use App\Http\Controllers\Platform\HealthController;
use App\Http\Controllers\Platform\PlatformVersionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health/live', [HealthController::class, 'live'])->name('api.v1.health.live');
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('api.v1.health.ready');
    Route::get('/version', PlatformVersionController::class)
        ->middleware('throttle:api')
        ->name('api.v1.version');

    Route::middleware('throttle:api')->group(function (): void {
        Route::post('/auth/login', [AuthenticationController::class, 'login'])->name('api.v1.auth.login');
        Route::post('/auth/mfa/verify', [AuthenticationController::class, 'verifyMfa'])->name('api.v1.auth.mfa.verify');
        Route::post('/auth/refresh', [AuthenticationController::class, 'refresh'])->name('api.v1.auth.refresh');
        Route::post('/auth/password/forgot', [AuthenticationController::class, 'requestPasswordReset'])->name('api.v1.auth.password.forgot');
        Route::post('/auth/password/reset', [AuthenticationController::class, 'resetPassword'])->name('api.v1.auth.password.reset');
    });

    Route::middleware(['throttle:api', 'identity.auth'])->group(function (): void {
        Route::post('/auth/logout', [AuthenticationController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('/me', [AuthenticationController::class, 'me'])->name('api.v1.me');
        Route::get('/me/sessions', [AuthenticationController::class, 'sessions'])->name('api.v1.me.sessions');
        Route::delete('/me/sessions/{session}', [AuthenticationController::class, 'revokeSession'])->name('api.v1.me.sessions.revoke');
    });

    Route::middleware('throttle:api')->group(function (): void {
        Route::get('/organization-types', [OrganizationTypeController::class, 'index'])->middleware('organization.permission:organization.type.view');
        Route::post('/organization-types', [OrganizationTypeController::class, 'store'])->middleware(['organization.permission:organization.type.create', 'idempotent']);
        Route::get('/organization-types/{organizationType}', [OrganizationTypeController::class, 'show'])->middleware('organization.permission:organization.type.view');
        Route::patch('/organization-types/{organizationType}', [OrganizationTypeController::class, 'update'])->middleware('organization.permission:organization.type.update');
        Route::post('/organization-types/{organizationType}/activate', [OrganizationTypeController::class, 'activate'])->middleware(['organization.permission:organization.type.activate', 'idempotent']);
        Route::post('/organization-types/{organizationType}/deactivate', [OrganizationTypeController::class, 'deactivate'])->middleware(['organization.permission:organization.type.deactivate', 'idempotent']);
        Route::get('/organization-types/{organizationType}/history', [OrganizationTypeController::class, 'history'])->middleware('organization.permission:organization.type.view');
        Route::get('/organization-type-rules', [OrganizationTypeController::class, 'listRules'])->middleware('organization.permission:organization.type.view');
        Route::post('/organization-type-rules', [OrganizationTypeController::class, 'storeRule'])->middleware(['organization.permission:organization.type.update', 'idempotent']);

        Route::get('/organizations/tree', [OrganizationController::class, 'tree'])->middleware('organization.permission:organization.hierarchy.view');
        Route::get('/organizations', [OrganizationController::class, 'index'])->middleware('organization.permission:organization.node.view');
        Route::post('/organizations', [OrganizationController::class, 'store'])->middleware(['organization.permission:organization.node.create', 'idempotent']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->middleware('organization.permission:organization.node.view');
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update'])->middleware('organization.permission:organization.node.update');
        Route::post('/organizations/{organization}/activate', [OrganizationController::class, 'activate'])->middleware(['organization.permission:organization.node.activate', 'idempotent']);
        Route::post('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->middleware(['organization.permission:organization.node.deactivate', 'idempotent']);
        Route::get('/organizations/{organization}/ancestors', [OrganizationController::class, 'ancestors'])->middleware('organization.permission:organization.hierarchy.view');
        Route::get('/organizations/{organization}/descendants', [OrganizationController::class, 'descendants'])->middleware('organization.permission:organization.hierarchy.view');
        Route::get('/organizations/{organization}/history', [OrganizationController::class, 'history'])->middleware('organization.permission:organization.hierarchy.history.view');

        Route::get('/organization-hierarchy-relationships', [HierarchyController::class, 'index'])->middleware('organization.permission:organization.hierarchy.view');
        Route::get('/organization-hierarchy-relationships/{relationshipId}', [HierarchyController::class, 'show'])->middleware('organization.permission:organization.hierarchy.view');
        Route::get('/organization-hierarchy/history', [HierarchyController::class, 'history'])->middleware('organization.permission:organization.hierarchy.history.view');
        Route::get('/organization-hierarchy/as-of', [OrganizationController::class, 'tree'])->middleware('organization.permission:organization.hierarchy.history.view');
        Route::post('/organization-hierarchy/validate', [HierarchyController::class, 'validateRelationship'])->middleware('organization.permission:organization.hierarchy.preview');

        Route::post('/organization-move-previews', [HierarchyMoveController::class, 'storePreview'])->middleware(['organization.permission:organization.hierarchy.preview', 'idempotent']);
        Route::get('/organization-move-previews/{previewId}', [HierarchyMoveController::class, 'showPreview'])->middleware('organization.permission:organization.hierarchy.preview');
        Route::post('/organization-move-previews/{previewId}/cancel', [HierarchyMoveController::class, 'cancelPreview'])->middleware(['organization.permission:organization.hierarchy.preview', 'idempotent']);
        Route::get('/organization-moves', [HierarchyMoveController::class, 'index'])->middleware('organization.permission:organization.hierarchy.view');
        Route::post('/organization-moves', [HierarchyMoveController::class, 'store'])->middleware(['organization.permission:organization.hierarchy.move.request', 'idempotent']);
        Route::get('/organization-moves/{move}', [HierarchyMoveController::class, 'show'])->middleware('organization.permission:organization.hierarchy.view');
        Route::post('/organization-moves/{move}/approve', [HierarchyMoveController::class, 'approve'])->middleware(['organization.permission:organization.hierarchy.move.approve', 'idempotent']);
        Route::post('/organization-moves/{move}/reject', [HierarchyMoveController::class, 'reject'])->middleware(['organization.permission:organization.hierarchy.move.reject', 'idempotent']);
        Route::post('/organization-moves/{move}/cancel', [HierarchyMoveController::class, 'cancel'])->middleware(['organization.permission:organization.hierarchy.move.request', 'idempotent']);
        Route::post('/organization-moves/{move}/schedule', [HierarchyMoveController::class, 'schedule'])->middleware(['organization.permission:organization.hierarchy.move.apply', 'idempotent']);
        Route::post('/organization-moves/{move}/apply', [HierarchyMoveController::class, 'apply'])->middleware(['organization.permission:organization.hierarchy.move.apply', 'idempotent']);
        Route::get('/organization-moves/{move}/history', [HierarchyMoveController::class, 'history'])->middleware('organization.permission:organization.hierarchy.history.view');

        Route::get('/organizations/{organization}/contacts', [OrganizationConfigurationController::class, 'contacts'])->middleware('organization.permission:organization.contact.manage');
        Route::post('/organizations/{organization}/contacts', [OrganizationConfigurationController::class, 'storeContact'])->middleware(['organization.permission:organization.contact.manage', 'idempotent']);
        Route::get('/organizations/{organization}/contacts/history', [OrganizationConfigurationController::class, 'contactHistory'])->middleware('organization.permission:organization.contact.manage');
        Route::get('/organizations/{organization}/contacts/{contact}', [OrganizationConfigurationController::class, 'showContact'])->middleware('organization.permission:organization.contact.manage');
        Route::patch('/organizations/{organization}/contacts/{contact}', [OrganizationConfigurationController::class, 'updateContact'])->middleware('organization.permission:organization.contact.manage');
        Route::post('/organizations/{organization}/contacts/{contact}/end', [OrganizationConfigurationController::class, 'endContact'])->middleware(['organization.permission:organization.contact.manage', 'idempotent']);

        Route::get('/organizations/{organization}/managers', [OrganizationConfigurationController::class, 'managers'])->middleware('organization.permission:organization.manager.manage');
        Route::post('/organizations/{organization}/managers', [OrganizationConfigurationController::class, 'storeManager'])->middleware(['organization.permission:organization.manager.manage', 'idempotent']);
        Route::get('/organizations/{organization}/managers/history', [OrganizationConfigurationController::class, 'managerHistory'])->middleware('organization.permission:organization.manager.manage');
        Route::get('/organizations/{organization}/managers/{manager}', [OrganizationConfigurationController::class, 'showManager'])->middleware('organization.permission:organization.manager.manage');
        Route::patch('/organizations/{organization}/managers/{manager}', [OrganizationConfigurationController::class, 'updateManager'])->middleware('organization.permission:organization.manager.manage');
        Route::post('/organizations/{organization}/managers/{manager}/end', [OrganizationConfigurationController::class, 'endManager'])->middleware(['organization.permission:organization.manager.manage', 'idempotent']);

        Route::get('/organizations/{organization}/settings', [OrganizationConfigurationController::class, 'settings'])->middleware('organization.permission:organization.settings.view');
        Route::post('/organizations/{organization}/settings', [OrganizationConfigurationController::class, 'storeSetting'])->middleware(['organization.permission:organization.settings.manage', 'idempotent']);
        Route::get('/organizations/{organization}/settings/effective', [OrganizationConfigurationController::class, 'effectiveSettings'])->middleware('organization.permission:organization.settings.view');
        Route::get('/organizations/{organization}/settings/inheritance', [OrganizationConfigurationController::class, 'settingInheritance'])->middleware('organization.permission:organization.settings.view');
        Route::get('/organizations/{organization}/settings/history', [OrganizationConfigurationController::class, 'settingHistory'])->middleware('organization.permission:organization.settings.view');
        Route::patch('/organizations/{organization}/settings/{setting}', [OrganizationConfigurationController::class, 'updateSetting'])->middleware('organization.permission:organization.settings.manage');
        Route::post('/organizations/{organization}/settings/{setting}/remove', [OrganizationConfigurationController::class, 'removeSetting'])->middleware('organization.permission:organization.settings.manage');

        Route::get('/organization-readiness', [OrganizationController::class, 'readiness'])->middleware('organization.permission:organization.hierarchy.view');
    });
});
