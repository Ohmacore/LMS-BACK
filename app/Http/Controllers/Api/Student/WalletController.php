<?php

namespace App\Http\Controllers\Api\Student;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;

class WalletController extends Controller
{
    /**
     * Get current wallet balance
     */
    public function index(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        return response()->json([
            'balance' => $student->wallet_balance,
            'currency' => 'DZD',
            'referral_code' => $student->referral_code,
        ]);
    }

    /**
     * Request wallet recharge (upload receipt)
     */
    public function recharge(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:100',
            'receipt' => 'required|image|max:5120', // 5MB max
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        // Store receipt image
        $receiptPath = $request->file('receipt')->store('receipts', 'public');

        // Create pending transaction
        $transaction = Transaction::create([
            'student_id' => $student->id,
            'type' => 'deposit',
            'amount' => $request->amount,
            'status' => 'pending',
            'receipt_url' => $receiptPath,
            'description' => 'Recharge wallet - BaridiMob',
        ]);

        return response()->json([
            'message' => 'Recharge request submitted successfully. You will be notified once validated.',
            'transaction' => [
                'id' => $transaction->id,
                'amount' => $transaction->amount,
                'status' => $transaction->status,
                'created_at' => $transaction->created_at,
            ],
        ], 201);
    }

    /**
     * Get transaction history
     */
    public function transactions(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        $transactions = Transaction::where('student_id', $student->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'amount' => $transaction->amount,
                    'status' => $transaction->status,
                    'description' => $transaction->description,
                    'created_at' => $transaction->created_at,
                    'validated_at' => $transaction->validated_at,
                ];
            });

        return response()->json(['transactions' => $transactions]);
    }
}
