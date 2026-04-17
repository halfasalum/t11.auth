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
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use DateTimeImmutable;
use Tymon\JWTAuth\Exceptions\JWTException;

class Authcontroller extends Controller
{
    public function login(Request $request, UserLogService $userLogService)
    {
        try {
            $username = $request->username;
            $password = $request->password;
            $language = $request->language ?? 'en';
            $controls = [];
            $unique_controls = [];
            $branches = [];
            $branchesId = [];
            $zones = [];
            $zonesId = [];
            
            $user = User::where('name', $username)->first();
            
            if ($user && Hash::check($password, $user->password)) {
                // Check if password needs change
                $needsPasswordChange = false;
                if ($user->password_changed_at === null) {
                    $needsPasswordChange = true;
                } elseif ($user->password_expiry_date && now()->gt($user->password_expiry_date)) {
                    $needsPasswordChange = true;
                }
                
                // Generate refresh token
                $refreshToken = bin2hex(random_bytes(32));
                $refreshTokenExpiry = now()->addDays(30);
                
                $user->refresh_token = $refreshToken;
                $user->refresh_token_expiry = $refreshTokenExpiry;
                $user->save();
                
                // Get roles and permissions
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
                            }
                        }
                    }
                }
                
                // Get branches
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
                
                // Get zones
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
                
                // Generate JWT token
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
                    'name'  => $user->first_name . " - " . $user->last_name,
                ])->fromUser($user);

                $userLogService->log('login', null, $user->id, $user->user_company);
                
                return response()->json([
                    'token' => $token,
                    'refresh_token' => $refreshToken,
                    'name' => $user->first_name . " - " . $user->last_name,
                    'company' => $company->company_name,
                    'success' => true,
                    'permissions' => $controls,
                    'branches' => $branches,
                    'zones' => $zones,
                    'branchesId' => $branchesId,
                    'zonesId' => $zonesId,
                    'message' => 'Login successful',
                    'needs_password_change' => $needsPasswordChange,
                    'expires_in' => config('jwt.ttl') * 60,
                ]);
            } else {
                return response()->json([
                    'token' => null,
                    'name' => $username,
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }
        } catch (Exception $e) {
            Log::error('Login error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function refresh(Request $request)
    {
        try {
            Log::info('=== REFRESH TOKEN START ===');
            
            // Try to get refresh token from request
            $refreshToken = $request->refresh_token ?? $request->header('X-Refresh-Token');
            
            if (!$refreshToken) {
                // Fallback to old method - try to refresh JWT directly
                try {
                    $newToken = JWTAuth::refresh();
                    $user = JWTAuth::setToken($newToken)->authenticate();
                } catch (\Exception $e) {
                    Log::error('No refresh token provided and JWT refresh failed: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Refresh token required',
                    ], 401);
                }
            } else {
                // Find user by refresh token
                $user = User::where('refresh_token', $refreshToken)
                    ->where('refresh_token_expiry', '>', now())
                    ->first();
                
                if (!$user) {
                    Log::error('Invalid or expired refresh token');
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid or expired refresh token',
                    ], 401);
                }
                
                // Generate new JWT token
                $newToken = JWTAuth::fromUser($user);
                
                // Generate new refresh token
                $newRefreshToken = bin2hex(random_bytes(32));
                $user->refresh_token = $newRefreshToken;
                $user->refresh_token_expiry = now()->addDays(30);
                $user->save();
                
                Log::info('Token refreshed successfully for user: ' . $user->id);
                
                // Return new refresh token as well
                $responseData = [
                    'refresh_token' => $newRefreshToken,
                ];
            }
            
            // Fetch user permissions, branches, and company details
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

            Log::info('=== REFRESH TOKEN SUCCESS ===');

            $response = [
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
                'expires_in' => config('jwt.ttl') * 60,
            ];
            
            if (isset($responseData)) {
                $response = array_merge($response, $responseData);
            }
            
            return response()->json($response);
            
        } catch (TokenExpiredException $e) {
            Log::error('REFRESH ERROR - TokenExpiredException: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Token has expired. Please login again.',
                'code' => 'token_expired'
            ], 401);
        } catch (TokenInvalidException $e) {
            Log::error('REFRESH ERROR - TokenInvalidException: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Token is invalid. Please login again.',
                'code' => 'token_invalid'
            ], 401);
        } catch (JWTException $e) {
            Log::error('REFRESH ERROR - JWTException: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Could not refresh token: ' . $e->getMessage(),
                'code' => 'jwt_exception'
            ], 401);
        } catch (Exception $e) {
            Log::error('REFRESH ERROR - General Exception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh token: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function logout(UserLogService $userLogService)
    {
        try {
            $token = JWTAuth::getToken();
            if ($token) {
                try {
                    $userLogService->log('logout');
                    JWTAuth::invalidate($token);
                } catch (TokenExpiredException $e) {
                    // Token already expired, nothing to invalidate
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function changePassword(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:8|confirmed',
            ]);
            
            $user = JWTAuth::parseToken()->authenticate();
            
            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Current password is incorrect'
                ], 422);
            }
            
            $user->password = Hash::make($validated['new_password']);
            $user->password_changed_at = now();
            $user->password_expiry_date = now()->addDays(90);
            $user->save();
            
            // Generate new token
            $newToken = JWTAuth::fromUser($user);
            
            return response()->json([
                'success' => true,
                'message' => 'Password changed successfully',
                'token' => $newToken
            ]);
            
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change password: ' . $e->getMessage()
            ], 500);
        }
    }

    public function generateFinancialYearDate(int $startMonth = 1): array
    {
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException('Start month must be between 1 and 12');
        }

        $today = new DateTimeImmutable();
        $currentYear = (int)$today->format('Y');
        $currentMonth = (int)$today->format('n');

        if ($currentMonth < $startMonth) {
            $startYear = $currentYear - 1;
            $endYear = $currentYear;
        } else {
            $startYear = $currentYear;
            $endYear = $currentYear + 1;
        }

        try {
            $startDate = new DateTimeImmutable(sprintf('%d-%02d-01', $startYear, $startMonth));
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

    public function default_admin()
    {
        $user = User::where('name', 'admin')->first();
        if (!$user) {
            $admin = new User();
            $admin->name = 'admin';
            $admin->first_name = 'admin';
            $admin->middle_name = 'admin';
            $admin->last_name = 'admin';
            $admin->phone = '255657183285';
            $admin->super_admin = 1;
            $admin->user_company = 0;
            $admin->password = Hash::make('admin');
            $admin->email = 'ahbabrasul@icloud.com';
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
}