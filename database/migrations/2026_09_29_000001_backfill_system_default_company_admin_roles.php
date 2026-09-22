<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Retroactively flags every existing company's auto-created "Company
     * Admin" role (and the one assignment row that put it on their
     * registering admin) as is_system_default — these rows predate that
     * column, so without this they'd stay permanently (and wrongly)
     * grandfathered in as satisfying the onboarding checklist's
     * role_creation/role_assignment steps.
     *
     * The role match is exact by name+company, unambiguous. For the
     * assignment row: prefer companies.registered_by (precise — it's
     * exactly who registration assigned the role to); for the handful of
     * older companies with no registered_by on file, fall back to
     * flagging the row only when there is EXACTLY ONE active assignment
     * to that role, since with only one candidate there's nothing to
     * disambiguate. Companies with zero or multiple candidates and no
     * registered_by are left untouched rather than guessed at.
     */
    public function up(): void
    {
        $roles = DB::table('roles')->where('role_name', 'Company Admin')->where('is_system_default', 0)->get();

        foreach ($roles as $role) {
            DB::table('roles')->where('id', $role->id)->update(['is_system_default' => true]);

            $company = DB::table('companies')->where('id', $role->company)->first();
            $assignmentId = null;

            if ($company?->registered_by) {
                $assignment = DB::table('users_roles')
                    ->where('user_id', $company->registered_by)
                    ->where('role_id', $role->id)
                    ->where('user_role_status', 1)
                    ->first();

                if ($assignment) {
                    $assignmentId = $assignment->id;
                }
            }

            if (! $assignmentId) {
                $candidates = DB::table('users_roles')
                    ->where('role_id', $role->id)
                    ->where('user_role_status', 1)
                    ->get();

                if ($candidates->count() === 1) {
                    $assignmentId = $candidates->first()->id;
                }
            }

            if ($assignmentId) {
                DB::table('users_roles')->where('id', $assignmentId)->update(['is_system_default' => true]);
            }
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('role_name', 'Company Admin')->update(['is_system_default' => false]);
        DB::table('users_roles')->where('is_system_default', true)->update(['is_system_default' => false]);
    }
};
