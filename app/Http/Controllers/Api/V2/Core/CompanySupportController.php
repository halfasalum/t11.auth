<?php

namespace App\Http\Controllers\Api\V2\Core;

use App\Http\Controllers\Api\V2\BaseController;
use App\Models\BranchModel;
use App\Models\Company;
use App\Models\Customers;
use App\Models\Loans;
use App\Models\LoansProducts;
use App\Models\Roles;
use App\Models\User;
use App\Models\UserLog;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only "support view" into any company's data, for permission-1
 * (super admin) staff assisting a customer with a problem. Every method
 * here only ever SELECTs — there is no create/update/delete anywhere in
 * this controller, deliberately, so this can never be used to change what
 * it's inspecting.
 */
class CompanySupportController extends BaseController
{
    private function findCompanyOrFail(int $companyId)
    {
        return Company::where('id', $companyId)
            ->where('company_status', '!=', 3)
            ->select('id', 'company_name', 'company_phone', 'company_email', 'company_status', 'company_address', 'company_city', 'company_country', 'financial_year_start', 'created_at')
            ->first();
    }

    private function perPage(Request $request): int
    {
        return min(100, max(1, (int) $request->input('per_page', 20)));
    }

    public function overview(int $companyId)
    {
        $company = $this->findCompanyOrFail($companyId);
        if (! $company) {
            return $this->errorResponse('Company not found', 404);
        }

        return $this->successResponse([
            'company' => $company,
            'counts' => [
                'customers' => Customers::whereHas('zoneAssignment', fn ($q) => $q->where('company_id', $companyId)->where('status', '!=', 3))->count(),
                'users' => User::where('user_company', $companyId)->where('status', '!=', 3)->count(),
                'roles' => Roles::where('company', $companyId)->where('status', 1)->count(),
                'loans' => Loans::where('company', $companyId)->where('status', '!=', 9)->count(),
                'branches' => BranchModel::where('company', $companyId)->where('status', '!=', 3)->count(),
                'zones' => Zone::where('company', $companyId)->where('status', '!=', 3)->count(),
            ],
        ]);
    }

    public function customers(Request $request, int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $query = Customers::with(['zoneAssignment' => fn ($q) => $q->where('company_id', $companyId)])
            ->whereHas('zoneAssignment', fn ($q) => $q->where('company_id', $companyId)->where('status', '!=', 3));

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('fullname', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderByDesc('created_at')->paginate($this->perPage($request));

        $customers->getCollection()->transform(fn ($c) => [
            'id' => $c->id,
            'fullname' => $c->fullname,
            'phone' => $c->customer_phone ?? $c->phone,
            'email' => $c->email,
            'is_group' => (bool) $c->is_group,
            'status' => $c->zoneAssignment->status ?? null,
            'created_at' => $c->created_at,
        ]);

        return $this->successResponse($this->paginateResponse($customers));
    }

    public function users(Request $request, int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $query = User::where('user_company', $companyId)
            ->where('status', '!=', 3)
            ->select('id', 'name', 'email', 'first_name', 'last_name', 'phone', 'user_phone', 'status', 'created_at', 'last_login_at');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('created_at')->paginate($this->perPage($request));

        return $this->successResponse($this->paginateResponse($users));
    }

    public function roles(int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $roles = Roles::where('company', $companyId)
            ->where('status', 1)
            ->select('id', 'role_name', 'is_system_default', 'created_at')
            ->orderBy('role_name')
            ->get();

        $assignedCounts = DB::table('users_roles')
            ->whereIn('role_id', $roles->pluck('id'))
            ->where('user_role_status', 1)
            ->select('role_id', DB::raw('count(*) as cnt'))
            ->groupBy('role_id')
            ->pluck('cnt', 'role_id');

        $roles->each(function ($role) use ($assignedCounts) {
            $role->assigned_users_count = $assignedCounts[$role->id] ?? 0;
        });

        return $this->successResponse($roles);
    }

    public function loans(Request $request, int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $query = Loans::with(['loan_customer', 'loan_zone', 'loan_product'])
            ->where('company', $companyId)
            ->where('status', '!=', 9);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'like', "%{$search}%")
                    ->orWhereHas('loan_customer', fn ($cq) => $cq->where('fullname', 'like', "%{$search}%"));
            });
        }

        $loans = $query->orderByDesc('created_at')->paginate($this->perPage($request));

        $loans->getCollection()->transform(fn ($loan) => [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'customer' => $loan->loan_customer->fullname ?? null,
            'principal_amount' => $this->parseNumber($loan->principal_amount),
            'total_loan' => $this->parseNumber($loan->total_loan),
            'loan_paid' => $this->parseNumber($loan->loan_paid ?? 0),
            'status' => $loan->status,
            'start_date' => $loan->start_date,
            'end_date' => $loan->end_date,
            'created_at' => $loan->created_at,
        ]);

        return $this->successResponse($this->paginateResponse($loans));
    }

    public function branches(int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $branches = BranchModel::where('company', $companyId)
            ->where('status', '!=', 3)
            ->select('id', 'branch_name', 'balance', 'status', 'created_at')
            ->get();

        return $this->successResponse($branches);
    }

    public function zones(int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $zones = Zone::where('zones.company', $companyId)
            ->where('zones.status', '!=', 3)
            ->join('branches', 'branches.id', '=', 'zones.branch')
            ->select('zones.id', 'zone_name', 'branch_name', 'zones.status', 'zones.created_at')
            ->get();

        return $this->successResponse($zones);
    }

    public function products(int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $products = LoansProducts::where('company', $companyId)
            ->where('status', '!=', 3)
            ->get();

        return $this->successResponse($products);
    }

    public function logs(Request $request, int $companyId)
    {
        if (! $this->findCompanyOrFail($companyId)) {
            return $this->errorResponse('Company not found', 404);
        }

        $query = UserLog::query()
            ->where('company', $companyId)
            ->with(['user:id,name,email,first_name,last_name,phone']);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('action', 'like', "%{$search}%")
                    ->orWhere('route', 'like', "%{$search}%");
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        $logs = $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request));

        return $this->successResponse($this->paginateResponse($logs));
    }
}
