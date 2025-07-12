<?php

namespace App\Http\Controllers;

use App\Services\PaymentGatewayService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    protected $paymentGatewayService;

    public function __construct(PaymentGatewayService $paymentGatewayService)
    {
        $this->paymentGatewayService = $paymentGatewayService;
    }

    public function generateToken(): JsonResponse
    {
        $result = $this->paymentGatewayService->generateToken();

        if ($result['success']) {
            return response()->json([
                'status' => 'success',
                'data' => $result['data'],
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => $result['error'],
        ], 400);
    }

    public function getToken(): JsonResponse
    {
        $result = $this->paymentGatewayService->getValidToken();

        if ($result['success']) {
           /*  return response()->json([
                'status' => 'success',
                'data' => $result['data'],
            ], 200); */
            return response()->json([
                'status' => 'success',
                'token' => $result['token'],
            ], 200);
        }

        return response()->json([
            'status' => 'error',
            'message' => $result['error'],
        ], 400);
    }
}