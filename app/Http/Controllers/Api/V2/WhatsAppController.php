<?php

namespace App\Http\Controllers\Api\V2;

use App\Http\Controllers\Controller;
use App\Models\WhatsappContact;
use App\Models\WhatsappConversation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    private string $apiUrl;
    private string $token;
    private string $phoneNumberId;
    private string $verifyToken;

    public function __construct()
    {
        $this->token         = config('services.whatsapp.token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
        $this->verifyToken   = config('services.whatsapp.verify_token');
        $this->apiUrl        = "https://graph.facebook.com/v19.0/{$this->phoneNumberId}/messages";
    }

    public function verify(Request $request)
    {
        if ($request->get('hub_mode') === 'subscribe' && $request->get('hub_verify_token') === $this->verifyToken) {
            return response($request->get('hub_challenge'), 200);
        }
        return response('Unauthorized', 403);
    }

    public function handle(Request $request)
    {
        try {
            $data = $request->all();
            $messages = $data['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

            if (!$messages) return response('OK', 200);

            $phone = $messages['from'];
            $name  = $data['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null;

            // === IMPROVED DETECTION FOR BOTH BUTTONS AND LIST ===
            if (isset($messages['interactive']['button_reply'])) {
                $body = $messages['interactive']['button_reply']['id'];
                Log::info("BUTTON CLICK from {$phone}: {$body}");
            } elseif (isset($messages['interactive']['list_reply'])) {
                $body = $messages['interactive']['list_reply']['id'];
                Log::info("LIST SELECTION from {$phone}: {$body}");
            } else {
                $body = trim($messages['text']['body'] ?? '');
                Log::info("TEXT from {$phone}: {$body}");
            }

            $contact = WhatsappContact::firstOrCreate(['phone' => $phone], ['name' => $name]);
            $contact->update(['last_seen' => now(), 'name' => $name ?? $contact->name]);

            $conversation = WhatsappConversation::firstOrCreate(
                ['whatsapp_contact_id' => $contact->id],
                ['state' => 'awaiting_choice']
            );

            $this->routeMessage($conversation, $phone, strtolower(trim($body)));
        } catch (\Exception $e) {
            Log::error('WhatsApp Error: ' . $e->getMessage());
        }

        return response('OK', 200);
    }

    private function routeMessage(WhatsappConversation $conversation, string $phone, string $input): void
    {
        Log::info("Routing - State: {$conversation->state} | Input: {$input}");

        if (in_array($input, ['menu', 'start', '0', 'habari', 'back', 'hello', 'new', 'Habari.', 'Habari'])) {
            $conversation->updateState('awaiting_choice');
            $this->sendMainMenu($phone);
            return;
        }

        $state = $conversation->state;

        switch ($state) {
            case 'awaiting_choice':
            case 'new':
                $this->handleMainMenuChoice($conversation, $phone, $input);
                break;

            case 'registration_menu':
                $this->handleRegistrationChoice($conversation, $phone, $input);
                break;
            case 'assisted_registration':
                $this->handleAssistedRegistrationInput($conversation, $phone, $input); // Note: use original $body, not lowered
                break;

            case 'training_menu':
                $this->handleTrainingChoice($conversation, $phone, $input);
                break;

            default:
                $conversation->updateState('awaiting_choice');
                $this->sendMainMenu($phone);
        }
    }

    private function handleMainMenuChoice(WhatsappConversation $conversation, string $phone, string $input): void
    {
        Log::info("Main Menu Choice: {$input}");

        switch ($input) {
            case 'menu_1':
                $conversation->updateState('registration_menu');
                Log::info("State changed to registration_menu");
                $this->sendRegistrationMessage($phone);
                break;

            case 'menu_2':
                $conversation->updateState('training_menu');
                Log::info("State changed to training_menu");
                $this->sendTrainingMessage($phone);
                break;

            case 'menu_3':
                $conversation->updateState('info_requested');
                $this->sendSupportMessage($phone);
                break;

            case 'menu_4':
                $conversation->updateState('pricing_requested');
                $this->sendPricingMessage($phone);
                break;

            default:
                $this->sendMainMenu($phone);
        }
    }

    private function handleAssistedRegistrationInput(
        WhatsappConversation $conversation,
        string $phone,
        string $body
    ): void {
        $parsed = $this->parseRegistrationDetails($body);

        // Save what we have so far
        $existing = $conversation->collected_data ?? [];
        $merged = array_merge($existing, $parsed);

        $conversation->updateState('assisted_registration', $merged);

        $required = ['company_name', 'email', 'phone', 'region', 'ceo_name'];
        $missing = [];

        foreach ($required as $field) {
            if (empty($merged[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            $this->sendMissingFieldsPrompt($phone, $missing);
            return;
        }

        // All data collected → Save
        $this->saveRegistration($conversation, $phone, $merged);
    }

    private function parseRegistrationDetails(string $body): array
    {
        $data = [];
        $lines = explode("\n", $body);

        $fieldMap = [
            'jina la kampuni' => 'company_name',
            'kampuni'         => 'company_name',
            'baruapepe'       => 'email',
            'email'           => 'email',
            'namba ya simu'   => 'phone',
            'simu'            => 'phone',
            'mkoa'            => 'region',
            'Mkoa kampuni ilipo' => 'region',
            'jina la mmiliki' => 'ceo_name',
            'mmiliki'         => 'ceo_name',
            'jina la mwenye'  => 'ceo_name',
        ];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) continue;

            [$key, $value] = explode(':', $line, 2);
            $key = strtolower(trim($key));
            $value = trim($value);

            if (empty($value)) continue;

            foreach ($fieldMap as $pattern => $field) {
                if (str_contains($key, $pattern)) {
                    $data[$field] = $value;
                    break;
                }
            }
        }

        return $data;
    }

    private function sendMissingFieldsPrompt(string $to, array $missing): void
    {
        $labels = [
            'company_name' => 'Jina la kampuni',
            'email'        => 'Baruapepe',
            'phone'        => 'Namba ya simu',
            'region'       => 'Mkoa',
            'ceo_name'     => 'Jina la mmiliki',
        ];

        $text = "Asante! Tumepokea baadhi ya maelezo.\n\nTafadhali tuma maelezo yanayokosekana:\n\n";
        foreach ($missing as $field) {
            $text .= "• " . ($labels[$field] ?? $field) . "\n";
        }

        $this->sendMessage($to, $text);
    }

    private function saveRegistration(WhatsappConversation $conversation, string $phone, array $data): void
    {
        try {
            // Prepare data for the main registration controller
            $registrationData = [
                'company_name'     => $data['company_name'],
                'company_email'    => $data['email'],
                'company_phone'    => $data['phone'],
                'company_address'  => '',           // optional
                'company_city'     => $data['region'] ?? '',
                'company_country'  => 'Tanzania',

                // Admin details (use CEO as admin for now)
                'admin_name'       => $data['ceo_name'],
                'admin_email'      => $data['email'],
                'admin_phone'      => $data['phone'],
                'admin_password'   => '123456',           // Temporary password (user can change later)
                'admin_password_confirmation' => '123456',

                'is_trial'         => true,
                'terms_agreed'     => true,
            ];

            // Call the main registration logic
            $regController = new  CompanyRegistrationController();

            $request = new Request();
            $request->merge($registrationData);
            $request->setMethod('POST');

            $response = $regController->register($request);

            $result = $response->getData(true);

            if ($result['success'] ?? false) {
                $conversation->updateState('completed');

                $this->sendMessage(
                    $phone,
                    "✅ Usajili umekamilika kwa mafanikio!\n\n" .
                        "Kampuni: {$data['company_name']}\n" .
                        "Mmiliki: {$data['ceo_name']}\n\n" .
                        "Username: {$data['email']}\n" .
                        "Password: 123456\n\n" .
                        "Link: https://app.flux.co.tz\n\n" .
                        "Jaribio la bure la siku 7 limeanza.\n\n" .
                        "Utapata jumbe ya kuingia kwa namba ya simu ulio sajilia."
                );

                Log::info("WhatsApp Assisted Registration Success for {$phone}", $data);

                // After successful registration
                $this->notifyAdmin(
                    "🔔 *New Registration via WhatsApp*\n\n" .
                        "🏢 Company: {$data['company_name']}\n" .
                        "👤 CEO: {$data['ceo_name']}\n" .
                        "📧 Email: {$data['email']}\n" .
                        "📱 Phone: {$phone}\n" .
                        "📍 Region: " . ($data['region'] ?? 'N/A') . "\n" .
                        "⏰ Time: " . now()->format('d M Y, H:i')
                );
            } else {
                $this->sendMessage($phone, "❌ Kuna tatizo katika usajili. Tafadhali jaribu tena au wasiliana nasi.");
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp Registration Failed: " . $e->getMessage());
            $this->sendMessage($phone, "❌ Samahani, kuna tatizo la kiufundi. Tafadhali jaribu baadaye.");
        }
    }

    private function sendMainMenu(string $to): void
    {
        $body = "Karibu TerminalXI! 🎉\n\nKwa Masaada wa haraka wasiliana nasi kwa namba : +255799713285\n\nChagua huduma unayotaka:";

        $buttons = [
            'menu_1' => 'Kujiandikisha',
            'menu_2' => 'Mafunzo ya mfumo',
            'menu_4' => 'Gharama za mfumo',
            //'menu_3' => 'Ongea na support'
        ];

        $this->sendInteractiveMessage($to, $body, $buttons);
    }

    private function sendRegistrationMessage(string $to): void
    {
        $body = "📋 Jinsi ya Kujiandikisha 🎉\n\nChagua jinsi unavyotaka kujiandikisha:";

        $buttons = [
            'self_registration' => 'Najiandikisha',
            'assisted_registration' => 'Nisaidiwe',
            'new' => 'Rudi Menu Kuu'
        ];

        $this->sendInteractiveMessage($to, $body, $buttons);
    }

    private function handleRegistrationChoice(WhatsappConversation $conversation, string $phone, string $input): void
    {
        switch ($input) {
            case 'self_registration':
                $conversation->updateState('self_registration');
                $message = "Tafadhali tumia link hapa chini kujisajili :\nhttps://terminalxi.com/register\n\n✅ Una siku 7 za kujaribu BURE!";
                $this->sendMessage($phone, $message);
                break;

            case 'assisted_registration':
                $conversation->updateState('assisted_registration');
                $this->sendAssistedRegistrationPrompt($phone);
                break;

            case 'new':
                $conversation->updateState('awaiting_choice');
                $this->sendMainMenu($phone);
                break;

            default:
                $this->sendRegistrationMessage($phone);
        }
    }

    private function sendTrainingMessage(string $to): void
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'list',
                'header' => [
                    'type' => 'text',
                    'text' => '📚 Mafunzo ya TerminalXI'
                ],
                'body'   => [
                    'text' => 'Chagua aina ya mafunzo unayotaka kujifunza:'
                ],
                'footer' => [
                    'text' => 'TerminalXI'
                ],
                'action' => [
                    'button'   => 'Chagua Mafunzo',
                    'sections' => [
                        [
                            'title' => 'Mafunzo Yanayopatikana',
                            'rows'  => [
                                ['id' => 'train_settings',   'title' => 'Mpangilio wa Mfumo'],
                                /*  ['id' => 'train_users',      'title' => 'Kusimamia Watumiaji'], */
                                /*   ['id' => 'train_bank',       'title' => 'Benki na Malipo'], */
                                ['id' => 'train_customers',  'title' => 'Kusimamia Wateja'],
                                ['id' => 'train_loans',      'title' => 'Kutoa Mikopo'],
                                ['id' => 'train_payments',   'title' => 'Marejesho ya Mikopo'],
                                /* ['id' => 'train_income',     'title' => 'Mapato'], */
                                /* ['id' => 'train_expenses',   'title' => 'Matumizi'], */
                                /* ['id' => 'train_reports',    'title' => 'Ripoti na Takwimu'], */


                            ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withToken($this->token)->post($this->apiUrl, $payload);

            if (!$response->successful()) {
                Log::error("List Message Failed", $response->json());
                // Fallback to normal text if list fails
                $this->sendMessage($to, "Mafunzo yanapatikana. Tafadhali andika *menu* kurudi nyumbani.");
            }
        } catch (\Exception $e) {
            Log::error("Training menu error: " . $e->getMessage());
        }
    }
    private function handleTrainingChoice(WhatsappConversation $conversation, string $phone, string $input): void
    {
        Log::info("Training Choice Received: " . $input);   // ← Important for debugging

        switch ($input) {
            case 'train_settings':
                Log::info("Sending Mpangilio wa Mfumo PDF to {$phone}");

                $this->sendMessage($phone, "📘 Mafunzo: Mpangilio wa Mfumo\n\nInatuma PDF...");

                $this->sendAttachment(
                    $phone,
                    "https://t11.auth.flux.co.tz/tutorials/pdf/settings.pdf",
                    "Mwongozo wa Mpangilio wa Mfumo",
                    "document"
                );
                break;

            case 'train_customers':
                Log::info("Sending Customers PDF to {$phone}");

                $this->sendMessage($phone, "📘 Mafunzo: Kusimamia Wateja\n\nInatuma PDF...");

                $this->sendAttachment(
                    $phone,
                    "https://t11.auth.flux.co.tz/tutorials/pdf/customers.pdf",
                    "Mwongozo wa Kusimamia Wateja",
                    "document"
                );
                break;

            case 'train_loans':
                Log::info("Sending Loans PDF to {$phone}");

                $this->sendMessage($phone, "📘 Mafunzo: Kutoa Mikopo\n\nInatuma PDF...");

                $this->sendAttachment(
                    $phone,
                    "https://t11.auth.flux.co.tz/tutorials/pdf/loans.pdf",
                    "Mwongozo wa Kutoa Mikopo",
                    "document"
                );
                break;

            case 'train_payments':
                Log::info("Sending Payments PDF to {$phone}");

                $this->sendMessage($phone, "📘 Mafunzo: Malipo na Marejesho\n\nInatuma PDF...");

                $this->sendAttachment(
                    $phone,
                    "https://t11.auth.flux.co.tz/tutorials/pdf/payments.pdf",
                    "Mwongozo wa Malipo na Marejesho",
                    "document"
                );
                break;

            default:
                Log::warning("Unknown training option: " . $input);
                $this->sendMessage($phone, "Mafunzo yanakuja hivi karibuni.\n\nAndika *menu* kurudi nyumbani.");
        }
    }

    /**
     * Send Attachment (PDF, Image, Document, etc.)
     */
    private function sendAttachment(string $to, string $fileUrl, string $caption = "", string $type = "document"): void
    {
        $mediaType = match ($type) {
            'image'     => 'image',
            'video'     => 'video',
            'audio'     => 'audio',
            default     => 'document',
        };

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $mediaType,
        ];

        if ($mediaType === 'document') {
            $payload['document'] = [
                'link'     => $fileUrl,
                'caption'  => $caption,
                'filename' => 'customers.pdf'   // You can customize filename
            ];
        } elseif ($mediaType === 'image') {
            $payload['image'] = [
                'link' => $fileUrl,
                'caption' => $caption
            ];
        } else {
            $payload[$mediaType] = ['link' => $fileUrl];
        }

        try {
            $response = Http::withToken($this->token)->post($this->apiUrl, $payload);

            if (!$response->successful()) {
                Log::error("Failed to send PDF", $response->json());
                $this->sendMessage($to, "Samahani, PDF haikuweza kutumwa. Jaribu baadaye.");
            } else {
                Log::info("PDF sent successfully to {$to}");
            }
        } catch (\Exception $e) {
            Log::error("Attachment error: " . $e->getMessage());
            $this->sendMessage($to, "Kuna tatizo katika kutuma faili.");
        }
    }

    private function sendSupportMessage(string $to)
    {
        $this->sendMessage($to, "Kitengo cha msaada kitawasiliana nawe muda si mrefu.\nAu wasiliana na mtoa huduma wetu kwa namba 0799713285");
        $this->notifyAdmin(
            "🚨 *Support Request from WhatsApp*\n\n" .
                "👤 Name: " . ($contact->name ?? 'Unknown') . "\n" .
                "📱 Phone: {$to}\n" .
                "⏰ Time: " . now()->format('d M Y, H:i')
        );
    }
    private function sendPricingMessage(string $to)
    {
        $message = "💰 *Bei za Mpango wa TerminalXI*\n\n";
        $message .= "Chagua mpango unaokufaa. Unaweza kulipa kila mwezi au kwa miezi 24 (punguzo la hadi 12%)\n\n";

        $message .= "──────────────────\n";
        $message .= "📌 *BASIC*\n";
        $message .= "35,000 TZS / mwezi\n\n";
        $message .= "• Wateja hadi 50\n";
        $message .= "• Tawi 1\n";
        $message .= "• Kanda 2\n";
        $message .= "• Watumiaji 5\n";
        $message .= "• Mikopo hai 55\n\n";

        $message .= "──────────────────\n";
        $message .= "📌 *MEDIUM*\n";
        $message .= "50,000 TZS / mwezi\n\n";
        $message .= "• Wateja hadi 100\n";
        $message .= "• Matawi 3\n";
        $message .= "• Kanda 9\n";
        $message .= "• Watumiaji 16\n";
        $message .= "• Mikopo hai 80\n\n";

        $message .= "──────────────────\n";
        $message .= "📌 *ADVANCE*\n";
        $message .= "80,000 TZS / mwezi\n\n";
        $message .= "• Wateja hadi 200\n";
        $message .= "• Matawi 10\n";
        $message .= "• Kanda 30\n";
        $message .= "• Watumiaji 45\n";
        $message .= "• Mikopo hai 150\n\n";

        $message .= "──────────────────\n";
        $message .= "📌 *PRO*\n";
        $message .= "150,000 TZS / mwezi\n\n";
        $message .= "• Wateja hadi 300\n";
        $message .= "• Matawi 50\n";
        $message .= "• Kanda 150\n";
        $message .= "• Watumiaji 250\n";
        $message .= "• Mikopo hai 250\n\n";

        $message .= "──────────────────\n";
        $message .= "📌 *ENTERPRISE*\n";
        $message .= "250,000 TZS / mwezi\n\n";
        $message .= "• Wateja ∞ (Unlimited)\n";
        $message .= "• Matawi ∞\n";
        $message .= "• Kanda ∞\n";
        $message .= "• Watumiaji ∞\n";
        $message .= "• Mikopo hai 500\n\n";

        $message .= "──────────────────\n";
        $message .= "_Andika *menu* kurudi nyumbani_\n";
        $message .= "Wasiliana nasi kupata punguzo au maelezo zaidi.";

        $this->sendMessage($to, $message);

        // Optional: Notify Admin
        $this->notifyAdmin("📋 *User Requested Pricing*\nPhone: {$to}");
        $conversation = WhatsAppConversation::where('phone', $to)->first();
        $conversation->updateState('completed');
    }

    private function sendAssistedRegistrationPrompt(string $to): void
    {
        $message = "✅ Sawa, nitakusaidia kujisajili.\n\n";
        $message .= "Tafadhali tuma maelezo yako yote kwa muundo huu:\n\n";
        $message .= "Jina la kampuni: \n";
        $message .= "Baruapepe: \n";
        $message .= "Namba ya simu: \n";
        $message .= "Mkoa: \n";
        $message .= "Jina la mmiliki: \n\n";
        $message .= "Nakili na ujaze kisha unitumie.\n\n";
        $message .= "Mfano: \nJina la kampuni: ABC MICROFINANCE\nBaruapepe: abc@gmail.com\nNamba ya simu: 0799713285\nMkoa: Dar es Salaam\nJina la mmiliki: Halfa Mnyimvua\n\n";

        $this->sendMessage($to, $message);
    }

    // ====================== SEND INTERACTIVE ======================
    private function sendInteractiveMessage(string $to, string $body, array $buttons, string $footer = "TerminalXI"): void
    {
        $buttonArray = [];
        foreach ($buttons as $id => $title) {
            $buttonArray[] = [
                'type' => 'reply',
                'reply' => ['id' => $id, 'title' => $title]
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => $body],
                'footer' => ['text' => $footer],
                'action' => ['buttons' => $buttonArray]
            ]
        ];

        $response = Http::withToken($this->token)->post($this->apiUrl, $payload);

        if (!$response->successful()) {
            Log::error("Failed to send interactive message", $response->json());
        } else {
            Log::info("Interactive message sent successfully to {$to}");
        }
    }

    private function sendMessage(string $to, string $message): void
    {
        Http::withToken($this->token)->post($this->apiUrl, [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => ['body' => $message]
        ]);
    }

    /**
     * Send Notification to Admin
     */
    private function notifyAdmin(string $message): void
    {
        $adminPhone = config('services.whatsapp.admin_phone'); // From .env

        if (empty($adminPhone)) {
            Log::warning("Admin phone not configured in .env");
            return;
        }

        // Clean phone number (remove + if exists)
        $adminPhone = ltrim($adminPhone, '+');

        try {
            $response = Http::withToken($this->token)
                ->post($this->apiUrl, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type'    => 'individual',
                    'to'                => $adminPhone,
                    'type'              => 'text',
                    'text'              => [
                        'body' => $message
                    ]
                ]);

            if ($response->successful()) {
                Log::info("Admin notification sent successfully");
            } else {
                Log::error("Failed to notify admin", $response->json());
            }
        } catch (\Exception $e) {
            Log::error("Admin notification error: " . $e->getMessage());
        }
    }
}
