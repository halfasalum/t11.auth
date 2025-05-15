<?php

use App\Http\Controllers\Authcontroller;
use App\Http\Controllers\Branch;
use App\Http\Controllers\Company;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\Modules;
use App\Http\Controllers\Roles;
use App\Http\Controllers\SystemUsers;
use App\Http\Controllers\TestController;
use App\Http\Controllers\ZoneController;
use App\Http\Middleware\ControlAccessMiddleware;
use App\Http\Middleware\JwtMiddleware;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/* Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum'); */

Route::post("/authenticate",[Authcontroller::class,"login"]);
Route::get("/admin",[Authcontroller::class,"default_admin"]);
Route::post("/logout",[Authcontroller::class,"logout"]);

Route::get('/send-message', [TestController::class, 'sendMessage']);


Route::get("/user/roles/{id}", [SystemUsers::class, "getRolePermissons"]);


Route::middleware([JwtMiddleware::class])->group(function () {
    Route::post("/module/register", [Modules::class, "register"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::post("/company/register", [Company::class, "register"])->middleware([ControlAccessMiddleware::class . ':2']);
    Route::get("/companies", [Company::class, "list"])->middleware([ControlAccessMiddleware::class . ':2']);
    Route::post("/control/register", [Modules::class, "control_register"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::post("/module-control/register", [Modules::class, "control_register"])->middleware([ControlAccessMiddleware::class . ':11']);
    Route::get("/modules/list", [Modules::class, "listModules"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/module-controls/{id}", [Modules::class, "getControls"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/modules", [Modules::class, "getModules"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/controls", [Modules::class, "listControls"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/module/permissions", [Modules::class, "getModulesPermissions"])->middleware([ControlAccessMiddleware::class . ':3']);
    Route::get("/roles", [Roles::class, "listRoles"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::post("/roles/register", [Roles::class, "register"])->middleware([ControlAccessMiddleware::class . ':3']);
    Route::post("/role/update", [Roles::class, "updateRolePermission"])->middleware([ControlAccessMiddleware::class . ':3']);
    Route::get("/role/permissions/{id}", [Roles::class, "getRolePermissons"])->middleware([ControlAccessMiddleware::class . ':3']);
    Route::get('/users', [SystemUsers::class, 'list'])->middleware([ControlAccessMiddleware::class . ':10']);
    Route::get("/roles/user/{id}", [Roles::class, "getUserAssignedRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/role", [SystemUsers::class, "registerUserRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/register", [SystemUsers::class, "registerUser"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/users/school", [SystemUsers::class, "registerSchoolAdmin"])->middleware([ControlAccessMiddleware::class . ':1']);
    Route::get("/branches", [Branch::class, "list"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::post("/branch/register", [Branch::class, "register"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::get("/zones", [ZoneController::class, "list"])->middleware([ControlAccessMiddleware::class . ':30']);
    Route::get("/user/zones", [ZoneController::class, "getUserAssignedZones"])->middleware([ControlAccessMiddleware::class . ':12']);
    Route::post("/zone/register", [ZoneController::class, "register"])->middleware([ControlAccessMiddleware::class . ':30']);
    Route::get("/dashboard/officer", [Dashboard::class, "officer_dashboard"])->middleware([ControlAccessMiddleware::class . ':19']);
    Route::get("/dashboard/branch", [Dashboard::class, "branch_dashboard"])->middleware([ControlAccessMiddleware::class . ':20']);
    Route::get("/dashboard/manager", [Dashboard::class, "manager_dashboard"])->middleware([ControlAccessMiddleware::class . ':21']);
    Route::get("/allocation/{id}", [SystemUsers::class, "userAllocation"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::post("/allocationUpdate", [SystemUsers::class, "updateUserAllocations"])->middleware([ControlAccessMiddleware::class . ':29']);
    
});
