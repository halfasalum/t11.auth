<?php

namespace App\Http\Controllers;

use App\Models\Company as ModelsCompany;
use App\Models\Modules;
use App\Models\modules_controls;
use App\Models\role_permissions;
use App\Models\Roles;
use App\Models\User;
use App\Models\users_roles;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;

class Company extends Controller
{
    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'company_name' => 'bail|required|string|max:255',
                'company_phone' => 'bail|required|string|max:10',
                'company_email' => 'bail|required|string|max:255',
                'company_admin' => 'bail|required|string|max:255',
            ]);

            $data = [
                "company_name" => $request->company_name,
                "company_phone" => '255' . substr($request->company_phone, 1),
                "company_email" => $request->company_email,
            ];
            $company = ModelsCompany::create($data);
            $insertedId = $company->id;
            $user       = $request->company_admin;
            $password = mt_rand(10000000, 99999999);
            $phone = '255' . substr($request->company_phone, 1);

            $admin = new User();
            $admin->name = strtolower($user);
            $admin->first_name = $user;
            $admin->middle_name = $user;
            $admin->last_name = $user;
            $admin->phone = $phone; // Provide a default phone number
            $admin->user_phone = $request->company_phone; // Provide a default phone number
            $admin->super_admin = 0; // Set super_admin to true
            $admin->user_company = $insertedId; // Set user_company to 0
            $admin->password = Hash::make($password); // Hash the password 'admin'
            $admin->email = $request->company_email;    // Provide a default email
            $admin->save();
            $insertedAdminId = $admin->id;
            $roleData = [
                'role_name' => 'Company Admin',
                'company'   => $insertedId,
                'is_system_default' => true,
            ];
            $role  = Roles::create($roleData);
            $roleId = $role->id;

            // One query for every active control across every active module
            // (instead of one SELECT per module), then one bulk INSERT for
            // all of them (instead of one INSERT per permission). The old
            // nested-loop version made ~64 sequential DB round-trips here
            // alone — over a remote DB connection that's slow enough to
            // blow past PHP's 30s execution limit and hang registration.
            $controlIds = modules_controls::whereIn('module_id', Modules::where('module_status', 1)->pluck('id'))
                ->where('module_control_status', 1)
                ->pluck('id');

            if ($controlIds->isNotEmpty()) {
                $now = now();
                $permissionRows = $controlIds->map(fn ($controlId) => [
                    'role_id' => $roleId,
                    'permission_id' => $controlId,
                    'permission_status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                role_permissions::insert($permissionRows);
            }

            /*  $permissions = [7, 14, 15, 16, 17, 18, 21, 3, 10, 29, 30, 23];
            foreach ($permissions as $permission) {
                $data = [
                    'role_id' => $roleId,
                    'permission_id' => $permission,
                    'permission_status' => 1
                ];
                role_permissions::create($data);
            } */
            $data = [
                'role_id' => $roleId,
                'user_id' => $insertedAdminId,
                'user_role_status' => 1,
                'is_system_default' => true,
            ];
            users_roles::create($data);

            $notification = new Notifications();
            $message = "Habari, Username yako ni : " . $user . " na password ni : " . $password;
            $notification->sendSMS($phone, $message);


            return response()->json([
                'status' => 'success',
                'message' => 'Company created successfully',
                'module' => $company->company_name,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function list()
    {
        $companies = ModelsCompany::where('company_status', '!=', 3)
            ->select('id', 'company_name', 'company_phone', 'company_status', 'company_email', 'company_address', 'company_city', 'company_country', 'created_at')
            ->get();
        return response()->json(
            $companies
        );
    }

    /**
     * Edit a company's own details. Deliberately excludes company_status —
     * that's changed only via toggleStatus()/destroy() below, so an edit
     * can never accidentally suspend or delete a company as a side effect.
     */
    public function update(Request $request, $id)
    {
        $company = ModelsCompany::where('id', $id)->where('company_status', '!=', 3)->first();
        if (!$company) {
            return response()->json(['status' => 'error', 'message' => 'Company not found'], 404);
        }

        try {
            $validated = $request->validate([
                'company_name' => 'bail|required|string|max:255|unique:companies,company_name,' . $id,
                'company_email' => 'bail|required|email|max:255|unique:companies,company_email,' . $id,
                'company_phone' => 'bail|required|string|max:20|unique:companies,company_phone,' . $id,
                'company_address' => 'bail|nullable|string|max:255',
                'company_city' => 'bail|nullable|string|max:255',
                'company_country' => 'bail|nullable|string|max:255',
            ]);

            $company->update($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Company updated successfully',
                'data' => $company->only([
                    'id', 'company_name', 'company_phone', 'company_email',
                    'company_address', 'company_city', 'company_country',
                    'company_status', 'created_at',
                ]),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * Flip between active (1) and suspended (2). Companies have no separate
     * "suspended" status value in the shared statuses lookup — suspending a
     * company is the same as marking it inactive.
     */
    public function toggleStatus($id)
    {
        $company = ModelsCompany::where('id', $id)->where('company_status', '!=', 3)->first();
        if (!$company) {
            return response()->json(['status' => 'error', 'message' => 'Company not found'], 404);
        }

        $newStatus = $company->company_status == 1 ? 2 : 1;
        $company->update(['company_status' => $newStatus]);

        return response()->json([
            'status' => 'success',
            'message' => $newStatus == 1 ? 'Company activated' : 'Company suspended',
            'data' => ['id' => $company->id, 'company_status' => $newStatus],
        ]);
    }

    /**
     * Soft delete — sets company_status = 3, same convention as every other
     * status-column resource in this codebase (branches, zones, users). The
     * row is never actually removed.
     */
    public function destroy($id)
    {
        $company = ModelsCompany::where('id', $id)->where('company_status', '!=', 3)->first();
        if (!$company) {
            return response()->json(['status' => 'error', 'message' => 'Company not found'], 404);
        }

        $company->update(['company_status' => 3]);

        return response()->json([
            'status' => 'success',
            'message' => 'Company deleted successfully',
        ]);
    }
}
