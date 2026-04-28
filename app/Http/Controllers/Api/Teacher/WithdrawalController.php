<?php

namespace App\Http\Controllers\Api\Teacher;

use App\Models\Module;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class WithdrawalController extends Controller
{
    /**
     * List the authenticated teacher's withdrawal history
     */
    public function index(Request $request)
    {
        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $withdrawals = Withdrawal::where('teacher_id', $teacher->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'withdrawals' => $withdrawals,
        ]);
    }

    /**
     * Create a new withdrawal request
     */
    public function store(Request $request)
    {
        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:100',
            'payment_method' => 'required|string|in:CCP,Baridimob,BaridiPay',
            'payment_details' => 'required|string|min:5',
        ]);

        // Calculate total completed earnings
        $moduleIds = Module::where('teacher_id', $teacher->id)->pluck('id');

        $totalEarnings = abs(
            Transaction::whereIn('module_id', $moduleIds)
                ->where('type', 'purchase')
                ->where('status', 'completed')
                ->sum('amount')
        );

        // Calculate already withdrawn or in-progress amounts (everything except rejected)
        $withdrawnAmount = Withdrawal::where('teacher_id', $teacher->id)
            ->whereIn('status', ['pending', 'in_treatment', 'transferred'])
            ->sum('amount');

        $availableBalance = $totalEarnings - $withdrawnAmount;

        if ($request->amount > $availableBalance) {
            return response()->json([
                'message' => 'Solde insuffisant. Votre solde disponible est de ' . number_format($availableBalance, 2) . ' DZD.',
            ], 422);
        }

        $withdrawal = Withdrawal::create([
            'teacher_id' => $teacher->id,
            'amount' => $request->amount,
            'status' => 'pending',
            'payment_method' => $request->payment_method,
            'payment_details' => $request->payment_details,
        ]);

        return response()->json([
            'message' => 'Demande de retrait créée avec succès.',
            'withdrawal' => $withdrawal,
        ], 201);
    }
}
