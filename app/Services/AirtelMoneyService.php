<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AirtelMoneyService
{
    protected $client;
    protected $baseUrl;
    protected $clientId;
    protected $clientSecret;
    protected $xKey;
    protected $xSignature;
    protected $publicKey;

    public function __construct()
    {
        $this->client = new Client();
        $this->baseUrl = config('services.airtel_money.base_url', 'https://openapiuat.airtel.africa');
        $this->clientId = config('services.airtel_money.client_id');
        $this->clientSecret = config('services.airtel_money.client_secret');
        $this->xKey = config('services.airtel_money.x_key');
        $this->xSignature = config('services.airtel_money.x_signature');
        $this->publicKey = config('services.airtel_money.public_key');
    }

    /**
     * Fetch OAuth 2.0 access token
     */
    public function getAuthToken()
    {
        $cacheKey = 'airtel_money_access_token';
        if ($cached = cache()->get($cacheKey)) {
            return [
                'success' => true,
                'access_token' => $cached['access_token'],
                'expires_in' => $cached['expires_in'],
            ];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => '*/*',
        ];

        $requestBody = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'client_credentials',
        ];

        try {
            $response = $this->client->post("{$this->baseUrl}/auth/oauth2/token", [
                'headers' => $headers,
                'json' => $requestBody,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            cache()->put($cacheKey, [
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'],
            ], now()->addSeconds($data['expires_in'] - 300));

            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'],
            ];
        } catch (RequestException $e) {
            Log::error('Airtel Money Auth Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Encrypt PIN using RSA
     */
    protected function encryptPin($pin)
    {
        if (strlen($pin) !== 4 || !is_numeric($pin)) {
            throw new \InvalidArgumentException('PIN must be a 4-digit number');
        }

        $publicKey = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($this->publicKey, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $keyResource = openssl_get_publickey($publicKey);

        if ($keyResource === false) {
            throw new \Exception('Invalid public key');
        }

        $encrypted = '';
        $config = [
            'digest_alg' => 'sha256',
            'padding' => OPENSSL_PKCS1_OAEP_PADDING,
        ];

        $result = openssl_public_encrypt($pin, $encrypted, $keyResource, $config['padding']);
        openssl_free_key($keyResource);

        if ($result === false) {
            throw new \Exception('PIN encryption failed');
        }

        return base64_encode($encrypted);
    }

    /**
     * Disburse funds to a recipient
     */
    public function disburseFunds($msisdn, $amount, $walletType = 'NORMAL', $type = 'B2C', $country = 'ZM', $currency = 'ZMW')
    {
        $auth = $this->getAuthToken();

        if (!$auth['success']) {
            return [
                'success' => false,
                'message' => 'Authentication failed: ' . $auth['message'],
            ];
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => '*/*',
            'X-Country' => $country,
            'X-Currency' => $currency,
            'Authorization' => 'Bearer ' . $auth['access_token'],
            //'x-signature' => $this->xSignature,
            //'x-key' => $this->xKey,
        ];

        $transactionId = 'AB' . Str::random(10);
        $pin = $this->encryptPin('1832'); // Replace with dynamic 4-digit PIN

        $requestBody = [
            'payee' => [
                'msisdn' => $msisdn,
                'wallet_type' => $walletType, // SALARY or NORMAL
            ],
            'reference' => $transactionId,
            'pin' => $pin,
            'transaction' => [
                'amount' => $amount,
                'id' => $transactionId,
                'type' => $type, // B2C or B2B
            ],
        ];

        try {
            $response = $this->client->post("{$this->baseUrl}/standard/v3/disbursements", [
                'headers' => $headers,
                'json' => $requestBody,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'success' => true,
                'data' => $data,
            ];
        } catch (RequestException $e) {
            Log::error('Airtel Money Disbursement Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}