<?php

use App\Http\Controllers\Fleet\AssignmentController;
use App\Http\Controllers\Fleet\DriverController;
use App\Http\Controllers\Fleet\FleetController;
use App\Http\Controllers\Fleet\VehicleController;
use App\Http\Controllers\Geography\GeographyController;
use App\Http\Controllers\Identity\AuthenticationController;
use App\Http\Controllers\Identity\GovernanceController;
use App\Http\Controllers\Operations\AuditController;
use App\Http\Controllers\Operations\DocumentController;
use App\Http\Controllers\Operations\NotificationController;
use App\Http\Controllers\Operations\OutboxController;
use App\Http\Controllers\Operations\WorkflowController;
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

        Route::get('/users', [GovernanceController::class, 'users'])->middleware('identity.permission:identity.user.view');
        Route::post('/users', [GovernanceController::class, 'createUser'])->middleware(['identity.permission:identity.user.manage', 'idempotent']);
        Route::post('/users/{user}/status', [GovernanceController::class, 'changeUserStatus'])->middleware(['identity.permission:identity.user.manage', 'idempotent']);
        Route::get('/permissions', [GovernanceController::class, 'permissions'])->middleware('identity.permission:identity.role.view');
        Route::get('/roles', [GovernanceController::class, 'roles'])->middleware('identity.permission:identity.role.view');
        Route::post('/roles', [GovernanceController::class, 'createRole'])->middleware(['identity.permission:identity.role.manage', 'idempotent']);
        Route::get('/role-assignments', [GovernanceController::class, 'roleAssignments'])->middleware('identity.permission:identity.role_assignment.view');
        Route::post('/role-assignments', [GovernanceController::class, 'requestAssignment'])->middleware(['identity.permission:identity.role_assignment.request', 'idempotent']);
        Route::post('/role-assignments/{assignment}/approve', [GovernanceController::class, 'approveAssignment'])->middleware(['identity.permission:identity.role_assignment.approve', 'idempotent']);
        Route::post('/role-assignments/{assignment}/revoke', [GovernanceController::class, 'revokeAssignment'])->middleware(['identity.permission:identity.role_assignment.approve', 'idempotent']);
        Route::get('/delegations', [GovernanceController::class, 'delegations'])->middleware('identity.permission:identity.delegation.view');
        Route::post('/delegations', [GovernanceController::class, 'requestDelegation'])->middleware(['identity.permission:identity.delegation.request', 'idempotent']);
        Route::post('/delegations/{delegation}/approve', [GovernanceController::class, 'approveDelegation'])->middleware(['identity.permission:identity.delegation.approve', 'idempotent']);
        Route::post('/delegations/{delegation}/revoke', [GovernanceController::class, 'revokeDelegation'])->middleware(['identity.permission:identity.delegation.request', 'idempotent']);
        Route::get('/access-reviews', [GovernanceController::class, 'accessReviews'])->middleware('identity.permission:identity.access_review.manage');
        Route::get('/break-glass-access', [GovernanceController::class, 'breakGlassEvents'])->middleware('identity.permission:identity.break_glass.review');
        Route::post('/break-glass-access', [GovernanceController::class, 'startBreakGlass'])->middleware(['identity.permission:identity.break_glass.request', 'idempotent']);
        Route::post('/break-glass-access/{access}/review', [GovernanceController::class, 'reviewBreakGlass'])->middleware(['identity.permission:identity.break_glass.review', 'idempotent']);
        Route::get('/identity-audit-events', [GovernanceController::class, 'audit'])->middleware('identity.permission:identity.audit.view');

        Route::get('/audit-events', [AuditController::class, 'index'])->middleware('identity.permission:audit.event.view');
        Route::get('/audit-events-export', [AuditController::class, 'export'])->middleware('identity.permission:audit.event.export');
        Route::get('/audit-events/{auditEvent}', [AuditController::class, 'show'])->middleware('identity.permission:audit.event.view');
        Route::post('/audit-integrity/verify', [AuditController::class, 'verify'])->middleware(['identity.permission:audit.integrity.verify', 'idempotent']);

        Route::get('/documents', [DocumentController::class, 'index'])->middleware('identity.permission:document.view');
        Route::post('/documents', [DocumentController::class, 'store'])->middleware(['identity.permission:document.upload', 'idempotent']);
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->middleware('identity.permission:document.view');
        Route::get('/documents/{document}/history', [DocumentController::class, 'history'])->middleware('identity.permission:document.view');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])->middleware('identity.permission:document.download');
        Route::post('/documents/{document}/replace', [DocumentController::class, 'replace'])->middleware(['identity.permission:document.replace', 'idempotent']);
        Route::post('/documents/{document}/archive', [DocumentController::class, 'archive'])->middleware(['identity.permission:document.archive', 'idempotent']);

        Route::get('/workflow-definitions', [WorkflowController::class, 'definitions'])->middleware('identity.permission:workflow.view');
        Route::post('/workflow-definitions', [WorkflowController::class, 'createDefinition'])->middleware(['identity.permission:workflow.configure', 'idempotent']);
        Route::post('/workflow-definitions/{definition}/publish', [WorkflowController::class, 'publishDefinition'])->middleware(['identity.permission:workflow.approve', 'idempotent']);
        Route::get('/workflow-instances', [WorkflowController::class, 'instances'])->middleware('identity.permission:workflow.view');
        Route::post('/workflow-instances', [WorkflowController::class, 'start'])->middleware(['identity.permission:workflow.view', 'idempotent']);
        Route::get('/workflow-instances/{instance}', [WorkflowController::class, 'show'])->middleware('identity.permission:workflow.view');
        Route::post('/workflow-instances/{instance}/transitions', [WorkflowController::class, 'transition'])->middleware(['identity.permission:workflow.view', 'idempotent']);
        Route::post('/workflow-instances/{instance}/comments', [WorkflowController::class, 'comment'])->middleware(['identity.permission:workflow.view', 'idempotent']);
        Route::post('/workflow-instances/{instance}/reassign', [WorkflowController::class, 'reassign'])->middleware(['identity.permission:workflow.approve', 'idempotent']);

        Route::get('/notifications', [NotificationController::class, 'index'])->middleware('identity.permission:notification.view');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->middleware(['identity.permission:notification.view', 'idempotent']);
        Route::get('/notification-preferences', [NotificationController::class, 'preferences'])->middleware('identity.permission:notification.view');
        Route::put('/notification-preferences', [NotificationController::class, 'updatePreference'])->middleware(['identity.permission:notification.view', 'idempotent']);
        Route::get('/notification-templates', [NotificationController::class, 'templates'])->middleware('identity.permission:notification.template.manage');
        Route::post('/notification-templates', [NotificationController::class, 'createTemplate'])->middleware(['identity.permission:notification.template.manage', 'idempotent']);
        Route::post('/notification-templates/{template}/activate', [NotificationController::class, 'activateTemplate'])->middleware(['identity.permission:notification.template.manage', 'idempotent']);

        Route::get('/outbox/messages', [OutboxController::class, 'index'])->middleware('identity.permission:outbox.view');
        Route::get('/outbox/dead-letters', [OutboxController::class, 'deadLetters'])->middleware('identity.permission:outbox.view');
        Route::post('/outbox/dead-letters/{deadLetter}/replay', [OutboxController::class, 'replay'])->middleware(['identity.permission:outbox.replay', 'idempotent']);
        Route::post('/outbox/process', [OutboxController::class, 'process'])->middleware(['identity.permission:outbox.replay', 'idempotent']);

        Route::get('/fleet/reference-data', [FleetController::class, 'referenceData'])->middleware('identity.permission:fleet.reference.view');
        Route::post('/fleet/reference-data/{resource}', [FleetController::class, 'storeReference'])->middleware(['identity.permission:fleet.reference.manage', 'idempotent']);
        Route::post('/fleet/vehicle-licence-compatibility', [FleetController::class, 'linkVehicleLicenceClass'])->middleware(['identity.permission:fleet.reference.manage', 'idempotent']);
        Route::get('/fleet/dashboard', [FleetController::class, 'dashboard'])->middleware('identity.permission:fleet.dashboard.view');
        Route::get('/vehicles', [VehicleController::class, 'index'])->middleware('identity.permission:vehicle.view');
        Route::post('/vehicles', [VehicleController::class, 'store'])->middleware(['identity.permission:vehicle.create', 'idempotent']);
        Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show'])->middleware('identity.permission:vehicle.view');
        Route::post('/vehicles/{vehicle}/status', [VehicleController::class, 'status'])->middleware(['identity.permission:vehicle.status.manage', 'idempotent']);
        Route::post('/vehicles/{vehicle}/transfer', [VehicleController::class, 'transfer'])->middleware(['identity.permission:vehicle.transfer', 'idempotent']);
        Route::post('/vehicles/{vehicle}/odometer-readings', [VehicleController::class, 'odometer'])->middleware(['identity.permission:vehicle.odometer.record', 'idempotent']);
        Route::post('/vehicles/{vehicle}/plates', [VehicleController::class, 'plate'])->middleware(['identity.permission:vehicle.plate.manage', 'idempotent']);
        Route::post('/vehicles/{vehicle}/fleet-assignment', [VehicleController::class, 'fleetUnit'])->middleware(['identity.permission:vehicle.fleet.assign', 'idempotent']);
        Route::post('/vehicles/{vehicle}/compliance-records', [VehicleController::class, 'compliance'])->middleware(['identity.permission:vehicle.compliance.manage', 'idempotent']);
        Route::get('/drivers', [DriverController::class, 'index'])->middleware('identity.permission:driver.view');
        Route::post('/drivers', [DriverController::class, 'store'])->middleware(['identity.permission:driver.create', 'idempotent']);
        Route::get('/drivers/{driver}', [DriverController::class, 'show'])->middleware('identity.permission:driver.view');
        Route::post('/drivers/{driver}/status', [DriverController::class, 'status'])->middleware(['identity.permission:driver.status.manage', 'idempotent']);
        Route::post('/drivers/{driver}/licences', [DriverController::class, 'licence'])->middleware(['identity.permission:driver.licence.manage', 'idempotent']);
        Route::get('/vehicle-driver-assignments', [AssignmentController::class, 'index'])->middleware('identity.permission:assignment.view');
        Route::post('/vehicle-driver-assignments', [AssignmentController::class, 'store'])->middleware(['identity.permission:assignment.create', 'idempotent']);
        Route::post('/vehicle-driver-assignments/{assignment}/close', [AssignmentController::class, 'close'])->middleware(['identity.permission:assignment.close', 'idempotent']);
        Route::get('/me/vehicle-assignments', [AssignmentController::class, 'mine'])->middleware('identity.permission:assignment.own.view');
        Route::post('/me/vehicle-assignments/{assignment}/acknowledge', [AssignmentController::class, 'acknowledge'])->middleware(['identity.permission:assignment.own.acknowledge', 'idempotent']);

        Route::get('/geography/dashboard', [GeographyController::class, 'dashboard'])->middleware('identity.permission:geography.dashboard.view');
        Route::get('/geography/reference-data/place-categories', [GeographyController::class, 'categories'])->middleware('identity.permission:geography.reference.view');
        Route::post('/geography/reference-data/place-categories', [GeographyController::class, 'storeCategory'])->middleware(['identity.permission:geography.reference.manage', 'idempotent']);
        Route::get('/places/tree', [GeographyController::class, 'tree'])->middleware('identity.permission:place.view');
        Route::get('/places', [GeographyController::class, 'places'])->middleware('identity.permission:place.view');
        Route::post('/places', [GeographyController::class, 'storePlace'])->middleware(['identity.permission:place.manage', 'idempotent']);
        Route::get('/places/{place}', [GeographyController::class, 'showPlace'])->middleware('identity.permission:place.view');
        Route::patch('/places/{place}', [GeographyController::class, 'updatePlace'])->middleware(['identity.permission:place.manage', 'idempotent']);
        Route::post('/places/{place}/status', [GeographyController::class, 'transitionPlace'])->middleware(['identity.permission:place.approve', 'idempotent']);
        Route::post('/places/{place}/hierarchy', [GeographyController::class, 'attachParent'])->middleware(['identity.permission:place.hierarchy.manage', 'idempotent']);
        Route::post('/places/{place}/location-policies', [GeographyController::class, 'createPolicy'])->middleware(['identity.permission:place.policy.manage', 'idempotent']);
        Route::post('/location-policies/{policy}/approve', [GeographyController::class, 'approvePolicy'])->middleware(['identity.permission:place.policy.approve', 'idempotent']);
        Route::get('/routes', [GeographyController::class, 'routes'])->middleware('identity.permission:route.view');
        Route::post('/routes', [GeographyController::class, 'storeRoute'])->middleware(['identity.permission:route.manage', 'idempotent']);
        Route::get('/routes/{route}', [GeographyController::class, 'showRoute'])->middleware('identity.permission:route.view');
        Route::post('/routes/{route}/versions', [GeographyController::class, 'createRouteVersion'])->middleware(['identity.permission:route.manage', 'idempotent']);
        Route::post('/route-versions/{version}/approve', [GeographyController::class, 'approveRouteVersion'])->middleware(['identity.permission:route.approve', 'idempotent']);
        Route::get('/distance-references', [GeographyController::class, 'distanceReferences'])->middleware('identity.permission:distance.view');
        Route::post('/distance-references', [GeographyController::class, 'storeDistanceReference'])->middleware(['identity.permission:distance.manage', 'idempotent']);
        Route::get('/distance-references/{reference}', [GeographyController::class, 'showDistanceReference'])->middleware('identity.permission:distance.view');
        Route::post('/distance-references/{reference}/approve', [GeographyController::class, 'approveDistanceReference'])->middleware(['identity.permission:distance.approve', 'idempotent']);
        Route::get('/distance-matrix', [GeographyController::class, 'matrix'])->middleware('identity.permission:distance.view');
        Route::get('/operational-zones', [GeographyController::class, 'operationalZones'])->middleware('identity.permission:geography.zone.view');
        Route::post('/operational-zones', [GeographyController::class, 'storeOperationalZone'])->middleware(['identity.permission:geography.zone.manage', 'idempotent']);
        Route::get('/distance-imports', [GeographyController::class, 'importBatches'])->middleware('identity.permission:geography.import.manage');
        Route::post('/distance-imports', [GeographyController::class, 'stageImport'])->middleware(['identity.permission:geography.import.manage', 'idempotent']);
        Route::post('/distance-imports/{import}/approve', [GeographyController::class, 'approveImport'])->middleware(['identity.permission:geography.import.approve', 'idempotent']);
        Route::post('/distance-imports/{import}/rollback', [GeographyController::class, 'rollbackImport'])->middleware(['identity.permission:geography.import.approve', 'idempotent']);
        Route::get('/me/operational-geography', [GeographyController::class, 'myOperationalReference'])->middleware('identity.permission:geography.own.view');
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
