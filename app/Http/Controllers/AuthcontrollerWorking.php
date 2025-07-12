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
use InvalidArgumentException;
use DateTimeImmutable;
use Tymon\JWTAuth\Exceptions\JWTException;

class Authcontroller extends Controller
{
    public function login(Request $request, UserLogService $userLogService)
    {
        $username = $request->username;
        $password = $request->password;
        $controls = [];
        $unique_controls = [];
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
                            if (!in_array($permission->permission_id, $controls)) {
                                $controls[] = $permission->permission_id;
                            }
                            //$controls[] = $permission->permission_id;
                        }
                        //$unique_controls = array_unique($controls);
                    }
                }
            }
            $branchesData = BranchUser::where(['user_id' => $user->id, 'branch_users.status' => 1])
                ->select('branches.id', 'branch_name')
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
                ->select('zones.id', 'zone_name')
                ->join('zones', 'zones.id', '=', 'zone_users.zone_id')
                ->where('zones.status', 1)
                ->get();
            if (sizeof($zonesData) > 0) {
                foreach ($zonesData as $zone) {
                    $zones[] = $zone->zone_name;
                    $zonesId[] = $zone->id;
                }
            }
            $company = Company::where('id', $user->user_company)->first();
            $financial = $this->generateFinancialYearDate($company->financial_year_start); 
            //$token = JWTAuth::fromUser($user, ['controls' => $controls]);
            $token = JWTAuth::claims([
                'controls' => $controls,
                'user_id' => $user->id,
                'company' => $user->user_company,
                'branches' => $branches,
                'zones' => $zones,
                'branchesId' => $branchesId,
                'zonesId' => $zonesId,
                'company_phone' => $company->company_phone,
                "company_name" => $company->company_name,
                "f_start_date" => $financial['start_date'],
                "f_end_date" => $financial['end_date'],
                'name'  => $user->first_name  . " - " . $user->last_name,
            ])->fromUser($user);
            $userLogService->log('login', null, $user->id, $user->user_company);
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
            ], 401);
        }
    }


public function refresh()
{
    try {
        // Attempt to refresh the token
        $newToken = JWTAuth::refresh();

        // Get the authenticated user
        $user = JWTAuth::user();

        // Fetch user permissions, branches, and company details (similar to login)
        $controls = [];
        $branches = [];
        $zones = [];
        $branchesId = [];
        $zonesId = [];

        $roles = users_roles::where(['user_id' => $user->id, 'user_role_status' => 1])
            ->select('role_id')
            ->get();

        foreach ($roles as $role) {
            $role_permissions = role_permissions::where(['role_id' => $role->role_id, 'permission_status' => 1])
                ->select('permission_id')
                ->get();
            foreach ($role_permissions as $permission) {
                if (!in_array($permission->permission_id, $controls)) {
                    $controls[] = $permission->permission_id;
                }
            }
        }

        $branchesData = BranchUser::where(['user_id' => $user->id, 'branch_users.status' => 1])
            ->select('branches.id', 'branch_name')
            ->join('branches', 'branches.id', '=', 'branch_users.branch_id')
            ->where('branches.status', '1')
            ->get();

        foreach ($branchesData as $branch) {
            $branches[] = $branch->branch_name;
            $branchesId[] = $branch->id;
        }

        $zonesData = ZoneUser::where(['user_id' => $user->id, 'zone_users.status' => 1])
            ->select('zones.id', 'zone_name')
            ->join('zones', 'zones.id', '=', 'zone_users.zone_id')
            ->where('zones.status', 1)
            ->get();

        foreach ($zonesData as $zone) {
            $zones[] = $zone->zone_name;
            $zonesId[] = $zone->id;
        }

        $company = Company::where('id', $user->user_company)->first();
        $financial = $this->generateFinancialYearDate($company->financial_year_start);

        // Return the new token with user data
        return response()->json([
            'token' => $newToken,
            'name' => $user->first_name . " - " . $user->last_name,
            'company' => $company->company_name,
            'success' => true,
            'permissions' => $controls,
            'branches' => $branches,
            'zones' => $zones,
            'branchesId' => $branchesId,
            'zonesId' => $zonesId,
            'company_phone' => $company->company_phone,
            'f_start_date' => $financial['start_date'],
            'f_end_date' => $financial['end_date'],
            'message' => 'Token refreshed successfully',
        ]);
    } catch (JWTException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Could not refresh token: ' . $e->getMessage(),
        ], 401);
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

    /* public function generateFinancialYearDate($startMonth = 1)
    {
        $today = date('Y-m-d');
        $year = date('Y');
        $month = date('n'); // Numeric representation of a month without leading zeros (1 to 12)

        if ($month < $startMonth) {
            $startYear = $year - 1;
            $endYear = $year;
        } else {
            $startYear = $year;
            $endYear = $year + 1;
        }

        $startDate = date('Y-m-d', strtotime("{$startYear}-{$startMonth}-01"));
        $endDate = date('Y-m-d', strtotime("last day of " . ($startMonth - 1 == 0 ? 12 : $startMonth - 1) . " {$endYear}"));

        return [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
    } */

    public function generateFinancialYearDate(int $startMonth = 1): array
{
    // Validate start month (1-12)
    if ($startMonth < 1 || $startMonth > 12) {
        throw new InvalidArgumentException('Start month must be between 1 and 12');
    }

    $today = new DateTimeImmutable(); // Use DateTimeImmutable for safer date handling
    $currentYear = (int)$today->format('Y');
    $currentMonth = (int)$today->format('n');

    // Determine financial year boundaries
    if ($currentMonth < $startMonth) {
        $startYear = $currentYear - 1;
        $endYear = $currentYear;
    } else {
        $startYear = $currentYear;
        $endYear = $currentYear + 1;
    }

    try {
        // Create start date (first day of start month)
        $startDate = new DateTimeImmutable(sprintf('%d-%02d-01', $startYear, $startMonth));

        // Create end date (last day of the month before start month in the next year)
        $endMonth = $startMonth - 1 > 0 ? $startMonth - 1 : 12;
        $endDate = (new DateTimeImmutable(sprintf('%d-%02d-01', $endYear, $endMonth)))
            ->modify('last day of this month');

        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'financial_year' => sprintf('%d/%d', $startYear, $endYear)
        ];
    } catch (Exception $e) {
        throw new \RuntimeException('Failed to generate financial year dates: ' . $e->getMessage());
    }
}

    public function paymentsReceipts(){

    }
}
