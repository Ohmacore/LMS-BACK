<?php

namespace App\Http\Controllers\Api;

use App\Models\Module;
use App\Models\Transaction;
use App\Models\Enrollment;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Carbon\Carbon;

class TeacherDashboardController extends Controller
{
    /**
     * Get dashboard summary statistics
     */
    public function stats(Request $request)
    {
        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        // Get module IDs for this teacher
        $moduleIds = Module::where('teacher_id', $teacher->id)->pluck('id');

        // Total modules
        $totalModules = $moduleIds->count();

        // Total active students (unique students enrolled in any module/chapter)
        $totalStudents = Enrollment::whereIn('module_id', $moduleIds)
            ->where('status', 'active')
            ->distinct('student_id')
            ->count('student_id');

        // Revenue calculations
        $transactionsQuery = Transaction::whereIn('module_id', $moduleIds)
            ->where('type', 'purchase');

        $totalEarnings = abs((clone $transactionsQuery)
            ->where('status', 'completed')
            ->sum('amount'));

        $pendingEarnings = abs((clone $transactionsQuery)
            ->where('status', 'pending')
            ->sum('amount'));

        return response()->json([
            'total_modules' => $totalModules,
            'total_students' => $totalStudents,
            'total_earnings' => (float)$totalEarnings,
            'pending_earnings' => (float)$pendingEarnings,
        ]);
    }

    /**
     * Get detailed earnings and transactions
     */
    public function earnings(Request $request)
    {
        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        $moduleIds = Module::where('teacher_id', $teacher->id)->pluck('id');

        $transactionsQuery = Transaction::with(['student.user', 'module'])
            ->whereIn('module_id', $moduleIds)
            ->where('type', 'purchase');

        $totalEarnings = abs((clone $transactionsQuery)
            ->where('status', 'completed')
            ->sum('amount'));

        $pendingEarnings = abs((clone $transactionsQuery)
            ->where('status', 'pending')
            ->sum('amount'));

        $thisMonthEarnings = abs((clone $transactionsQuery)
            ->where('status', 'completed')
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->sum('amount'));

        $transactions = (clone $transactionsQuery)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'amount' => abs((float)$transaction->amount),
                    'status' => $transaction->status,
                    'created_at' => $transaction->created_at,
                    'description' => "Inscription de l'étudiant {$transaction->student->user->name} au module {$transaction->module->name}",
                ];
            });

        return response()->json([
            'total_earnings' => (float)$totalEarnings,
            'pending_earnings' => (float)$pendingEarnings,
            'this_month_earnings' => (float)$thisMonthEarnings,
            'transactions' => $transactions,
        ]);
    }
}
