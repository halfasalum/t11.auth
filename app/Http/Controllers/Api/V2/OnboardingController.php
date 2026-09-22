<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Accounts;
use App\Models\BranchModel;
use App\Models\BranchUser;
use App\Models\Roles;
use App\Models\User;
use App\Models\Zone;
use App\Models\ZoneUser;
use App\Models\users_roles;

/**
 * Mandatory setup checklist, scoped by the user's permission tier. Every
 * step is computed LIVE against the real tables (does a role exist? a
 * branch? etc.) rather than a stored "completed" flag — there's one source
 * of truth, and if a company's only branch gets deleted later, the step
 * correctly goes back to incomplete instead of a stale checkmark lying
 * about it.
 *
 * Only the manager (permission 21) checklist exists so far. A user
 * without that permission gets 'applicable' => false — this feature never
 * blocks someone from a role it has no defined checklist for yet. This is
 * keyed purely on "does the user hold permission 21 among their combined
 * roles" — a manager who is ALSO e.g. an officer or incharge still gets
 * this checklist, regardless of what else they hold.
 */
class OnboardingController extends BaseController
{
    /**
     * Checklist definitions per permission id. Add more entries here
     * (e.g. 19 => [...], 20 => [...]) as checklists for officer/incharge
     * are defined — nothing else needs to change to support that.
     */
    private function checklistDefinitions(): array
    {
        return [
            21 => [
                ['key' => 'role_creation', 'route' => '/settings/role'],
                ['key' => 'user_creation', 'route' => '/users/register'],
                ['key' => 'role_assignment', 'route' => '/users'],
                ['key' => 'branch_creation', 'route' => '/settings/branch'],
                ['key' => 'zone_creation', 'route' => '/settings/zone'],
                ['key' => 'user_allocation', 'route' => '/users'],
                ['key' => 'bank_account_creation', 'route' => '/bank/register'],
            ],
        ];
    }

    public function status()
    {
        $companyId = $this->getCompanyId();
        $userId = $this->getUserId();

        $permissionId = $this->applicablePermissionId();

        if (! $permissionId) {
            return $this->successResponse([
                'applicable' => false,
                'steps' => [],
                'is_complete' => true,
            ]);
        }

        $steps = array_map(function (array $step) use ($companyId, $userId) {
            return [
                'key' => $step['key'],
                'route' => $step['route'],
                'completed' => $this->isStepComplete($step['key'], $companyId, $userId),
            ];
        }, $this->checklistDefinitions()[$permissionId]);

        return $this->successResponse([
            'applicable' => true,
            'permission_id' => $permissionId,
            'steps' => $steps,
            'is_complete' => ! in_array(false, array_column($steps, 'completed'), true),
        ]);
    }

    /**
     * The first permission tier (in definition order) this user has that
     * has a checklist defined. Only 21 exists today, so this is currently
     * just "does the user have permission 21", but stays extensible.
     */
    private function applicablePermissionId(): ?int
    {
        foreach (array_keys($this->checklistDefinitions()) as $permissionId) {
            if ($this->hasPermission($permissionId)) {
                return $permissionId;
            }
        }

        return null;
    }

    private function isStepComplete(string $key, int $companyId, int $userId): bool
    {
        $companyUserIds = fn () => User::where('user_company', $companyId)->pluck('id');

        return match ($key) {
            // Excludes the one "Company Admin" role registration auto-creates
            // for every new company — only a role the manager created
            // themselves through the Role Management screen counts.
            'role_creation' => Roles::where('company', $companyId)
                ->where('status', 1)
                ->where('is_system_default', false)
                ->exists(),

            'user_creation' => User::where('user_company', $companyId)
                ->where('id', '!=', $userId)
                ->where('status', '!=', 3)
                ->exists(),

            // Excludes registration's auto-assignment of "Company Admin" to
            // the admin user — a role_id could still be the same one if the
            // manager later assigns that very role to someone else through
            // the Assign Role screen, since only THAT ROW is flagged, not
            // every row referencing that role.
            'role_assignment' => users_roles::where('user_role_status', 1)
                ->where('is_system_default', false)
                ->whereIn('user_id', $companyUserIds())
                ->exists(),

            'branch_creation' => BranchModel::where('company', $companyId)
                ->where('status', '!=', 3)
                ->exists(),

            'zone_creation' => Zone::where('company', $companyId)
                ->where('status', '!=', 3)
                ->exists(),

            'user_allocation' => BranchUser::where('status', 1)->whereIn('user_id', $companyUserIds())->exists()
                || ZoneUser::where('status', 1)->whereIn('user_id', $companyUserIds())->exists(),

            'bank_account_creation' => Accounts::where('company_id', $companyId)
                ->where('account_status', '!=', Accounts::STATUS_DELETED)
                ->exists(),

            default => false,
        };
    }
}
