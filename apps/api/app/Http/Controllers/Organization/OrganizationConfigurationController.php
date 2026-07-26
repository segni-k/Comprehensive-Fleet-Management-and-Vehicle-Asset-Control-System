<?php

namespace App\Http\Controllers\Organization;

use App\Exceptions\ConflictException;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationContact;
use App\Organization\Models\OrganizationManagerAssignment;
use App\Organization\Models\OrganizationSettingDefinition;
use App\Organization\Models\OrganizationSettingValue;
use App\Organization\Services\HierarchyService;
use App\Organization\Services\OrganizationAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrganizationConfigurationController extends OrganizationApiController
{
    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly OrganizationAuditService $audit,
    ) {}

    public function contacts(Request $request, Organization $organization): JsonResponse
    {
        return $this->respond($request, OrganizationContact::query()->where('organization_id', $organization->id)->orderBy('contact_type')->get());
    }

    public function storeContact(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'contact_type' => ['required', 'string', 'max:50'],
            'value' => ['required', 'string', 'max:500'],
            'is_primary' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertContactDoesNotOverlap(
            $organization->id,
            $validated['contact_type'],
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
            (bool) ($validated['is_primary'] ?? false),
        );
        $contact = OrganizationContact::query()->create(['organization_id' => $organization->id, ...$validated, 'status' => 'active']);
        $contact->refresh();
        $this->record($request, 'organization.contact.created', $contact, $organization->id, 'Contact created');

        return $this->respond($request, $contact, 201);
    }

    public function showContact(Request $request, Organization $organization, OrganizationContact $contact): JsonResponse
    {
        $this->assertOwner($contact, $organization);

        return $this->respond($request, $contact);
    }

    public function updateContact(Request $request, Organization $organization, OrganizationContact $contact): JsonResponse
    {
        $this->assertOwner($contact, $organization);
        $validated = $request->validate([
            'value' => ['sometimes', 'string', 'max:500'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        return $this->updateVersioned($request, $contact, $validated, 'organization.contact.updated', $organization->id);
    }

    public function endContact(Request $request, Organization $organization, OrganizationContact $contact): JsonResponse
    {
        $this->assertOwner($contact, $organization);
        $validated = $request->validate([
            'effective_to' => ['required', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return $this->updateVersioned($request, $contact, ['effective_to' => $validated['effective_to'], 'status' => 'inactive'], 'organization.contact.ended', $organization->id, $validated['reason']);
    }

    public function managers(Request $request, Organization $organization): JsonResponse
    {
        return $this->respond($request, OrganizationManagerAssignment::query()->where('organization_id', $organization->id)->orderBy('effective_from')->get());
    }

    public function storeManager(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'string', 'size:26'],
            'responsibility_code' => ['required', 'string', 'max:80', 'exists:organization_manager_responsibilities,code'],
            'appointing_authority' => ['required', 'string', 'max:500'],
            'approval_reference' => ['nullable', 'string', 'max:190'],
            'delegation_restricted' => ['sometimes', 'boolean'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $responsibilityId = DB::table('organization_manager_responsibilities')->where('code', $validated['responsibility_code'])->value('id');
        unset($validated['responsibility_code']);
        $this->assertManagerDoesNotOverlap(
            $organization->id,
            (string) $responsibilityId,
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
        );
        $assignment = OrganizationManagerAssignment::query()->create([
            'organization_id' => $organization->id,
            'responsibility_id' => $responsibilityId,
            ...$validated,
            'status' => 'pending',
        ]);
        $assignment->refresh();
        $this->record($request, 'organization.manager.assigned', $assignment, $organization->id, 'Manager assignment created');

        return $this->respond($request, $assignment, 201);
    }

    public function showManager(Request $request, Organization $organization, OrganizationManagerAssignment $manager): JsonResponse
    {
        $this->assertOwner($manager, $organization);

        return $this->respond($request, $manager);
    }

    public function updateManager(Request $request, Organization $organization, OrganizationManagerAssignment $manager): JsonResponse
    {
        $this->assertOwner($manager, $organization);
        $validated = $request->validate([
            'approval_reference' => ['nullable', 'string', 'max:190'],
            'delegation_restricted' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:pending,active,ended'],
        ]);

        return $this->updateVersioned($request, $manager, $validated, 'organization.manager.updated', $organization->id);
    }

    public function endManager(Request $request, Organization $organization, OrganizationManagerAssignment $manager): JsonResponse
    {
        $this->assertOwner($manager, $organization);
        $validated = $request->validate([
            'effective_to' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return $this->updateVersioned($request, $manager, ['effective_to' => $validated['effective_to'], 'status' => 'ended'], 'organization.manager.ended', $organization->id, $validated['reason']);
    }

    public function settings(Request $request, Organization $organization): JsonResponse
    {
        return $this->respond($request, OrganizationSettingValue::query()->where('organization_id', $organization->id)->orderBy('effective_from')->get());
    }

    public function storeSetting(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'setting_definition_id' => ['required', 'string', 'size:26', 'exists:organization_setting_definitions,id'],
            'value' => ['present'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertSettingDoesNotOverlap($organization->id, $validated['setting_definition_id'], $validated['effective_from'], $validated['effective_to'] ?? null);
        $setting = OrganizationSettingValue::query()->create(['organization_id' => $organization->id, ...$validated]);
        $setting->refresh();
        $this->record($request, 'organization.setting.overridden', $setting, $organization->id, 'Local setting override created');

        return $this->respond($request, $setting, 201);
    }

    public function updateSetting(Request $request, Organization $organization, OrganizationSettingValue $setting): JsonResponse
    {
        $this->assertOwner($setting, $organization);
        $validated = $request->validate([
            'value' => ['present'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ]);
        $this->assertSettingDoesNotOverlap(
            $organization->id,
            $setting->setting_definition_id,
            $validated['effective_from'],
            $validated['effective_to'] ?? null,
            $setting->id,
        );

        return $this->updateVersioned($request, $setting, $validated, 'organization.setting.updated', $organization->id);
    }

    public function removeSetting(Request $request, Organization $organization, OrganizationSettingValue $setting): JsonResponse
    {
        $this->assertOwner($setting, $organization);
        $validated = $request->validate([
            'effective_to' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return $this->updateVersioned($request, $setting, ['effective_to' => $validated['effective_to']], 'organization.setting.removed', $organization->id, $validated['reason']);
    }

    public function effectiveSettings(Request $request, Organization $organization): JsonResponse
    {
        $at = CarbonImmutable::parse($request->input('as_of', now()));
        $settings = OrganizationSettingDefinition::query()->where('status', 'active')->get()
            ->map(fn (OrganizationSettingDefinition $definition) => $this->hierarchy->effectiveSetting($organization->id, $definition->id, $at))
            ->filter()->values();

        return $this->respond($request, $settings);
    }

    public function settingInheritance(Request $request, Organization $organization): JsonResponse
    {
        $at = CarbonImmutable::parse($request->input('as_of', now()));
        $pathIds = array_merge([$organization->id], $this->hierarchy->ancestorIds($organization->id, $at));
        $path = Organization::query()->whereIn('id', $pathIds)->get();
        $data = OrganizationSettingDefinition::query()->where('status', 'active')->get()->map(fn (OrganizationSettingDefinition $definition): array => [
            'setting_definition_id' => $definition->id,
            'path' => $path,
            'effective_value' => $this->hierarchy->effectiveSetting($organization->id, $definition->id, $at),
        ]);

        return $this->respond($request, $data);
    }

    public function history(Request $request, Organization $organization, string $subject): JsonResponse
    {
        return $this->respond($request, DB::table('organization_hierarchy_change_history')
            ->where('organization_id', $organization->id)
            ->where('subject_type', $subject)
            ->orderByDesc('occurred_at')
            ->get());
    }

    public function contactHistory(Request $request, Organization $organization): JsonResponse
    {
        return $this->history($request, $organization, 'organization_contacts');
    }

    public function managerHistory(Request $request, Organization $organization): JsonResponse
    {
        return $this->history($request, $organization, 'organization_manager_assignments');
    }

    public function settingHistory(Request $request, Organization $organization): JsonResponse
    {
        return $this->history($request, $organization, 'organization_setting_values');
    }

    /** @param array<string, mixed> $values */
    private function updateVersioned(Request $request, Model $model, array $values, string $event, string $organizationId, string $reason = 'Configuration updated'): JsonResponse
    {
        $expected = $this->expectedVersion($request);
        $before = $model->toArray();
        $updated = $model->newQuery()->whereKey($model->getKey())->where('record_version', $expected)->update([
            ...$values,
            'record_version' => $expected + 1,
        ]);
        if ($updated !== 1) {
            throw new ConflictException('STALE_RECORD_VERSION', 'Configuration record version is stale');
        }
        $model->refresh();
        $this->record($request, $event, $model, $organizationId, $reason, $before);

        return $this->respond($request, $model);
    }

    /** @param array<string, mixed>|null $before */
    private function record(Request $request, string $event, Model $model, string $organizationId, string $reason, ?array $before = null): void
    {
        $this->audit->record($event, $model->getTable(), (string) $model->getKey(), $this->actor($request), $organizationId, $reason, $before, $model->toArray(), $request->attributes->get('correlation_id'));
    }

    private function assertOwner(Model $model, Organization $organization): void
    {
        if ((string) $model->getAttribute('organization_id') !== $organization->id) {
            abort(404);
        }
    }

    private function assertSettingDoesNotOverlap(string $organizationId, string $definitionId, string $start, ?string $end, ?string $excludeId = null): void
    {
        $overlap = OrganizationSettingValue::query()
            ->where('organization_id', $organizationId)
            ->where('setting_definition_id', $definitionId)
            ->when($excludeId, fn ($query, $id) => $query->whereKeyNot($id))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $start))
            ->when($end, fn ($query, $value) => $query->where('effective_from', '<', $value))
            ->exists();
        if ($overlap) {
            throw new ConflictException('EFFECTIVE_DATE_OVERLAP', 'Organization setting overlaps an existing override');
        }
    }

    private function assertContactDoesNotOverlap(
        string $organizationId,
        string $contactType,
        string $start,
        ?string $end,
        bool $primary,
    ): void {
        if (! $primary) {
            return;
        }

        $overlap = OrganizationContact::query()
            ->where('organization_id', $organizationId)
            ->where('contact_type', $contactType)
            ->where('is_primary', true)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $start))
            ->when($end, fn ($query, $value) => $query->where('effective_from', '<', $value))
            ->exists();
        if ($overlap) {
            throw new ConflictException('EFFECTIVE_DATE_OVERLAP', 'A primary contact of this type already applies during the requested period');
        }
    }

    private function assertManagerDoesNotOverlap(
        string $organizationId,
        string $responsibilityId,
        string $start,
        ?string $end,
    ): void {
        $overlap = OrganizationManagerAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('responsibility_id', $responsibilityId)
            ->whereIn('status', ['pending', 'active'])
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $start))
            ->when($end, fn ($query, $value) => $query->where('effective_from', '<', $value))
            ->exists();
        if ($overlap) {
            throw new ConflictException('EFFECTIVE_DATE_OVERLAP', 'A manager responsibility already applies during the requested period');
        }
    }
}
