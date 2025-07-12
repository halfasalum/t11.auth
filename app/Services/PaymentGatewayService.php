<?php

namespace App\Services;

use App\Models\PaymentToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use Carbon\Carbon;
use Tymon\JWTAuth\Facades\JWTAuth;

class PaymentGatewayService
{
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $appName;

    public function __construct()
    {
        $this->baseUrl = config('services.payment_gateway.base_url');
        $this->clientId = config('services.payment_gateway.client_id');
        $this->clientSecret = config('services.payment_gateway.client_secret');
        $this->appName = config('services.payment_gateway.app_name');
    }

    /**
     * Generate and store authentication token
     * @return array
     * @throws RequestException
     */
    public function generateToken(): array
    {
        //$user = JWTAuth::parseToken()->getPayload();
        //$user_company = $user->get('company');
        //$user_id = $user->get('user_id');
        try {
            $response = Http::post("{$this->baseUrl}/AppRegistration/GenerateToken", [
                'appName' => $this->appName,
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Store the token
                $token = PaymentToken::create([
                    //'user_id' => $user_id,
                    //'company_id' => $user_company,
                    'user_id' => 1, // Placeholder for user ID, adjust as needed
                    'company_id' => 1, // Placeholder for company ID, adjust as needed
                    'access_token' => $data['data']['accessToken'],
                    'expires_at' => Carbon::parse($data['data']['expire']),
                ]);

                return [
                    'success' => true,
                    'data' => [
                        'access_token' => $token->access_token,
                        'expires_at' => $token->expires_at,
                    ],
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to generate token: ' . $response->body(),
            ];
        } catch (RequestException $e) {
            return [
                'success' => false,
                'error' => 'Request failed: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Unexpected error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get valid token or generate new one
     * @return array
     */
    public function getValidToken(): array
    {
        //$user = JWTAuth::parseToken()->getPayload();
        //$user_company = $user->get('company');
        //$user_id = $user->get('user_id');
        //$token = PaymentToken::where(['user_id' => $user_id, 'company_id' => $user_company])
        $token = PaymentToken::
        latest()
        ->first();

        if ($token && $token->isValid()) {
            /* return [
                'success' => true,
                'data' => [
                    'access_token' => $token->access_token,
                    'expires_at' => $token->expires_at,
                ],
            ]; */
            return [
                'success' => true,
                'token' => $token->access_token,
            ];
        }

        return $this->generateToken();
    }
}