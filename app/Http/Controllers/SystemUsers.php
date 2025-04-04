<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\users_roles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class SystemUsers extends Controller
{
    public function list()
    {
        $user = JWTAuth::parseToken()->getPayload();
        $user_company = $user->get('company');
        $users = User::where(['user_company' => $user_company])
            ->select('id', 'name', 'email', 'first_name', 'last_name','status','created_at','phone')
            ->where("status",'!=' ,3)
            ->get();
        return response()->json(
            $users
        );
    }

    public function getUserRoles() {}

    public function registerUserRoles(Request $request)
    {

        try {
            $request->validate([
                'roles' => 'required',
            ]);
            $user = JWTAuth::parseToken()->getPayload();
                $user_id = $user->get('user_id');
            users_roles::where('user_id', $user_id)
                ->update(['user_role_status' => 3]);
            $roles = $request->roles;
            if (sizeof($roles) > 0) {
                foreach ($roles as $role) {
                    $data = [
                        'role_id' => $role,
                        'user_id' => $user_id,
                        'user_role_status' => 1
                    ];
                    users_roles::create($data);
                }
            }
            return response()->json([
                'status' => 'success',
                'message' => 'User role is successfully updated',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function registerSchoolAdmin(Request $request)
    {
        try {
            $validated = $request->validate([
                'first_name' => 'bail|required|string|max:255',
                'middle_name' => 'bail|required|string|max:255',
                'last_name' => 'bail|required|string|max:255',
                'email' => 'required|email',
                'phone' => 'required',
                'user_school' => 'required',
                'birth_date' => 'required',
            ]);
            $validated['name'] = strtolower($validated['first_name'] . '.' . $validated['last_name']);
            $validated['password'] = Hash::make('password');
            $validated['user_type'] = 1;
            User::create($validated);

            return response()->json([
                'status' => 'success',
                'message' => 'System admin created successfully',
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
