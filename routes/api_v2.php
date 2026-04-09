<?php

use App\Http\Controllers\Api\V2\CustomerController;
use App\Http\Controllers\Api\V2\LoanController;
use Illuminate\Support\Facades\Route;

use App\Http\Middleware\CheckSubscriptionLimits;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\ControlAccessMiddleware;
use App\Http\Middleware\JwtMiddleware;


// ============================================
// Protected Routes (require authentication)
// ============================================

Route::middleware([JwtMiddleware::class, CheckSubscriptionStatus::class])->group(function () {
    Route::prefix('customers')->group(function () {
        // Customer Management
        Route::get('/', [CustomerController::class, 'index'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/stats', [CustomerController::class, 'stats'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::post("/register", [CustomerController::class, "store"])->middleware([ControlAccessMiddleware::class . ':12']);
        Route::post("/register-group", [CustomerController::class, "registerGroup"])->middleware([ControlAccessMiddleware::class . ':12']);
        Route::post("/referee/register", [CustomerController::class, "registerReferee"])->middleware([ControlAccessMiddleware::class . ':12']);
        Route::post("/upload", [CustomerController::class, "registerAttachments"])->middleware([ControlAccessMiddleware::class . ':12']);
        Route::post("/collateral", [CustomerController::class, "registerCollateral"])->middleware([ControlAccessMiddleware::class . ':12']);
        Route::post("/submit", [CustomerController::class, "customerSubmit"])->middleware([ControlAccessMiddleware::class . ':12']);

        Route::get('/{id}', [CustomerController::class, 'show'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/{id}/profile', [CustomerController::class, 'profile'])->middleware(ControlAccessMiddleware::class . ':7');

        // Customer Registration
        Route::post('/', [CustomerController::class, 'store'])->middleware(ControlAccessMiddleware::class . ':12');
        Route::post('/groups', [CustomerController::class, 'storeGroup'])->middleware(ControlAccessMiddleware::class . ':12');

        // Customer Actions
        Route::post('/{id}/approve', [CustomerController::class, 'approve'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{id}/reject', [CustomerController::class, 'reject'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{id}/delete', [CustomerController::class, 'delete'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{id}/submit', [CustomerController::class, 'submit'])->middleware(ControlAccessMiddleware::class . ':12');
        Route::post('/{id}/finalize', [CustomerController::class, 'finalize'])->middleware(ControlAccessMiddleware::class . ':19,20');

        // Referee Management
        Route::post('/{id}/referees', [CustomerController::class, 'storeReferee'])->middleware(ControlAccessMiddleware::class . ':12');

        // Attachments & Collaterals
        Route::post('/{id}/attachments', [CustomerController::class, 'storeAttachment'])->middleware(ControlAccessMiddleware::class . ':12');
        Route::post('/{id}/collaterals', [CustomerController::class, 'storeCollateral'])->middleware(ControlAccessMiddleware::class . ':12');

        // Bulk Operations
        Route::post('/bulk/approve', [CustomerController::class, 'bulkApprove'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/bulk/reject', [CustomerController::class, 'bulkReject'])->middleware(ControlAccessMiddleware::class . ':21');
    });

    // Loan Routes
    Route::prefix('loans')->group(function () {
        // Basic view permissions
        Route::get('/', [LoanController::class, 'index'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/stats', [LoanController::class, 'stats'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/active', [LoanController::class, 'active'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/pending', [LoanController::class, 'pending'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/completed', [LoanController::class, 'completed'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/overdue', [LoanController::class, 'overdue'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/defaulted', [LoanController::class, 'defaulted'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::post('/calculate-schedule', [LoanController::class, 'calculateLoanSchedule']);
        Route::get('/{loan}', [LoanController::class, 'show'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/{loan}/schedule', [LoanController::class, 'schedule'])->middleware(ControlAccessMiddleware::class . ':8');
        // Loan Approval Data - Get all information needed for approval decision
        Route::get('/{loanId}/approval-data', [LoanController::class, 'getApprovalData']);

        // Approve loan with start date
        Route::post('/{loanId}/approve', [LoanController::class, 'approveLoan']);

        // Reject loan application
        Route::post('/{loanId}/reject', [LoanController::class, 'rejectLoan']);
        // Write permissions
        Route::post('/', [LoanController::class, 'store'])->middleware(ControlAccessMiddleware::class . ':12');
        Route::post('/{loan}/approve', [LoanController::class, 'approve'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{loan}/reject', [LoanController::class, 'reject'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{loan}/disburse', [LoanController::class, 'disburse'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{loan}/complete', [LoanController::class, 'complete'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{loan}/default', [LoanController::class, 'markDefault'])->middleware(ControlAccessMiddleware::class . ':21');

        // Product management
        Route::get('/products', [LoanController::class, 'products'])->middleware(ControlAccessMiddleware::class . ':18');
        Route::post('/products', [LoanController::class, 'storeProduct'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::put('/products/{product}', [LoanController::class, 'updateProduct'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::post('/products/{product}/enable', [LoanController::class, 'enableProduct'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::post('/products/{product}/disable', [LoanController::class, 'disableProduct'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::get('/products/{product}/details/{customer}', [LoanController::class, 'productDetails'])->middleware(ControlAccessMiddleware::class . ':7');
    });

    
});
