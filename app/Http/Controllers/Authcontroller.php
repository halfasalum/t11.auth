<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\role_permissions;
use App\Models\User;
use App\Models\users_roles;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;

class Authcontroller extends Controller
{
    public function login(Request $request)
    {
        $username = $request->username;
        $password = $request->password;
        $controls = [];
        $user = User::where('name', $username)->first();
        if ($user && Hash::check($password, $user->password)) {
            // Generate a JWT token for the authenticated user
            $roles = users_roles::where(['user_id' => $user->id, 'user_role_status' => 1])
                ->select('role_id')
                ->get();
            if (sizeof($roles) > 0) {
                foreach ($roles as $role) {
                    $role_permissions = role_permissions::where(['role_id' => $role->role_id, 'permission_status' => 1])
                        ->select('permission_id')
                        ->get();
                    if (sizeof($role_permissions) > 0) {
                        foreach ($role_permissions as $permission) {
                            $controls[] = $permission->permission_id;
                        }
                    }
                }
            }
            $company = Company::where('id',$user->user_company)->first();
            //$token = JWTAuth::fromUser($user, ['controls' => $controls]);
            $token = JWTAuth::claims(['controls' => $controls,'user_id'=>$user->id,'company' => $user->user_company])->fromUser($user);
            return response()->json([
                'token'     => $token,
                'name'  => $user->first_name  . " - " . $user->last_name,
                //'company_id' => $user->user_company,
                'company' => $company->company_name,
                'success'   => true,
                'permissions' => $controls
            ]);
        } else {
            return response()->json([
                'token' => null,
                'name' => $username,
                'success' => false,
                'permissions' => $controls,
                'message' => 'Invalid credentials',
            ],401);
        }
    }

    public function default_admin()
    {

        $user = User::where('name', 'admin')->first();
        if (!$user) {
            // Create the default admin user
            $admin = new User();
            $admin->name = 'admin';
            $admin->first_name = 'admin';
            $admin->middle_name = 'admin';
            $admin->last_name = 'admin';
            $admin->phone = '255657183285'; // Provide a default phone number
            $admin->super_admin = 1; // Set super_admin to true
            $admin->user_company = 0; // Set user_company to 0
            $admin->password = Hash::make('admin'); // Hash the password 'admin'
            $admin->email = 'ahbabrasul@icloud.com';    // Provide a default email
            $admin->save();

            return response()->json([
                'success' => true,
                'message' => 'Default admin account created successfully.',
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Admin account already exists.',
            ]);
        }
    }

    public function logout()
    {
        try {
            // Invalidate the token
            JWTAuth::invalidate(JWTAuth::getToken());

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout, please try again. : ' . $e,
            ], 500);
        }
    }
}
