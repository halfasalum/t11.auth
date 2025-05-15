<?php

namespace App\Http\Controllers;

use App\Models\BranchUser;
use App\Models\Company;
use App\Models\role_permissions;
use App\Models\User;
use App\Models\users_roles;
use App\Models\ZoneUser;
use App\Services\UserLogService;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Exception;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Illuminate\Support\Facades\Log;

class Authcontroller extends Controller
{
    public function login(Request $request, UserLogService $userLogService)
    {
        $username = $request->username;
        $password = $request->password;
        $controls = [];
        $branches = [];
        $branchesId = [];
        $zones = [];
        $zonesId = [];
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
            $branchesData = BranchUser::where(['user_id' => $user->id, 'branch_users.status' => 1])
                ->select('branches.id','branch_name')
                ->join('branches', 'branches.id', '=', 'branch_users.branch_id')
                ->where('branches.status', 1)
                ->get();
            if (sizeof($branchesData) > 0) {
                foreach ($branchesData as $branch) {
                    $branches[] = $branch->branch_name;
                    $branchesId[] = $branch->id;
                }
            }
            $zonesData = ZoneUser::where(['user_id' => $user->id, 'zone_users.status' => 1])
                ->select('zones.id','zone_name')
                ->join('zones', 'zones.id', '=', 'zone_users.zone_id')
                ->where('zones.status', 1)
                ->get();
            if (sizeof($zonesData) > 0) {
                foreach ($zonesData as $zone) {
                    $zones[] = $zone->zone_name;
                    $zonesId[] = $zone->id;
                }
            }
            $company = Company::where('id',$user->user_company)->first();
            //$token = JWTAuth::fromUser($user, ['controls' => $controls]);
            $token = JWTAuth::claims(['controls' => $controls,'user_id'=>$user->id,'company' => $user->user_company,'branches'=>$branches,'zones'=>$zones,'branchesId'=>$branchesId,'zonesId'=>$zonesId,'company_phone'=>$company->company_phone,"company_name"=>$company->company_name])->fromUser($user); 
            $userLogService->log('login',null,$user->id,$user->user_company);
            return response()->json([
                'token'     => $token,
                'name'  => $user->first_name  . " - " . $user->last_name,
                'company' => $company->company_name,
                'success'   => true,
                'permissions' => $controls,
                'branches' => $branches,
                'zones' => $zones,
                'branchesId' => $branchesId,
                'zonesId' => $zonesId,
                'message' => 'Login successful',
            ]);
        } else {
            return response()->json([
                'token' => null,
                'name' => $username,
                'success' => false,
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

    public function logout(UserLogService $userLogService)
    {
        try {
            // Invalidate the token
            $token = JWTAuth::getToken();
            if ($token) {
                try {
                    $userLogService->log('logout');
                    JWTAuth::invalidate($token);
                } catch (TokenExpiredException $e) {
                    // Token is already expired, consider it a successful logout
                }
            } else {
                return response()->json([
                    'success' => true,
                    'message' => 'Token not provided',
                ]);
            }

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
