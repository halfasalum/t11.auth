<?php

use App\Http\Controllers\Authcontroller;
use App\Http\Controllers\Branch;
use App\Http\Controllers\Company;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\LoanDisbursementController;
use App\Http\Controllers\Modules;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Roles;
use App\Http\Controllers\SystemUsers;
use App\Http\Controllers\TestController;
use App\Http\Controllers\ZoneController;
use App\Http\Middleware\CheckSubscriptionLimits;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\ControlAccessMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Models\BranchUser;
use App\Models\Company as ModelsCompany;
use App\Models\role_permissions;
use App\Models\users_roles;
use App\Models\Zone;
use App\Models\ZoneUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;

/* Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum'); */

Route::post("/authenticate",[Authcontroller::class,"login"]);
Route::get("/admin",[Authcontroller::class,"default_admin"]);
Route::get("/receipt",[Authcontroller::class,"paymentsReceipts"]);
Route::get('/payment/generatetoken', [PaymentController::class, 'generateToken']);
Route::get('/payment/gettoken', [PaymentController::class, 'getToken']);
Route::get('/loans/disburse', [LoanDisbursementController::class, 'disburse']);
Route::post("/logout",[Authcontroller::class,"logout"]);
Route::post("/refresh",[Authcontroller::class,"refresh"]);

Route::get('/send-message', [TestController::class, 'sendMessage']);


Route::get("/user/roles/{id}", [SystemUsers::class, "getRolePermissons"]);


use Illuminate\Support\Facades\Response;

Route::post('/refresh-token', function () {
    //dd(config('jwt.ttl'), gettype(config('jwt.ttl')));
    try {
        $oldToken = JWTAuth::getToken();
        $user = JWTAuth::toUser($oldToken);

        // You must manually rebuild controls, branches, zones, etc.
        // Re-run similar logic as your login method to get them
        $roles = users_roles::where(['user_id' => $user->id, 'user_role_status' => 1])->pluck('role_id');
        $controls = role_permissions::whereIn('role_id', $roles)->where('permission_status', 1)->pluck('permission_id')->unique()->values();

        $branchesData = BranchUser::where(['user_id' => $user->id, 'branch_users.status' => 1])
            ->join('branches', 'branches.id', '=', 'branch_users.branch_id')
            ->where('branches.status', 1)
            ->select('branches.id', 'branch_name')
            ->get();

        $zonesData = ZoneUser::where(['user_id' => $user->id, 'zone_users.status' => 1])
            ->join('zones', 'zones.id', '=', 'zone_users.zone_id')
            ->where('zones.status', 1)
            ->select('zones.id', 'zone_name')
            ->get();

        $company = ModelsCompany::find($user->user_company);
        $financial = (new \App\Http\Controllers\Authcontroller)->generateFinancialYearDate($company->financial_year_start);

        $branches = $branchesData->pluck('branch_name');
        $branchesId = $branchesData->pluck('id');
        $zones = $zonesData->pluck('zone_name');
        $zonesId = $zonesData->pluck('id');

        // Now create a new token with updated claims
        $newToken = JWTAuth::claims([
            'controls' => $controls,
            'user_id' => $user->id,
            'company' => $user->user_company,
            'branches' => $branches,
            'zones' => $zones,
            'branchesId' => $branchesId,
            'zonesId' => $zonesId,
            'company_phone' => $company->company_phone,
            'company_name' => $company->company_name,
            'f_start_date' => $financial['start_date'],
            'f_end_date' => $financial['end_date'],
            'name' => $user->first_name . ' - ' . $user->last_name,
        ])->fromUser($user);

        return response()->json([
            'token' => $newToken,
            //'expires_in' => JWTAuth::factory()->getTTL() * 60,
            'name' => $user->first_name . ' - ' . $user->last_name,
            'company' => $company->company_name,
            'permissions' => $controls,
            'branches' => $branches,
            'zones' => $zones,
            'branchesId' => $branchesId,
            'zonesId' => $zonesId,
        ]);
    } catch (TokenInvalidException $e) {
        return response()->json(['message' => 'Invalid token'], 401);
    } catch (Exception $e) {
        return response()->json(['message' => 'Token refresh failed'], 500);
    }
});


Route::middleware([JwtMiddleware::class, CheckSubscriptionStatus::class])->group(function () {
    Route::post("/module/register", [Modules::class, "register"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::post("/company/register", [Company::class, "register"])->middleware([ControlAccessMiddleware::class . ':2']);
    Route::get("/companies", [Company::class, "list"])->middleware([ControlAccessMiddleware::class . ':2']);
    Route::post("/control/register", [Modules::class, "control_register"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::post("/module-control/register", [Modules::class, "control_register"])->middleware([ControlAccessMiddleware::class . ':11']);
    Route::get("/modules/list", [Modules::class, "listModules"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/module-controls/{id}", [Modules::class, "getControls"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/modules", [Modules::class, "getModules"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/controls", [Modules::class, "listControls"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/module/permissions", [Modules::class, "getModulesPermissions"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::get("/roles", [Roles::class, "listRoles"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::post("/roles/register", [Roles::class, "register"])->middleware([ControlAccessMiddleware::class . ':3']);
    Route::post("/role/update", [Roles::class, "updateRolePermission"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::get("/role/permissions/{id}", [Roles::class, "getRolePermissons"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::get('/users', [SystemUsers::class, 'list'])->middleware([ControlAccessMiddleware::class . ':10']);
    Route::get("/user/details/{id}", [SystemUsers::class, "getUserDetails"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::get("/roles/user/{id}", [Roles::class, "getUserAssignedRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/role", [SystemUsers::class, "registerUserRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/updateUserPassword", [SystemUsers::class, "updateUserPassword"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/updateUserDetails", [SystemUsers::class, "updateUserDetails"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/register", [SystemUsers::class, "registerUser"])->middleware([ControlAccessMiddleware::class . ':15'])->middleware([CheckSubscriptionLimits::class . ':users']);
    Route::post("/users/school", [SystemUsers::class, "registerSchoolAdmin"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/branches", [Branch::class, "list"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::post("/branch/register", [Branch::class, "register"])->middleware([ControlAccessMiddleware::class . ':29'])->middleware([CheckSubscriptionLimits::class . ':branches']);
    Route::post("/branch/update", [Branch::class, "update"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::post("/branch/fund", [Branch::class, "fund"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::get("/zones", [ZoneController::class, "list"])->middleware([ControlAccessMiddleware::class . ':30']);
    Route::get("/user/zones", [ZoneController::class, "getUserAssignedZones"])->middleware([ControlAccessMiddleware::class . ':12']);
    Route::post("/zone/register", [ZoneController::class, "register"])->middleware([ControlAccessMiddleware::class . ':30'])->middleware([CheckSubscriptionLimits::class . ':zones']);
    Route::get("/dashboard/officer", [Dashboard::class, "officer_dashboard"])->middleware([ControlAccessMiddleware::class . ':19']);
    Route::get("/dashboard/branch", [Dashboard::class, "branch_dashboard"])->middleware([ControlAccessMiddleware::class . ':20']);
    Route::get("/dashboard/manager", [Dashboard::class, "manager_dashboard"])->middleware([ControlAccessMiddleware::class . ':21']);
    Route::get("/allocation/{id}", [SystemUsers::class, "userAllocation"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::post("/allocationUpdate", [SystemUsers::class, "updateUserAllocations"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::get("/branchDetails/{id}", [Branch::class, "branchDetails"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::get("/zoneDetails/{id}", [ZoneController::class, "zoneDetails"])->middleware([ControlAccessMiddleware::class . ':30']);
    Route::post("/zone/update", [ZoneController::class, "update"])->middleware([ControlAccessMiddleware::class . ':30']);
    
});
