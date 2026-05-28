<?php

namespace App\Http\Controllers\Api\V2;

use App\Models\Customers;
use App\Models\LoansProducts;
use App\Models\LoanToken;
use App\Http\Controllers\Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Log;

class LoansProductsController extends BaseController
{
    /**
     * Register a new loan product
     */
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'product_name' => 'bail|required|string|max:255',
                'max_loan_amount' => 'bail|required|numeric|min:1',
                'min_loan_amount' => 'bail|required|numeric|min:1',
                'loan_period_unit' => 'bail|required|string|max:255',
                'min_loan_period' => 'bail|required|numeric|min:1',
                'max_loan_period' => 'bail|required|numeric|min:1',
                'repayment_interval_unit' => 'bail|required|string|max:255',
                'repayment_interval' => 'bail|required|numeric|min:1',
                'interest_mode' => 'bail|required|numeric',
                'interest_amount' => 'bail|nullable|required_if:interest_mode,1|numeric|min:0',
                'interest_rate' => 'bail|nullable|required_if:interest_mode,2|numeric|min:0',
                'interest_threshold' => 'bail|nullable|required_if:interest_mode,2|numeric|min:0',
                'penalty_type' => 'bail|required',
                'fixed_penalty_amount' => 'bail|nullable|required_if:penalty_type,1|numeric|min:0',
                'penalty_percentage' => 'bail|nullable|required_if:penalty_type,2|numeric|min:0',
                'skip_sat' => 'bail|required',
                'skip_sun' => 'bail|required',
                // Add to the validation array in register() and update() methods

                // Workflow Settings
                'default_days_overdue' => 'nullable|integer|min:1',
                'default_missed_payments' => 'nullable|integer|min:1',
                'default_percentage_of_term' => 'nullable|integer|min:0|max:100',

                'write_off_enabled' => 'boolean',
                'write_off_days_overdue' => 'nullable|integer|min:1',
                'write_off_missed_payments' => 'nullable|integer|min:1',
                'write_off_requires_approval' => 'boolean',
                'write_off_approval_level' => 'nullable|string|in:supervisor,manager,director,board',
                'write_off_auto_process' => 'boolean',
                'write_off_recovery_attempts' => 'nullable|integer|min:0',

                'foreclosure_enabled' => 'boolean',
                'foreclosure_days_overdue' => 'nullable|integer|min:1',
                'foreclosure_missed_payments' => 'nullable|integer|min:1',
                'foreclosure_requires_collateral' => 'boolean',
                'foreclosure_legal_required' => 'boolean',
                'foreclosure_approval_level' => 'nullable|string|in:manager,director,legal',
                'foreclosure_notice_days' => 'nullable|integer|min:1',
                'foreclosure_redemption_period' => 'nullable|integer|min:0',

                'restructure_enabled' => 'boolean',
                'restructure_days_overdue' => 'nullable|integer|min:1',
                'restructure_max_times' => 'nullable|integer|min:0',
                'restructure_approval_level' => 'nullable|string|in:supervisor,manager,director',

                'notify_on_overdue_days' => 'nullable|integer|min:1',
                'notify_on_default' => 'boolean',
                'notify_on_write_off' => 'boolean',
                'notify_on_foreclosure' => 'boolean',

                'recovery_enabled' => 'boolean',
                'recovery_max_attempts' => 'nullable|integer|min:1',
                'recovery_assign_to_agency_days' => 'nullable|integer|min:1',
            ]);

            $validated['registered_by'] = $this->getUserId();
            $validated['company']       = $this->getCompanyId();

            $product = LoansProducts::create($validated);

            return $this->successResponse([
                'module' => $product->product_name ?? 'Loan Product',
            ], 'Loan product created successfully', 201);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Failed to register loan product: ' . $e->getMessage());
            return $this->errorResponse('Failed to register loan product', 500);
        }
    }

    /**
     * Update an existing loan product
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'bail|required',
                'product_name' => 'bail|required|string|max:255',
                'max_loan_amount' => 'bail|required|numeric|min:1',
                'min_loan_amount' => 'bail|required|numeric|min:1',
                'loan_period_unit' => 'bail|required|string|max:255',
                'min_loan_period' => 'bail|required|numeric|min:1',
                'max_loan_period' => 'bail|required|numeric|min:1',
                'repayment_interval_unit' => 'bail|required|string|max:255',
                'repayment_interval' => 'bail|required|numeric|min:1',
                'interest_mode' => 'bail|required|numeric',
                'interest_amount' => 'bail|nullable|required_if:interest_mode,1|numeric|min:0',
                'interest_rate' => 'bail|nullable|required_if:interest_mode,2|numeric|min:0',
                'interest_threshold' => 'bail|nullable|required_if:interest_mode,2|numeric|min:0',
                'penalty_type' => 'bail|required',
                'fixed_penalty_amount' => 'bail|nullable|required_if:penalty_type,1|numeric|min:0',
                'penalty_percentage' => 'bail|nullable|required_if:penalty_type,2|numeric|min:0',
                'skip_sat' => 'bail|required',
                'skip_sun' => 'bail|required',
                // Add to the validation array in register() and update() methods

                // Workflow Settings
                'default_days_overdue' => 'nullable|integer|min:1',
                'default_missed_payments' => 'nullable|integer|min:1',
                'default_percentage_of_term' => 'nullable|integer|min:0|max:100',

                'write_off_enabled' => 'boolean',
                'write_off_days_overdue' => 'nullable|integer|min:1',
                'write_off_missed_payments' => 'nullable|integer|min:1',
                'write_off_requires_approval' => 'boolean',
                'write_off_approval_level' => 'nullable|string|in:supervisor,manager,director,board',
                'write_off_auto_process' => 'boolean',
                'write_off_recovery_attempts' => 'nullable|integer|min:0',

                'foreclosure_enabled' => 'boolean',
                'foreclosure_days_overdue' => 'nullable|integer|min:1',
                'foreclosure_missed_payments' => 'nullable|integer|min:1',
                'foreclosure_requires_collateral' => 'boolean',
                'foreclosure_legal_required' => 'boolean',
                'foreclosure_approval_level' => 'nullable|string|in:manager,director,legal',
                'foreclosure_notice_days' => 'nullable|integer|min:1',
                'foreclosure_redemption_period' => 'nullable|integer|min:0',

                'restructure_enabled' => 'boolean',
                'restructure_days_overdue' => 'nullable|integer|min:1',
                'restructure_max_times' => 'nullable|integer|min:0',
                'restructure_approval_level' => 'nullable|string|in:supervisor,manager,director',

                'notify_on_overdue_days' => 'nullable|integer|min:1',
                'notify_on_default' => 'boolean',
                'notify_on_write_off' => 'boolean',
                'notify_on_foreclosure' => 'boolean',

                'recovery_enabled' => 'boolean',
                'recovery_max_attempts' => 'nullable|integer|min:1',
                'recovery_assign_to_agency_days' => 'nullable|integer|min:1',
            ]);

            $product = LoansProducts::where('id', $validated['id'])
                ->where('company', $this->getCompanyId())
                ->first();

            if (!$product) {
                return $this->errorResponse('Loan product not found or does not belong to your company.', 404);
            }

            $product->update($validated);

            return $this->successResponse([
                'module' => $product->product_name ?? 'Loan Product',
            ], 'Loan product updated successfully', 200);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Failed to update loan product: ' . $e->getMessage());
            return $this->errorResponse('Failed to update loan product', 500);
        }
    }

    /**
     * Enable a loan product
     */
    public function enable(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'bail|required',
            ]);

            $product = LoansProducts::where('id', $validated['id'])
                ->where('company', $this->getCompanyId())
                ->first();

            if (!$product) {
                return $this->errorResponse('Loan product not found or does not belong to your company.', 404);
            }

            $product->status = 1; // Enable the product
            $product->save();

            return $this->successResponse([], 'Loan product updated successfully', 200);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Failed to enable loan product: ' . $e->getMessage());
            return $this->errorResponse('Failed to enable loan product', 500);
        }
    }

    /**
     * Disable a loan product
     */
    public function disable(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'id' => 'bail|required',
            ]);

            $product = LoansProducts::where('id', $validated['id'])
                ->where('company', $this->getCompanyId())
                ->first();

            if (!$product) {
                return $this->errorResponse('Loan product not found or does not belong to your company.', 404);
            }

            $product->status = 2; // Disable the product
            $product->save();

            return $this->successResponse([], 'Loan product updated successfully', 200);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        } catch (\Exception $e) {
            Log::error('Failed to disable loan product: ' . $e->getMessage());
            return $this->errorResponse('Failed to disable loan product', 500);
        }
    }

    /**
     * List all products (except deleted)
     */
    public function list(): JsonResponse
    {
        try {
            $products = LoansProducts::where('status', '!=', 3)
                ->where('company', $this->getCompanyId())
                ->get();

            return $this->successResponse($products, 'Products retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to list products: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve products', 500);
        }
    }

    /**
     * List active products
     */
    public function activeList(): JsonResponse
    {
        try {
            $products = LoansProducts::where('status', 1)
                ->where('company', $this->getCompanyId())
                ->get();

            return $this->successResponse($products, 'Active products retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to list active products: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve active products', 500);
        }
    }

    /**
     * Get product details and optionally send token to customer
     */
    /* public function productDetails($product_id, $customer = null): JsonResponse
    {
        try {
            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();

            if ($customer) {
                Log::info('Customer ID: ' . $customer);
                $customerData = Customers::where('id', $customer)->first();
                Log::info('Customer Data: ' . json_encode($customerData));
                if ($customerData) {
                    $phone = $customerData->phone;
                    $token = rand(111111, 999999);
                    $message = "Habari, tokeni ya mkopo ni $token. \nUsitume tokeni hii kwa mtu yeyote kama hujaomba mkopo.";

                    $active_token = LoanToken::where('loan_customer', $customerData->id)
                        ->where('status', 1)
                        ->where('company', $user_company)
                        ->first();
                    Log::info('Active Token: ' . json_encode($active_token));

                    if (!$active_token) {
                        LoanToken::create([
                            'loan_sms' => $message,
                            'loan_token' => $token,
                            'loan_customer' => $customer,
                            'company' => $user_company,
                            'status' => 1,
                            'user' => $user_id,
                        ]);

                        $notification = new Notifications();
                        $notification->sendSMS($phone, $message);
                        Log::info('Token sent successfully');
                    }
                }
            }

            $product = LoansProducts::where(["id" => $product_id, "company" => $user_company])->first();

            if (!$product) {
                return $this->errorResponse('Product not found', 404);
            }

            return $this->successResponse($product, 'Product details retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve product details: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product details', 500);
        }
    } */
    /**
     * Get product details and optionally send token to customer
     */
    public function productDetails($product_id, $customer = null): JsonResponse
    {
        try {
            $user_company = $this->getCompanyId();
            $user_id = $this->getUserId();

            if ($customer) {
                Log::info('Customer ID: ' . $customer);
                $customerData = Customers::where('id', $customer)->first();
                Log::info('Customer Data: ' . json_encode($customerData));

                if ($customerData) {
                    $phone = $customerData->phone;
                    $token = rand(111111, 999999);
                    $message = "Habari, tokeni ya mkopo ni $token. \nUsitume tokeni hii kwa mtu yeyote kama hujaomba mkopo.";

                    $active_token = LoanToken::where('loan_customer', $customerData->id)
                        ->where('status', 1)
                        ->where('company', $user_company)
                        ->first();
                    Log::info('Active Token: ' . json_encode($active_token));

                    if (!$active_token) {
                        LoanToken::create([
                            'loan_sms' => $message,
                            'loan_token' => $token,
                            'loan_customer' => $customer,
                            'company' => $user_company,
                            'status' => 1,
                            'user' => $user_id,
                        ]);

                        $notification = new Notifications();
                        $notification->sendSMS($phone, $message);
                        Log::info('Token sent successfully');
                    }
                }
            }

            // Get the product with all fields including workflow settings
            $product = LoansProducts::where("id", $product_id)
                ->where("company", $user_company)
                ->first();

            if (!$product) {
                return $this->errorResponse('Product not found', 404);
            }

            // Return the entire product with all attributes
            return $this->successResponse($product, 'Product details retrieved successfully');
        } catch (\Exception $e) {
            Log::error('Failed to retrieve product details: ' . $e->getMessage());
            return $this->errorResponse('Failed to retrieve product details: ' . $e->getMessage(), 500);
        }
    }
}
