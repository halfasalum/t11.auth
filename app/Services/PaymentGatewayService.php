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
    protected $checkoutBaseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $appName;

    public function __construct()
    {
        $this->baseUrl = config('services.payment_gateway.base_url');
        $this->checkoutBaseUrl = config('services.payment_gateway.checkout_base_url');
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
            return [
                'success' => true,
                'data' => [
                    'access_token' => $token->access_token,
                    'expires_at' => $token->expires_at,
                ],
            ];
        }

        return $this->generateToken();
    }

    /**
     * Push a mobile-money checkout request (M-Pesa, Mixx by Yas/Tigo, Airtel
     * Money, HaloPesa) to the given number — this triggers a USSD PIN
     * prompt on the customer's phone. The actual payment result arrives
     * later via AzamPay's callback, not in this response; a 'pending'
     * result here only means the push was accepted, not that money moved.
     *
     * @param string $msisdn Local (0xxx) or international (255xxx) format
     * @param float $amount
     * @param string $provider One of: Mpesa, Tigo, Airtel, Halopesa, Azampesa
     * @param string $externalId Our own idempotency key for this attempt
     * @return array{success: bool, data?: array, error?: string}
     */
    public function initiateMnoCheckout(string $msisdn, float $amount, string $provider, string $externalId): array
    {
        if (empty($this->checkoutBaseUrl)) {
            return [
                'success' => false,
                'error' => 'services.payment_gateway.checkout_base_url is not configured on this server — '
                    . 'set PAYMENT_GATEWAY_CHECKOUT_BASE_URL in .env (or deploy the config/services.php update '
                    . 'that added it) rather than letting the request go out with no host.',
            ];
        }

        $tokenResult = $this->getValidToken();

        if (! ($tokenResult['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $tokenResult['error'] ?? 'Could not obtain a payment gateway token',
            ];
        }

        $accessToken = $tokenResult['data']['access_token'];

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$accessToken}",
                'X-API-KEY' => $accessToken,
            ])->post("{$this->checkoutBaseUrl}/azampay/mno/checkout", [
                'accountNumber' => $this->normalizeMsisdn($msisdn),
                'amount' => (string) $amount,
                'currency' => 'TZS',
                'externalId' => $externalId,
                'provider' => $provider,
            ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => 'Checkout request failed: ' . $response->body(),
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
     * Local (0xxxxxxxxx) or already-international (255xxxxxxxxx / +255...)
     * input, normalized to the 255xxxxxxxxx form AzamPay expects.
     */
    public function normalizeMsisdn(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($digits) === 10 && str_starts_with($digits, '0')) {
            return '255' . substr($digits, 1);
        }

        if (strlen($digits) === 9) {
            return '255' . $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '255')) {
            return $digits;
        }

        return $digits;
    }
}