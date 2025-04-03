<?php

namespace App\Http\Controllers;

use App\Models\role_permissions;
use App\Models\Roles as ModelsRoles;
use App\Models\users_roles;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

use function Laravel\Prompts\select;

class Roles extends Controller
{
    public function listRoles()
    {
        $user = JWTAuth::parseToken()->getPayload();
                $user_company = $user->get('company');
        $roles = ModelsRoles::where(["company" => $user_company,"status"=>1])
            ->select("id", "role_name", "created_at")
            ->get();
        return response()->json($roles);
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

    public function register(Request $request)
    {

        try {
            $validated = $request->validate([
                'role_name'   => 'bail|required|string|max:255',  
                'permissions' => 'required',
            ]);
            /* $role = $request->role;
            role_permissions::where('role_id', $role)
                ->update(['permission_status' => 2]); */
                $user = JWTAuth::parseToken()->getPayload();
                $user_company = $user->get('company');
                $validated['company']       = $user_company;
                $role = ModelsRoles::create($validated);
                $role_id = $role->id;
            $permissions = $request->permissions;
            if (sizeof($permissions) > 0) {
                foreach ($permissions as $permission) {
                    $data = [
                        'role_id' => $role_id,
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

    public function updateRolePermission(Request $request){
        try {
            $validated = $request->validate([
                //'role_name'   => 'bail|required|string|max:255',  
                'role_id'   => 'bail|required|integer',  
                'permissions' => 'required',
            ]);
             $role = $request->role_id;
            role_permissions::where('role_id', $role)
                ->update(['permission_status' => 3]); 
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
                'message' => $e->errors(),
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
