<?php

use App\Http\Controllers\Api\V2\CustomerController;
use App\Http\Controllers\Api\V2\LoanController;
use App\Http\Controllers\Api\V2\LoanPaymentsController;
use App\Http\Controllers\Api\V2\LoansProductsController;
use App\Http\Controllers\Api\V2\PaymentsController;
use App\Http\Controllers\Api\V2\Reports\AnalyticsReportController;
use App\Http\Controllers\Api\V2\Reports\BranchReportController;
use App\Http\Controllers\Api\V2\Reports\CollectionReportController;
use App\Http\Controllers\Api\V2\Reports\ComplianceReportController;
use App\Http\Controllers\Api\V2\Reports\CreditScoreReportController;
use App\Http\Controllers\Api\V2\Reports\CustomerReportController;
use App\Http\Controllers\Api\V2\Reports\FinancialReportController;
use App\Http\Controllers\Api\V2\Reports\OperationalReportController;
use App\Http\Controllers\Api\V2\Reports\PortfolioReportController;
use App\Http\Controllers\Authcontroller;
use App\Http\Controllers\BankController;
use App\Http\Controllers\Branch;
use App\Http\Controllers\Company;
use App\Http\Controllers\Dashboard;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\Modules;
use App\Http\Controllers\Roles;
use App\Http\Controllers\SystemUsers;
use App\Http\Controllers\ZoneController;
use Illuminate\Support\Facades\Route;

use App\Http\Middleware\CheckSubscriptionLimits;
use App\Http\Middleware\CheckSubscriptionStatus;
use App\Http\Middleware\ControlAccessMiddleware;
use App\Http\Middleware\JwtMiddleware;


Route::post('/authenticate', [Authcontroller::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('jwt.auth');
Route::post('/change-password', [AuthController::class, 'changePassword'])->middleware('jwt.auth');

// ============================================
// Protected Routes (require authentication)
// ============================================

Route::middleware([JwtMiddleware::class, CheckSubscriptionStatus::class])->group(function () {

    // routes/api.php

    // routes/api.php - Add these routes

    Route::prefix('reports')->group(function () {

        // Portfolio Reports
        Route::get('/portfolio/summary', [PortfolioReportController::class, 'portfolioSummary']);
        Route::get('/portfolio/by-product', [PortfolioReportController::class, 'portfolioByProduct']);
        Route::get('/portfolio/aging', [PortfolioReportController::class, 'loanAgingReport']);

        // Financial Reports
        Route::get('/financial/income-statement', [FinancialReportController::class, 'incomeStatement']);
        Route::get('/financial/balance-sheet', [FinancialReportController::class, 'balanceSheet']);
        Route::get('/financial/cash-flow', [FinancialReportController::class, 'cashFlowStatement']);
        Route::get('/financial/account-history/{accountId}', [FinancialReportController::class, 'accountHistory']);
        Route::get('/financial/accounts/list', [FinancialReportController::class, 'listAccounts']);

        // Branch Reports
        Route::get('/branch/performance', [BranchReportController::class, 'branchPerformance']);
        Route::get('/zone/performance', [BranchReportController::class, 'zonePerformance']);
        Route::get('/branch/funds-allocation', [BranchReportController::class, 'fundsAllocation']);

        // Customer Reports
        Route::get('/customer/credit-score', [CreditScoreReportController::class, 'creditScoreReport']);
        Route::get('/customer/repayment-behavior', [CustomerReportController::class, 'repaymentBehavior']);
        Route::get('/customer/eligibility', [CustomerReportController::class, 'customerEligibility']);
        Route::get('/customer/top-borrowers', [CustomerReportController::class, 'topBorrowers']);

        // Collection Reports
        Route::get('/collection/daily', [CollectionReportController::class, 'dailyCollection']);
        Route::get('/collection/overdue', [CollectionReportController::class, 'overdueReport']);
        Route::get('/collection/reconciliation', [CollectionReportController::class, 'reconciliationReport']);

        Route::get('/collection/daily-by-officer', [CollectionReportController::class, 'dailyCollectionByOfficer']);
        Route::get('/collection/daily-by-zone', [CollectionReportController::class, 'dailyCollectionByZone']);
        Route::get('/collection/daily-by-branch', [CollectionReportController::class, 'dailyCollectionByBranch']);
        Route::get('/collection/approval-status', [CollectionReportController::class, 'collectionApprovalStatus']);
        Route::get('/collection/payment-status', [CollectionReportController::class, 'paymentSubmissionStatus']);

        // Operational Reports
        Route::get('/operations/expense-analysis', [OperationalReportController::class, 'expenseAnalysis']);
        Route::get('/operations/income-analysis', [OperationalReportController::class, 'incomeAnalysis']);
        Route::get('/operations/customer-acquisition', [OperationalReportController::class, 'customerAcquisition']);
        Route::get('/operations/loan-approval-efficiency', [OperationalReportController::class, 'loanApprovalEfficiency']);

        // Analytics Reports
        Route::get('/analytics/default-risk', [AnalyticsReportController::class, 'defaultRiskPrediction']);
        Route::get('/analytics/customer-ltv', [AnalyticsReportController::class, 'customerLTV']);
        Route::get('/analytics/seasonal-demand', [AnalyticsReportController::class, 'seasonalDemand']);
        Route::get('/analytics/portfolio-diversification', [AnalyticsReportController::class, 'portfolioDiversification']);

        // Compliance Reports
        Route::get('/compliance/interest-income', [ComplianceReportController::class, 'interestIncomeReport']);
        Route::get('/compliance/loan-loss-provision', [ComplianceReportController::class, 'loanLossProvision']);

        Route::get('/accounts/list', [FinancialReportController::class, 'listAccounts']);
    });

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
        Route::get("/loan-free", [CustomerController::class, "loanFreeCustomers"])->middleware([ControlAccessMiddleware::class . ':7']);

        Route::get('/{id}', [CustomerController::class, 'show'])->middleware(ControlAccessMiddleware::class . ':13');
        Route::put('/{id}', [CustomerController::class, 'update'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::delete('/{id}', [CustomerController::class, 'destroy'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::get('/{id}/profile', [CustomerController::class, 'profile'])->middleware(ControlAccessMiddleware::class . ':7');

        // Customer Registration
        //Route::post('/', [CustomerController::class, 'store'])->middleware(ControlAccessMiddleware::class . ':12');
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
        Route::post('/', [LoanController::class, 'store'])->middleware(ControlAccessMiddleware::class . ':12');
        Route::post('/register', [LoanController::class, 'store'])->middleware(ControlAccessMiddleware::class . ':7');
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
        Route::get('/payments/view-zone/{zone}/{date}', [LoanPaymentsController::class, 'zonePaymentsView']);
        Route::get('/payments/view/{branch}/{date}', [LoanPaymentsController::class, 'branchPaymentsView']);
        Route::get('/payments/unfilled/{zone}/{date}', [LoanPaymentsController::class, 'fetchUnfilledPayments']);
        Route::get('/payments/rejected/{zone}/{date}', [LoanPaymentsController::class, 'fetchRejectedPayments']);

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




        Route::get('/{loan}', [LoanController::class, 'show'])->middleware(ControlAccessMiddleware::class . ':7');
        Route::get('/{loan}/schedule', [LoanController::class, 'schedule'])->middleware(ControlAccessMiddleware::class . ':8');
        // Loan Approval Data - Get all information needed for approval decision
        Route::get('/{loanId}/approval-data', [LoanController::class, 'getApprovalData']);

        // Approve loan with start date
        //Route::post('/{loanId}/approve', [LoanController::class, 'approveLoan']);

        // Reject loan application
        //Route::post('/{loanId}/reject', [LoanController::class, 'rejectLoan']);
        // Write permissions

        Route::post('/{loan}/approve', [LoanController::class, 'approve'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{loan}/reject', [LoanController::class, 'reject'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::post('/{loan}/disburse', [LoanController::class, 'disburse'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::get('/{loan}/complete', [LoanController::class, 'complete'])->middleware(ControlAccessMiddleware::class . ':21');
        Route::get('/{loan}/default', [LoanController::class, 'markDefault'])->middleware(ControlAccessMiddleware::class . ':21');






        // Schedule management
        Route::get('/{loanId}/schedule', [LoanPaymentsController::class, 'loanSchedules']);
        Route::get('/deleteSchedule/{scheduleId}', [LoanController::class, 'deleteSchedule']);

        // Accounts
        Route::get('/accounts', [LoanPaymentsController::class, 'listActiveAccounts']);
    });

    // Expense Routes
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'list']);
        Route::get('/categories', [ExpenseController::class, 'listCategories']);
        Route::post('/category/register', [ExpenseController::class, 'registerCategory']);
        Route::post('/category/enable', [ExpenseController::class, 'enableCategory']);
        Route::post('/category/disable', [ExpenseController::class, 'disableCategory']);
        Route::post('/register', [ExpenseController::class, 'registerExpense']);
        Route::get('/users/dropdown', [ExpenseController::class, 'getUsersDropdown']);
    });

    /* Route::prefix('users')->group(function () {
        Route::get('/users', [SystemUsers::class, 'list'])->middleware([ControlAccessMiddleware::class . ':10']);
    }); */

    /* OLD API MIGRATION */


    Route::get("/income", [IncomeController::class, "list"])->middleware([ControlAccessMiddleware::class . ':36']);
    Route::get("/income/active-loans", [LoanController::class, "activeLoansByLoanNumber"])->middleware([ControlAccessMiddleware::class . ':36']);
    Route::get("/income/categories", [IncomeController::class, "categoryList"])->middleware([ControlAccessMiddleware::class . ':36']);
    Route::post("/income/category/register", [IncomeController::class, "registeriCategory"])->middleware([ControlAccessMiddleware::class . ':37']);
    Route::post("/income/register", [IncomeController::class, "registerIncome"])->middleware([ControlAccessMiddleware::class . ':37']);


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
    Route::get("/roles/{id}", [Roles::class, "show"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::post("/roles/register", [Roles::class, "register"])->middleware([ControlAccessMiddleware::class . ':3']);
    Route::post("/role/update", [Roles::class, "updateRolePermission"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::get("/role/permissions/{id}", [Roles::class, "getRolePermissons"])->middleware([ControlAccessMiddleware::class . ':14']);
    Route::get('/users', [SystemUsers::class, 'list'])->middleware([ControlAccessMiddleware::class . ':10']);
    Route::get("/user/details/{id}", [SystemUsers::class, "getUserDetails"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::get("/user/allocations/{id}", [SystemUsers::class, "userAllocation"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::get("/roles/user/{id}", [Roles::class, "getUserAssignedRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/role", [SystemUsers::class, "registerUserRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
    Route::post("/user/roles/update", [SystemUsers::class, "registerUserRoles"])->middleware([ControlAccessMiddleware::class . ':15']);
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
    Route::post("/user/allocations/update", [SystemUsers::class, "updateUserAllocations"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::get("/branchDetails/{id}", [Branch::class, "branchDetails"])->middleware([ControlAccessMiddleware::class . ':29']);
    Route::get("/zoneDetails/{id}", [ZoneController::class, "zoneDetails"])->middleware([ControlAccessMiddleware::class . ':30']);
    Route::post("/zone/update", [ZoneController::class, "update"])->middleware([ControlAccessMiddleware::class . ':30']);



    Route::get("/bank", [BankController::class, "list"])->middleware([ControlAccessMiddleware::class . ':37']);
    Route::get("/bank/active-accounts", [BankController::class, "listActiveAccounts"])->middleware([ControlAccessMiddleware::class . ':9']);
    Route::post("/bank/register", [BankController::class, "registerAccount"])->middleware([ControlAccessMiddleware::class . ':37']);
    Route::get("/bank/parent-accounts", [BankController::class, "getParentAccounts"])->middleware([ControlAccessMiddleware::class . ':37']);
    Route::put('/bank/accounts/{id}', [BankController::class, 'updateAccount'])->middleware([ControlAccessMiddleware::class . ':37']);
});
