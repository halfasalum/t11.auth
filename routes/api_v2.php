<?php

use App\Http\Controllers\Api\V2\CustomerController;
use App\Http\Controllers\Api\V2\LoanController;
use App\Http\Controllers\Api\V2\LoanPaymentsController;
use App\Http\Controllers\Api\V2\LoansProductsController;
use App\Http\Controllers\Api\V2\PaymentsController;
use App\Http\Controllers\BankController;
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


        // Modular payment endpoints
        Route::get('/payments/today', [LoanPaymentsController::class, 'getTodayPaymentsData']);
        Route::get('/payments/zone-approval', [LoanPaymentsController::class, 'getZoneApprovalData']);
        Route::get('/payments/branch-approval', [LoanPaymentsController::class, 'getBranchApprovalData']);
        Route::get('/payments/previous-approvals', [LoanPaymentsController::class, 'getPreviousApprovalsData']);
        Route::get('/payments/unfilled', [LoanPaymentsController::class, 'getUnfilledPaymentsData']);
        Route::get('/payments/rejected', [LoanPaymentsController::class, 'getRejectedPaymentsData']);

        // Product management
        Route::get('/products', [LoansProductsController::class, 'list'])->middleware(ControlAccessMiddleware::class . ':18');
        Route::get('/products/active', [LoansProductsController::class, 'activeList'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::post('/products/register', [LoansProductsController::class, 'register'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::post('/products/update', [LoansProductsController::class, 'update'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::post('/products/enable', [LoansProductsController::class, 'enable'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::post('/products/disable', [LoansProductsController::class, 'disable'])->middleware(ControlAccessMiddleware::class . ':23');
        Route::get('/products/{product}/details/{customer?}', [LoansProductsController::class, 'productDetails'])->middleware(ControlAccessMiddleware::class . ':7');

        // Action endpoints
        Route::post('/submitPayment', [LoanPaymentsController::class, 'submitPayment']);
        Route::post('/approveZonePayments', [LoanPaymentsController::class, 'approveZonePayments']);
        Route::post('/rejectZonePayments', [LoanPaymentsController::class, 'rejectZonePayments']);
        Route::post('/approveBranchPayments', [LoanPaymentsController::class, 'approveBranchPayments']);
        Route::post('/rejectBranchPayments', [LoanPaymentsController::class, 'rejectBranchPayments']);



        // View endpoints (keep existing)
        Route::get('/payments/view-zone/{zone}/{date}', [LoanPaymentsController::class, 'zonePaymentsView']);
        Route::get('/payments/view/{branch}/{date}', [LoanPaymentsController::class, 'branchPaymentsView']);
        Route::get('/payments/unfilled/{zone}/{date}', [LoanPaymentsController::class, 'fetchUnfilledPayments']);
        Route::get('/payments/rejected/{zone}/{date}', [LoanPaymentsController::class, 'fetchRejectedPayments']);



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

        





        // Schedule management
        Route::get('/loans/{loanId}/schedule', [LoanPaymentsController::class, 'loanSchedules']);
        Route::delete('/loans/deleteSchedule/{scheduleId}', [LoanPaymentsController::class, 'deleteSchedule']);

        // Accounts
        Route::get('/accounts', [LoanPaymentsController::class, 'listActiveAccounts']);
    });

     Route::get("/bank", [BankController::class, "list"])->middleware([ControlAccessMiddleware::class . ':37']);
    Route::get("/bank/active-accounts", [BankController::class, "listActiveAccounts"])->middleware([ControlAccessMiddleware::class . ':9']);
    Route::post("/bank/register", [BankController::class, "registeriAccount"])->middleware([ControlAccessMiddleware::class . ':37']);
    
});
