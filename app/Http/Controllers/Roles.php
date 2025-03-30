<?php

namespace App\Http\Controllers;

use App\Models\role_permissions;
use App\Models\Roles as ModelsRoles;
use App\Models\users_roles;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

use function Laravel\Prompts\select;

class Roles extends Controller
{
    public function listRoles($school = 0)
    {
        $roles = ModelsRoles::where(["school" => $school, "status" => 1])
            ->select("id", "role_name", "created_at")
            ->get();
        return response()->json($roles);
    }

    public function register(Request $request)
    {
        try {
            $validated = $request->validate([
                'role_name' => 'bail|required|string|max:255',
                'school' => 'required',
                'status' => 'required',
            ]);

            $module = ModelsRoles::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'Role created successfully',
                'module' => $module,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }
    public function getRolePermissons($role_id)
    {
        $permissions = role_permissions::where(['role_id' => $role_id, 'permission_status' => 1])
            ->select('permission_id')
            ->get();
        return response()->json(
            $permissions
        );
    }

    public function registerRolePermissions(Request $request)
    {

        try {
            $request->validate([
                'permissions' => 'required',
            ]);
            $role = $request->role;
            role_permissions::where('role_id', $role)
                ->update(['permission_status' => 2]);
            $permissions = $request->permissions;
            if (sizeof($permissions) > 0) {
                foreach ($permissions as $permission) {
                    $data = [
                        'role_id' => $role,
                        'permission_id' => $permission,
                        'permission_status' => 1
                    ];
                    role_permissions::create($data);
                }
            }
            return response()->json([
                'status' => 'success',
                'message' => 'Role permissions is successfully updated',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function getUserAssignedRoles($user)
    {
        $roles = users_roles::where(['user_id' => $user, 'user_role_status' => 1])
            ->select('role_id')
            ->get();
        return response()->json(
            $roles
        );
    }
}
