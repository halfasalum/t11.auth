<?php

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tymon\JWTAuth\Facades\JWTAuth;

class BaseController
{
    /**
     * Get authenticated user payload
     */
    protected function getUserPayload(): array
    {
        return JWTAuth::parseToken()->getPayload()->toArray();
    }
    
    /**
     * Get authenticated user ID
     */
    protected function getUserId(): int
    {
        return $this->getUserPayload()['user_id'];
    }
    
    /**
     * Get authenticated user's company ID
     */
    protected function getCompanyId(): int
    {
        return $this->getUserPayload()['company'];
    }
    
    /**
     * Get authenticated user's permissions
     */
    protected function getUserPermissions(): array
    {
        return $this->getUserPayload()['controls'] ?? [];
    }
    
    /**
     * Check if user has a specific permission
     */
    protected function hasPermission(int $permissionId): bool
    {
        return in_array($permissionId, $this->getUserPermissions());
    }
    
    /**
     * Check if user has any of the given permissions
     */
    protected function hasAnyPermission(array $permissionIds): bool
    {
        return !empty(array_intersect($permissionIds, $this->getUserPermissions()));
    }
    
    /**
     * Get user's assigned zones
     */
    protected function getUserZones(): array
    {
        return $this->getUserPayload()['zonesId'] ?? [];
    }
    
    /**
     * Get user's assigned branches
     */
    protected function getUserBranches(): array
    {
        return $this->getUserPayload()['branchesId'] ?? [];
    }
    
    /**
     * Success response
     */
    protected function successResponse($data = null, string $message = 'Success', int $code = 200): JsonResponse
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
    protected function errorResponse(string $message, int $code = 400, $errors = null): JsonResponse
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
    protected function validationErrorResponse(ValidationException $e): JsonResponse
    {
        return $this->errorResponse('Validation failed', 422, $e->errors());
    }
    
    /**
     * Paginate data with consistent format
     */
    protected function paginateResponse($paginator): array
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
    
    /**
     * Parse number from string
     */
    protected function parseNumber($value): float
    {
        if (is_null($value)) return 0;
        if (is_numeric($value)) return (float) $value;
        return (float) preg_replace('/[^0-9.-]/', '', $value);
    }
    
    /**
     * Format currency
     */
    protected function formatCurrency($value): string
    {
        return number_format($this->parseNumber($value), 2, '.', ',');
    }
}