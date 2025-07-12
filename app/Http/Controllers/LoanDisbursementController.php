<?php

namespace App\Http\Controllers;

use App\Models\Loans;
use App\Services\AirtelMoneyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoanDisbursementController extends Controller
{
    protected $airtelMoneyService;

    public function __construct(AirtelMoneyService $airtelMoneyService)
    {
        //$this->middleware('auth'); // Ensure only authenticated managers can disburse
        $this->airtelMoneyService = $airtelMoneyService;
    }

    public function showDisburseForm()
    {
        return view('loans.disburse');
    }

    //public function disburse(Request $request)
    public function disburse()
    {
        /* $request->validate([
            'msisdn' => 'required|regex:/^\+?[1-9]\d{1,14}$/', // Validate phone number
            'amount' => 'required|numeric|min:1',
            'wallet_type' => 'required|in:SALARY,NORMAL',
            'type' => 'required|in:B2C,B2B',
            'country' => 'required|string',
            'currency' => 'required|string',
        ]); */

       /*  $result = $this->airtelMoneyService->disburseFunds(
            $request->msisdn,
            $request->amount,
            $request->wallet_type,
            $request->type,
            $request->country,
            $request->currency
        ); */

        $result = $this->airtelMoneyService->disburseFunds(
            684823797,
            1000,
            'NORMAL',
            'B2C',
            'TZ',
            'TZS'
        );

        if ($result['success']) {
            // Update loan record (example)
           /*  Loans::where('id', $request->loan_id)->update([
                'disbursed_at' => now(),
                'status' => 'disbursed',
            ]);

            return redirect()->route('loans.index')->with('success', 'Funds disbursed successfully!'); */
            return response()->json([
                'status' => 'success',
                'message' => 'Funds disbursed successfully!',
                'data' => $result['data'],
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $result['message'],
        ], 400);
    }
}