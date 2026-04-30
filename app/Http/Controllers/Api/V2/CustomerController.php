<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Customers;
use App\Models\CustomersZone;
use App\Models\Referees;
use App\Models\CustomerReferees;
use App\Models\Attachements;
use App\Models\Collateral;
use App\Models\Zone;
use App\Http\Controllers\Controller;
use App\Models\Loans;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tymon\JWTAuth\Facades\JWTAuth;

class CustomerController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Get authenticated user payload
     */
    protected function getUserPayload()
    {
        return JWTAuth::parseToken()->getPayload();
    }

    /**
     * Get authenticated user ID
     */
    protected function getUserId()
    {
        return $this->getUserPayload()->get('user_id');
    }

    /**
     * Get authenticated user's company ID
     */
    protected function getCompanyId()
    {
        return $this->getUserPayload()->get('company');
    }

    /**
     * Get authenticated user's permissions
     */
    protected function getUserPermissions()
    {
        return $this->getUserPayload()->get('controls') ?? [];
    }

    /**
     * Check if user has a specific permission
     */
    protected function hasPermission($permissionId)
    {
        return in_array($permissionId, $this->getUserPermissions());
    }

    /**
     * Get user's assigned zones
     */
    protected function getUserZones()
    {
        return $this->getUserPayload()->get('zonesId') ?? [];
    }

    /**
     * Get user's assigned branches
     */
    protected function getUserBranches()
    {
        return $this->getUserPayload()->get('branchesId') ?? [];
    }

    /**
     * Check if user is manager (permission 21)
     */
    protected function isManager()
    {
        return $this->hasPermission(21);
    }

    /**
     * Check if user is root admin (permission 22)
     */
    protected function isRootAdmin()
    {
        return $this->hasPermission(22);
    }

    /**
     * Check if user is branch manager (permission 20)
     */
    protected function isBranchManager()
    {
        return $this->hasPermission(20);
    }

    /**
     * Check if user is zone officer (permission 19)
     */
    protected function isZoneOfficer()
    {
        return $this->hasPermission(19);
    }

    /**
     * Success response
     */
    protected function successResponse($data = null, $message = 'Success', $code = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Error response
     */
    protected function errorResponse($message, $code = 400, $errors = null)
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    /**
     * Validation error response
     */
    protected function validationErrorResponse(ValidationException $e)
    {
        return $this->errorResponse('Validation failed', 422, $e->errors());
    }

    /**
     * Paginate response
     */
    protected function paginateResponse($paginator)
    {
        return [
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    // ============================================
    // Main Methods
    // ============================================

    /**
     * Get paginated customers list with filters
     */
    public function index(Request $request)
    {
        $companyId = $this->getCompanyId();
        $userZones = $this->getUserZones();
        $userBranches = $this->getUserBranches();

        // Query customers with their zone assignment
        $query = Customers::with(['zoneAssignment' => function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
            $q->where('status', '!=', 3);
            $q->where('status', '!=', 9);
        }]);

        // Only include customers that have a zone assignment for this company
        $query->whereHas('zoneAssignment', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
            $q->where('status', '!=', 3);
        });

        // Apply zone/branch filtering based on user role
        if (!$this->isManager() && !$this->isRootAdmin()) {
            if ($this->isBranchManager() && !empty($userBranches)) {
                $query->whereHas('zoneAssignment', function ($q) use ($userBranches) {
                    $q->whereIn('branch_id', $userBranches);
                });
            } elseif ($this->isZoneOfficer() && !empty($userZones)) {
                $query->whereHas('zoneAssignment', function ($q) use ($userZones) {
                    $q->whereIn('zone_id', $userZones);
                });
            }
        }

        // Apply filters
        if ($request->has('status')) {
            $query->whereHas('zoneAssignment', function ($q) use ($request) {
                $q->where('status', $request->status);
            });
        }

        if ($request->has('type')) {
            if ($request->type === 'individual') {
                $query->where('is_group', false);
            } elseif ($request->type === 'group') {
                $query->where('is_group', true);
            }
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('fullname', 'LIKE', "%{$search}%")
                    ->orWhere('customer_phone', 'LIKE', "%{$search}%")
                    ->orWhere('phone', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('nida', 'LIKE', "%{$search}%");
            });
        }

        $customers = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        // Transform each customer to include zone status
        $customers->getCollection()->transform(function ($customer) {
            return $this->transformCustomer($customer);
        });

        return $this->successResponse($this->paginateResponse($customers));
    }

    /**
     * Get single customer with full details
     */
    public function show($id)
    {
        $companyId = $this->getCompanyId();

        $customer = Customers::with([
            'zoneAssignment' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->with('zone', 'branch');
            },
            'loans' => function ($q) {
                $q->orderBy('created_at', 'desc')->limit(5);
            },
            'referees',
            'attachments',
            'collaterals'
        ])->where('id', $id)
            ->whereHas('zoneAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->first();

        if (!$customer) {
            return $this->errorResponse('Customer not found', 404);
        }

        // Check access permission
        if (!$this->hasAccessToCustomer($customer)) {
            return $this->errorResponse('Unauthorized access', 403);
        }

        return $this->successResponse($this->transformCustomer($customer, true));
    }

    /**
     * Register new customer
     */
    public function store(Request $request)
    {
        try {
            $validated = $this->validateCustomerRegistration($request);

            $userId = $this->getUserId();
            $companyId = $this->getCompanyId();

            // Check if customer already exists
            $existingCustomer = $this->findExistingCustomer($validated);

            DB::beginTransaction();

            if ($existingCustomer) {
                $customer = $existingCustomer;
                $message = 'Customer already existed, transferred to your company';
                $this->transferCustomerToCompany($customer, $validated['zone'], $userId, $companyId);
            } else {
                $customer = $this->createNewCustomer($validated, $userId);
                $message = 'Customer created successfully';
            }

            // Create zone assignment
            $zoneData = Zone::find($validated['zone']);
            if (!$zoneData) {
                throw new \Exception('Zone not found');
            }
            $this->createZoneAssignment($customer->id, $companyId, $validated['zone'], $zoneData->branch, $userId);

            DB::commit();

            return $this->successResponse(
                $this->transformCustomer($customer->load(['zoneAssignment' => function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                }])),
                $message,
                201
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to register customer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit customer for approval
     */
    public function submit(Request $request, $customerId)
    {
        $companyId = $this->getCompanyId();

        $customerZone = CustomersZone::where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->first();

        if (!$customerZone) {
            return $this->errorResponse('Customer not found', 404);
        }

        // Check if customer has all required data
        if (!$customerZone->has_referee) {
            return $this->errorResponse('Please add at least one referee before submitting', 422);
        }

        if (!$customerZone->has_attachments) {
            return $this->errorResponse('Please upload required documents before submitting', 422);
        }

        // Submit for approval
        $customerZone->update(['status' => CustomersZone::STATUS_PENDING]);

        return $this->successResponse(null, 'Customer submitted for approval successfully');
    }

    /**
     * Approve customer
     */
    public function approve($customerId)
    {
        if (!$this->hasPermission(21)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $companyId = $this->getCompanyId();
        $userPayload = $this->getUserPayload();

        $customerZone = CustomersZone::where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->first();

        if (!$customerZone) {
            return $this->errorResponse('Customer not found', 404);
        }

        $customerZone->update(['status' => CustomersZone::STATUS_ACTIVE]);

        // Send SMS notification
        $customer = Customers::find($customerId);
        $this->sendApprovalSMS($customer, $userPayload);

        return $this->successResponse(null, 'Customer approved successfully');
    }

    /**
     * Reject customer
     */
    public function reject($customerId)
    {
        if (!$this->hasPermission(21)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $companyId = $this->getCompanyId();

        $customerZone = CustomersZone::where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->first();

        if (!$customerZone) {
            return $this->errorResponse('Customer not found', 404);
        }

        $customerZone->update(['status' => CustomersZone::STATUS_REJECTED]);

        // Also reject associated data
        CustomerReferees::where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->update(['status' => 3]);

        Attachements::where('customer_id', $customerId)
            ->where('company_id', $companyId)
            ->update(['status' => 3]);

        return $this->successResponse(null, 'Customer rejected successfully');
    }

    /**
     * Get customer profile with all details
     */
    public function profile($customerId)
    {
        $companyId = $this->getCompanyId();

        $customer = Customers::with([
            'zoneAssignment' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->with('zone', 'branch');
            },
            'loans' => function ($q) {
                $q->orderBy('created_at', 'desc');
            },
            'referees',
            'attachments',
            'collaterals'
        ])->where('id', $customerId)
            ->whereHas('zoneAssignment', function ($q) use ($companyId) {
                $q->where('company_id', $companyId);
            })
            ->first();

        if (!$customer) {
            return $this->errorResponse('Customer not found', 404);
        }

        $readiness = $this->getReadinessScore($customer);

        return $this->successResponse([
            'customer' => $this->transformCustomer($customer, true),
            'readiness' => $readiness,
            'statistics' => [
                'total_loans' => $customer->loans->count(),
                'active_loan' => $customer->loans->whereIn('status', [5, 12])->first(),
                'total_borrowed' => $customer->loans->sum('principal_amount'),
                'total_repaid' => $customer->loans->sum('loan_paid'),
                'outstanding_balance' => $customer->loans->sum(function ($loan) {
                    return ($loan->total_loan + $loan->penalty_amount) - $loan->loan_paid;
                }),
            ]
        ]);
    }

    // ============================================
    // Helper Methods
    // ============================================

    private function transformCustomer($customer, $detailed = false)
    {
        // Get status from zoneAssignment
        $zoneStatus = $customer->zoneAssignment ? $customer->zoneAssignment->status : null;

        $data = [
            'id' => $customer->id,
            'fullname' => $customer->fullname,
            'phone' => $customer->customer_phone ?? $customer->phone,
            'email' => $customer->email,
            'nida' => $customer->nida,
            'address' => $customer->address,
            'city' => $customer->city,
            'income' => $customer->income,
            'formatted_income' => number_format($customer->income ?? 0, 0, '.', ','),
            'is_group' => (bool) $customer->is_group,
            'status' => $zoneStatus,
            'status_label' => $this->getZoneStatusLabel($zoneStatus),
            'status_color' => $this->getZoneStatusColor($zoneStatus),
            'initials' => $this->getInitials($customer->fullname),
            'avatar_color' => $this->getAvatarColor($customer->fullname),
            'customer_image' => $customer->customer_image,
            'created_at' => $customer->created_at,
            'profile_completeness' => $this->getProfileCompleteness($customer),
        ];

        if ($detailed) {
            $data['gender'] = $customer->gender;
            $data['gender_label'] = $this->getGenderLabel($customer->gender);
            $data['marital_status'] = $customer->marital_status;
            $data['marital_status_label'] = $this->getMaritalStatusLabel($customer->marital_status);
            $data['employment_type'] = $customer->employment_type;
            $data['employment_label'] = $this->getEmploymentLabel($customer->employment_type);
            $data['education_level'] = $customer->education_level;
            $data['education_label'] = $this->getEducationLabel($customer->education_level);
            $data['experience'] = $customer->experience;
            $data['date_of_birth'] = $customer->date_of_birth;
            $data['age'] = $customer->date_of_birth ? \Carbon\Carbon::parse($customer->date_of_birth)->age : null;
            $data['is_defaulted'] = (bool) $customer->is_defaulted;
            $data['zone'] = $customer->zoneAssignment?->zone;
            $data['branch'] = $customer->zoneAssignment?->branch;
            $data['loans'] = $customer->loans->map(function ($loan) {
                return [
                    'id' => $loan->id,
                    'loan_number' => $loan->loan_number,
                    'amount' => $loan->principal_amount,
                    'status' => $this->getLoanStatusLabel($loan->status),
                    'created_at' => $loan->created_at,
                ];
            });
            $data['referees'] = $customer->referees;
            $data['attachments'] = $customer->attachments;
            $data['collaterals'] = $customer->collaterals;
        }

        return $data;
    }

    private function getZoneStatusLabel($status)
    {
        $labels = [
            CustomersZone::STATUS_ACTIVE => 'Active',
            CustomersZone::STATUS_INACTIVE => 'Inactive',
            CustomersZone::STATUS_DELETED => 'Deleted',
            CustomersZone::STATUS_PENDING => 'Pending',
            CustomersZone::STATUS_REJECTED => 'Rejected',
        ];
        return $labels[$status] ?? 'Inactive';
    }

    private function getZoneStatusColor($status)
    {
        $colors = [
            CustomersZone::STATUS_ACTIVE => 'success',
            CustomersZone::STATUS_INACTIVE => 'warning',
            CustomersZone::STATUS_DELETED => 'danger',
            CustomersZone::STATUS_PENDING => 'info',
            CustomersZone::STATUS_REJECTED => 'danger',
        ];
        return $colors[$status] ?? 'secondary';
    }

    private function getGenderLabel($gender)
    {
        $labels = [1 => 'Male', 2 => 'Female'];
        return $labels[$gender] ?? 'Not specified';
    }

    private function getMaritalStatusLabel($status)
    {
        $labels = [
            1 => 'Single',
            2 => 'Married',
            3 => 'Divorced',
            4 => 'Widowed',
        ];
        return $labels[$status] ?? 'Not specified';
    }

    private function getEmploymentLabel($type)
    {
        $labels = [
            1 => 'Salaried',
            2 => 'Self Employed',
            3 => 'Business Owner',
            4 => 'Unemployed',
        ];
        return $labels[$type] ?? 'Not specified';
    }

    private function getEducationLabel($level)
    {
        $labels = [
            1 => 'Primary',
            2 => 'Secondary',
            3 => 'Diploma',
            4 => 'Degree',
            5 => 'Masters',
            6 => 'PhD',
        ];
        return $labels[$level] ?? 'Not specified';
    }

    private function getLoanStatusLabel($status)
    {
        $labels = [
            3 => 'Deleted',
            4 => 'Submitted',
            5 => 'Active',
            6 => 'Completed',
            7 => 'Defaulted',
            8 => 'Received',
            12 => 'Overdue',
        ];
        return $labels[$status] ?? 'Unknown';
    }

    private function getInitials($name)
    {
        $parts = explode(' ', $name);
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            if (!empty($part)) {
                $initials .= strtoupper($part[0]);
            }
        }
        return $initials ?: 'U';
    }

    private function getAvatarColor($name)
    {
        $colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'dark'];
        $index = ord($name[0] ?? 'A') % count($colors);
        return $colors[$index];
    }

    private function getProfileCompleteness($customer)
    {
        $score = 0;
        $fields = [
            'phone' => 15,
            'email' => 10,
            'nida' => 15,
            'address' => 10,
            'city' => 5,
            'income' => 15,
            'date_of_birth' => 10,
            'customer_image' => 10,
        ];

        foreach ($fields as $field => $weight) {
            if (!empty($customer->$field)) {
                $score += $weight;
            }
        }

        if ($customer->attachments && $customer->attachments->count() > 0) {
            $score += 5;
        }
        if ($customer->referees && $customer->referees->count() > 0) {
            $score += 5;
        }

        return min($score, 100);
    }

    private function getReadinessScore($customer)
    {
        $hasReferee = $customer->referees && $customer->referees->count() > 0;
        $hasAttachments = $customer->attachments && $customer->attachments->count() > 0;
        $hasCollateral = $customer->collaterals && $customer->collaterals->count() > 0;

        $score = 0;
        $recommendations = [];

        if ($hasReferee) {
            $score += 33;
        } else {
            $recommendations[] = [
                'action' => 'Add Referee',
                'description' => 'Add at least one referee to improve loan eligibility',
                'priority' => 'high'
            ];
        }

        if ($hasAttachments) {
            $score += 33;
        } else {
            $recommendations[] = [
                'action' => 'Upload Documents',
                'description' => 'Upload identification documents to verify identity',
                'priority' => 'high'
            ];
        }

        if ($hasCollateral) {
            $score += 34;
        } else {
            $recommendations[] = [
                'action' => 'Register Collateral',
                'description' => 'Add collateral to increase loan approval chances',
                'priority' => 'medium'
            ];
        }

        $level = $score >= 66 ? 'Ready for loan' : ($score >= 33 ? 'Needs more docs' : 'Incomplete');

        return [
            'score' => $score,
            'level' => $level,
            'recommendations' => $recommendations,
        ];
    }

    private function validateCustomerRegistration($request)
    {
        return $request->validate([
            'fullname' => 'required|string|max:255',
            'phone' => 'required|numeric|digits:10',
            'email' => 'nullable|email|max:255',
            'gender' => 'required',
            'maritual' => 'required',
            'nida' => 'nullable|numeric|digits:20',
            'address' => 'nullable|string|max:255',
            'city' => 'required',
            'education' => 'required',
            'employment' => 'required',
            'experience' => 'required|numeric',
            'income' => 'required|numeric',
            'dob' => 'required|date|date_format:Y-m-d|before:today',
            'zone' => 'required|exists:zones,id',
            'customer_image' => 'required|image|mimes:jpg,png,jpeg|max:2048',
        ]);
    }

    private function findExistingCustomer($validated)
    {

        return Customers::where('fullname', 'LIKE', '%' . $validated['fullname'] . '%')
            ->where('customer_phone', $validated['phone'])
            ->where('nida', $validated['nida'])
            ->first();
    }

    private function createNewCustomer($validated, $userId)
    {
        $validated['fullname'] = ucwords(strtolower(trim($validated['fullname'])));
        $validated['email'] = strtolower($validated['email'] ?? '');
        $validated['address'] = ucwords(strtolower($validated['address'] ?? ''));
        $validated['customer_phone'] = $validated['phone'];
        $validated['phone'] = '255' . substr($validated['phone'], 1);
        $validated['marital_status'] = $validated['maritual'];
        $validated['education_level'] = $validated['education'];
        $validated['employment_type'] = $validated['employment'];
        $validated['date_of_birth'] = $validated['dob'];
        $validated['created_by'] = $userId;
        $validated['nida'] = $validated['nida'] ?? null;
        $validated['city'] = $validated['city'];
        $validated['gender'] = $validated['gender'];
        $validated['experience'] = $validated['experience'];

        //$validated['status'] = null; // Status is stored in CustomersZone, not here

        // Handle image upload
        if (isset($validated['customer_image']) && $validated['customer_image'] instanceof \Illuminate\Http\UploadedFile) {
            $file = $validated['customer_image'];
            $filename = time() . '.' . $file->getClientOriginalExtension();
            $path = base_path('../t11.customers_public/uploads/customers');
            if (!file_exists($path)) {
                mkdir($path, 0755, true);
            }
            $file->move($path, $filename);
            $validated['customer_image'] = 'uploads/customers/' . $filename;
        }

        return Customers::create($validated);
    }

    private function transferCustomerToCompany($customer, $zoneId, $userId, $companyId)
    {
        $zoneData = Zone::find($zoneId);

        return CustomersZone::create([
            'customer_id' => $customer->id,
            'company_id' => $companyId,
            'zone_id' => $zoneId,
            'branch_id' => $zoneData->branch,
            'created_by' => $userId,
            'status' => CustomersZone::STATUS_INACTIVE,
            'customer_income' => $customer->income,
        ]);
    }

    private function createZoneAssignment($customerId, $companyId, $zoneId, $branchId, $userId)
    {
        return CustomersZone::create([
            'customer_id' => $customerId,
            'company_id' => $companyId,
            'zone_id' => $zoneId,
            'branch_id' => $branchId,
            'created_by' => $userId,
            'status' => CustomersZone::STATUS_INACTIVE,
        ]);
    }

    private function hasAccessToCustomer($customer)
    {
        $companyId = $this->getCompanyId();
        $userZones = $this->getUserZones();
        $userBranches = $this->getUserBranches();

        $zoneAssignment = $customer->zoneAssignment;

        if (!$zoneAssignment || $zoneAssignment->company_id != $companyId) {
            return false;
        }

        if ($this->isManager() || $this->isRootAdmin()) {
            return true;
        }

        if ($this->isBranchManager() && in_array($zoneAssignment->branch_id, $userBranches)) {
            return true;
        }

        if ($this->isZoneOfficer() && in_array($zoneAssignment->zone_id, $userZones)) {
            return true;
        }

        return false;
    }

    private function sendApprovalSMS($customer, $userPayload)
    {
        if (!$customer || !$customer->phone) return;

        $companyName = $userPayload->get('company_name', 'TerminalXI');
        $companyPhone = $userPayload->get('company_phone', '');

        $message = "Habari {$customer->fullname}\n" .
            "Umesajiliwa kikamilifu katika mfumo wa mikopo kutoka kampuni ya: {$companyName}.\n" .
            "Wasiliana nasi kwa nambari: {$companyPhone}. Karibu tukuhudumie.";

        $this->notificationService->sendSMS($customer->phone, $message);
    }

    // Add these methods to your existing CustomerController.php

    /**
     * Register referee for a customer
     */
    public function registerReferee(Request $request)
    {
        try {
            $validated = $request->validate([
                'fullname' => 'required|string|max:255',
                'referee_phone' => 'required|numeric|digits:10',
                'email' => 'nullable|email|max:255',
                'gender' => 'required',
                'nida' => 'nullable|numeric|digits:20',
                'address' => 'nullable|string|max:255',
                'city' => 'required',
                'dob' => 'nullable|date|date_format:Y-m-d',
                'referee_image' => 'required|image|mimes:jpg,png,jpeg|max:10240',
                'customer' => 'required',
            ]);

            $companyId = $this->getCompanyId();
            $userId = $this->getUserId();

            // Verify customer exists and user has access
            $customerZone = CustomersZone::where('customer_id', $validated['customer'])
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not found', 404);
            }

            // Check if referee already exists
            $existingReferee = Referees::where('nida', $validated['nida'])
                ->where('referee_phone', $validated['referee_phone'])
                ->first();

            DB::beginTransaction();

            // Handle image upload
            $refereeImage = null;
            if ($request->hasFile('referee_image')) {
                $file = $request->file('referee_image');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = base_path('../t11.customers_public/uploads/referees');
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }
                $file->move($path, $filename);
                $refereeImage = 'uploads/referees/' . $filename;
            }

            if ($existingReferee) {
                // Link existing referee to customer
                $referee = $existingReferee;

                // Check if already linked
                $existingLink = CustomerReferees::where('referee_id', $referee->id)
                    ->where('customer_id', $validated['customer'])
                    ->where('company_id', $companyId)
                    ->first();

                if ($existingLink) {
                    DB::rollBack();
                    return $this->errorResponse('Referee already linked to this customer', 422);
                }
            } else {
                // Create new referee
                $refereeData = [
                    'fullname' => ucwords(strtolower(trim($validated['fullname']))),
                    'email' => strtolower($validated['email'] ?? ''),
                    'referee_phone' => $validated['referee_phone'],
                    'phone' => '255' . substr($validated['referee_phone'], 1),
                    'nida' => $validated['nida'],
                    'address' => $validated['address'] ?? null,
                    'city' => $validated['city'],
                    'gender' => $validated['gender'],
                    'date_of_birth' => $validated['dob'] ?? null,
                    'referee_image' => $refereeImage,
                    'created_by' => $userId,
                    'status' => 1,
                ];

                $referee = Referees::create($refereeData);
            }

            // Link referee to customer
            CustomerReferees::create([
                'referee_id' => $referee->id,
                'customer_id' => $validated['customer'],
                'company_id' => $companyId,
                'branch_id' => $customerZone->branch_id,
                'zone_id' => $customerZone->zone_id,
                'status' => 1,
                'from_group' => $request->input('from_group', 0),
                'group_id' => $request->input('group_id', null),
            ]);

            // Update customer zone status
            $customerZone->update([
                'has_referee' => true,
                'referee_id' => $referee->id
            ]);

            DB::commit();

            return $this->successResponse([
                'referee' => $referee,
                'customer_id' => $validated['customer']
            ], 'Referee registered successfully', 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Referee registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to register referee: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Register attachments for a customer
     */
    public function registerAttachments(Request $request)
    {
        try {
            $validated = $request->validate([
                'attachment_name' => 'required|string|max:255',
                'customer' => 'required',
                'attachment' => 'required|file|mimes:jpg,png,jpeg,pdf|max:10240',
            ]);

            $companyId = $this->getCompanyId();
            $userId = $this->getUserId();

            // Verify customer exists
            $customerZone = CustomersZone::where('customer_id', $validated['customer'])
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not found', 404);
            }

            DB::beginTransaction();

            // Handle file upload
            $filePath = null;
            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
                $path = base_path('../t11.customers_public/uploads/attachments');
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }
                $file->move($path, $filename);
                $filePath = 'uploads/attachments/' . $filename;
            }

            // Create attachment
            $attachment = Attachements::create([
                'customer_id' => $validated['customer'],
                'attachment_name' => $validated['attachment_name'],
                'attachment_path' => $filePath,
                'attachment_extension' => $file->getClientOriginalExtension(),
                'company_id' => $companyId,
                'status' => 1,
            ]);

            // Update customer zone status
            $customerZone->update(['has_attachments' => true]);

            DB::commit();

            return $this->successResponse($attachment, 'Attachment uploaded successfully', 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Attachment upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to upload attachment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Register collateral for a customer
     */
    public function registerCollateral(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'value' => 'required|numeric|min:0',
                'customer' => 'required',
            ]);

            $companyId = $this->getCompanyId();
            $userId = $this->getUserId();

            // Verify customer exists
            $customerZone = CustomersZone::where('customer_id', $validated['customer'])
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not found', 404);
            }

            // Create collateral
            $collateral = Collateral::create([
                'name' => $validated['name'],
                'value' => $validated['value'],
                'customer' => $validated['customer'],
                'company' => $companyId,
                'status' => 1,
            ]);

            return $this->successResponse($collateral, 'Collateral registered successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Collateral registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to register collateral: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Submit customer for approval
     */
    public function customerSubmit(Request $request)
    {
        try {
            $validated = $request->validate([
                'customer' => 'required',
            ]);

            $companyId = $this->getCompanyId();

            $customerZone = CustomersZone::where('customer_id', $validated['customer'])
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not found', 404);
            }

            // Check if customer has all required data
            if (!$customerZone->has_referee) {
                return $this->errorResponse('Please add at least one referee before submitting', 422);
            }

            if (!$customerZone->has_attachments) {
                return $this->errorResponse('Please upload required documents before submitting', 422);
            }

            // Submit for approval
            $customerZone->update(['status' => CustomersZone::STATUS_PENDING]);

            return $this->successResponse(null, 'Customer submitted for approval successfully');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Customer submission failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to submit customer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Register group customer
     */
    public function registerGroup(Request $request)
    {
        try {
            $validated = $request->validate([
                'fullname' => 'required|string|max:255',
                'phone' => 'required|numeric|digits:10',
                'email' => 'nullable|email|max:255',
                'address' => 'nullable|string|max:255',
                'city' => 'required',
                'income' => 'required|numeric',
                'zone' => 'required|exists:zones,id',
            ]);

            $userId = $this->getUserId();
            $companyId = $this->getCompanyId();

            DB::beginTransaction();

            // Create group customer
            $customer = Customers::create([
                'fullname' => ucwords(strtolower(trim($validated['fullname']))),
                'email' => strtolower($validated['email'] ?? ''),
                'customer_phone' => $validated['phone'],
                'phone' => '255' . substr($validated['phone'], 1),
                'address' => ucwords(strtolower($validated['address'] ?? '')),
                'city' => $validated['city'],
                'income' => $validated['income'],
                'is_group' => true,
                'created_by' => $userId,
                'gender' => 1,
                'marital_status' => 1,
                'education_level' => 1,
                'employment_type' => 1,
                'experience' => 1,
                'date_of_birth' => date('Y-m-d'),
                'customer_image' => 'uploads/customers/avatar.png',


            ]);

            // Create zone assignment
            $zoneData = Zone::find($validated['zone']);
            $this->createZoneAssignment($customer->id, $companyId, $validated['zone'], $zoneData->branch, $userId);

            DB::commit();

            return $this->successResponse(
                $this->transformCustomer($customer->load(['zoneAssignment' => function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                }])),
                'Group created successfully',
                201
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Group registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to register group: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Store referee for a customer (alias for registerReferee)
     */
    public function storeReferee(Request $request, $id)
    {
        // Add the customer ID to the request
        $request->merge(['customer' => $id]);
        return $this->registerReferee($request);
    }

    /**
     * Store attachment for a customer (alias for registerAttachments)
     */
    public function storeAttachment(Request $request, $id)
    {
        // Add the customer ID to the request
        $request->merge(['customer' => $id]);
        return $this->registerAttachments($request);
    }

    /**
     * Store collateral for a customer (alias for registerCollateral)
     */
    public function storeCollateral(Request $request, $id)
    {
        // Add the customer ID to the request
        $request->merge(['customer' => $id]);
        return $this->registerCollateral($request);
    }


    /**
     * Update customer information
     * Aligned with frontend CustomerEdit.tsx form fields
     */
    public function update(Request $request, $id)
    {
        try {
            $companyId = $this->getCompanyId();
            $userId = $this->getUserId();

            // Find customer with zone assignment
            $customer = Customers::where('id', $id)
                ->whereHas('zoneAssignment', function ($q) use ($companyId) {
                    $q->where('company_id', $companyId);
                })
                ->first();

            if (!$customer) {
                return $this->errorResponse('Customer not found', 404);
            }

            // Check access permission
            if (!$this->hasAccessToCustomer($customer)) {
                return $this->errorResponse('Unauthorized access', 403);
            }

            // Validate request with frontend field names
            $validated = $request->validate([
                'fullname' => 'sometimes|required|string|max:255',
                'phone' => 'sometimes|required|numeric|digits:10',
                'email' => 'nullable|email|max:255',
                'gender' => 'sometimes|required',
                'maritual' => 'sometimes|required',  // Frontend uses 'maritual'
                'nida' => 'sometimes|required|numeric|digits:20',
                'address' => 'nullable|string|max:255',
                'city' => 'sometimes|required',
                'education' => 'sometimes|required',  // Frontend uses 'education'
                'employment' => 'sometimes|required', // Frontend uses 'employment'
                'experience' => 'sometimes|required|numeric',
                'income' => 'sometimes|required|numeric',
                'dob' => 'sometimes|required|date|date_format:Y-m-d|before:today',  // Frontend uses 'dob'
                'zone' => 'sometimes|required|exists:zones,id',
                'customer_image' => 'nullable|image|mimes:jpg,png,jpeg|max:2048',
            ]);

            DB::beginTransaction();

            // Prepare update data (map frontend fields to database fields)
            $updateData = [];

            if (isset($validated['fullname'])) {
                $updateData['fullname'] = ucwords(strtolower(trim($validated['fullname'])));
            }
            if (isset($validated['email'])) {
                $updateData['email'] = strtolower($validated['email']);
            }
            if (isset($validated['phone'])) {
                $updateData['customer_phone'] = $validated['phone'];
                $updateData['phone'] = '255' . substr($validated['phone'], 1);
            }
            if (isset($validated['gender'])) {
                $updateData['gender'] = $validated['gender'];
            }
            if (isset($validated['maritual'])) {
                $updateData['marital_status'] = $validated['maritual'];  // Map maritual -> marital_status
            }
            if (isset($validated['nida'])) {
                $updateData['nida'] = $validated['nida'];
            }
            if (isset($validated['address'])) {
                $updateData['address'] = ucwords(strtolower($validated['address']));
            }
            if (isset($validated['city'])) {
                $updateData['city'] = $validated['city'];
            }
            if (isset($validated['education'])) {
                $updateData['education_level'] = $validated['education'];  // Map education -> education_level
            }
            if (isset($validated['employment'])) {
                $updateData['employment_type'] = $validated['employment'];  // Map employment -> employment_type
            }
            if (isset($validated['experience'])) {
                $updateData['experience'] = $validated['experience'];
            }
            if (isset($validated['income'])) {
                $updateData['income'] = $validated['income'];
            }
            if (isset($validated['dob'])) {
                $updateData['date_of_birth'] = $validated['dob'];  // Map dob -> date_of_birth
            }

            // Handle image upload
            if ($request->hasFile('customer_image')) {
                // Delete old image if exists
                if ($customer->customer_image && file_exists(base_path('../t11.customers_public/' . $customer->customer_image))) {
                    unlink(base_path('../t11.customers_public/' . $customer->customer_image));
                }

                $file = $request->file('customer_image');
                $filename = time() . '.' . $file->getClientOriginalExtension();
                $path = base_path('../t11.customers_public/uploads/customers');
                if (!file_exists($path)) {
                    mkdir($path, 0755, true);
                }
                $file->move($path, $filename);
                $updateData['customer_image'] = 'uploads/customers/' . $filename;
            }

            // Update customer
            if (!empty($updateData)) {
                $customer->update($updateData);
            }

            // Update zone assignment if zone changed
            if (isset($validated['zone'])) {
                $zoneData = Zone::find($validated['zone']);
                if (!$zoneData) {
                    throw new \Exception('Zone not found');
                }

                CustomersZone::where('customer_id', $customer->id)
                    ->where('company_id', $companyId)
                    ->update([
                        'zone_id' => $validated['zone'],
                        'branch_id' => $zoneData->branch,
                        //'updated_by' => $userId,
                    ]);
            }

            DB::commit();

            // Refresh customer data
            $customer->refresh();
            $customer->load(['zoneAssignment' => function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->with('zone', 'branch');
            }]);

            return $this->successResponse(
                $this->transformCustomer($customer, true),
                'Customer updated successfully'
            );
        } catch (ValidationException $e) {
            DB::rollBack();
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer update failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to update customer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete customer (soft delete)
     */
    /* public function destroy($id)
    {
        try {
            $companyId = $this->getCompanyId();
            $userId = $this->getUserId();

            // Check permission
            if (!$this->hasPermission(21)) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $customerZone = CustomersZone::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not found', 404);
            }

            DB::beginTransaction();

            // Soft delete customer zone assignment
            $customerZone->update([
                'statuss' => 3,
                //'deleted_by' => $userId,
                //'deleted_at' => now(),
            ]);

            // Also soft delete related data
            CustomerReferees::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->update(['status' => 3]);

            Attachements::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->update(['status' => 3]);

            Collateral::where('customer', $id)
                ->where('company', $companyId)
                ->update(['status' => 3]);

            DB::commit();

            return $this->successResponse(null, 'Customer deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer deletion failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to delete customer: ' . $e->getMessage(), 500);
        }
    } */


    /**
     * Delete customer (soft delete)
     */
    public function destroy($id)
    {
        try {
            $companyId = $this->getCompanyId();
            $userId = $this->getUserId();

            // Check permission
            if (!$this->hasPermission(21)) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $customerZone = CustomersZone::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->first();

            if (!$customerZone) {
                return $this->errorResponse('Customer not found', 404);
            }

            // Log before update
            Log::info('Before deletion - CustomerZone data:', [
                'customer_id' => $id,
                'current_status' => $customerZone->status,
                'status_constant' => CustomersZone::STATUS_DELETED,
                'record_exists' => $customerZone ? 'yes' : 'no',
                'customer_zone_id' => $customerZone->id,
            ]);

            DB::beginTransaction();

            // Try update with different approaches
            $updateData = [
                'status' => CustomersZone::STATUS_DELETED,
            ];

            // Check if deleted_by column exists
            $hasDeletedBy = Schema::hasColumn('customers_zones', 'deleted_by');
            $hasDeletedAt = Schema::hasColumn('customers_zones', 'deleted_at');

            if ($hasDeletedBy) {
                $updateData['deleted_by'] = $userId;
            }
            if ($hasDeletedAt) {
                $updateData['deleted_at'] = now();
            }

            Log::info('Update data being sent:', [
                'update_data' => $updateData,
                'has_deleted_by_column' => $hasDeletedBy,
                'has_deleted_at_column' => $hasDeletedAt,
            ]);

            // Perform update
            $updated = $customerZone->update($updateData);

            Log::info('Update result:', [
                'updated_successfully' => $updated,
                'new_status_after_update' => $customerZone->fresh()->status,
            ]);

            // Also update related data
            $refereesUpdated = CustomerReferees::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->update(['status' => 3]);

            $attachmentsUpdated = Attachements::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->update(['status' => 3]);

            $collateralsUpdated = Collateral::where('customer', $id)
                ->where('company', $companyId)
                ->update(['status' => 3]);

            Log::info('Related records updated:', [
                'referees_updated' => $refereesUpdated,
                'attachments_updated' => $attachmentsUpdated,
                'collaterals_updated' => $collateralsUpdated,
            ]);

            DB::commit();

            // Verify final status
            $finalCustomerZone = CustomersZone::where('customer_id', $id)
                ->where('company_id', $companyId)
                ->first();

            Log::info('After deletion - Final state:', [
                'final_status' => $finalCustomerZone->status,
                'status_matches_expected' => $finalCustomerZone->status == CustomersZone::STATUS_DELETED,
            ]);

            return $this->successResponse(null, 'Customer deleted successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Customer deletion failed', [
                'customer_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse('Failed to delete customer: ' . $e->getMessage(), 500);
        }
    }

    public function loanFreeCustomers()
    {
        $user_zones = $this->getUserZones();
        $customers = [];
        //Log::info('zonesId data: ', $user_zones);
        if (sizeof($user_zones) > 0) {

            foreach ($user_zones as $zone) {
                $customerData = Customers::select('customers.fullname', 'customers.id')
                    ->join('customers_zones', 'customers.id', '=', 'customers_zones.customer_id')
                    ->where('customers_zones.zone_id', $zone)
                    ->where('customers_zones.status', 1)
                    //add where condition to filter only active customers
                    ->where('customers_zones.company_id', $this->getCompanyId())
                    ->get();

                if (sizeof($customerData) > 0) {
                    foreach ($customerData as $customer) {
                        $freeLoanCustomer = Loans::where('customer', [$customer->id])
                            ->whereIn('status', [4, 5, 8, 12])
                            ->get();
                        if (sizeof($freeLoanCustomer) == 0) {
                            $customers[] = $customer;
                        }
                    }
                }
            }
        }

        return response()->json(
            //["zones"=>$user_zones]
            $customers
            //$customerData
        );
    }
}
