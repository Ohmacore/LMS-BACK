<?php

namespace App\Http\Controllers\Api\Student;

use App\Models\Module;
use App\Models\Folder;
use App\Models\Enrollment;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

class EnrollmentController extends Controller
{
    /**
     * Purchase enrollment in a module (by chapter or full module)
     */
    public function enroll(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'module_id' => 'required|exists:modules,id',
            'subscription_type' => 'required|in:chapter,full',
            'chapter_id' => 'required_if:subscription_type,chapter|nullable|exists:folders,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        $module = Module::findOrFail($request->module_id);

        if ($module->status !== 'active') {
            return response()->json(['message' => 'Ce module n’est pas disponible pour inscription'], 403);
        }

        // Check for existing enrollment based on type
        if ($request->subscription_type === 'full') {
            // Check if already has full access
            $existingFull = Enrollment::where('student_id', $student->id)
                ->where('module_id', $module->id)
                ->where('subscription_type', 'full')
                ->where('status', 'active')
                ->first();

            if ($existingFull) {
                return response()->json(['message' => 'Vous avez déjà un accès complet à ce module'], 400);
            }
        } elseif ($request->subscription_type === 'chapter') {
            // Check if already enrolled in this specific chapter
            $existingChapter = Enrollment::where('student_id', $student->id)
                ->where('module_id', $module->id)
                ->where('subscription_type', 'chapter')
                ->where('chapter_id', $request->chapter_id)
                ->where('status', 'active')
                ->first();

            if ($existingChapter) {
                return response()->json(['message' => 'Vous êtes déjà inscrit à ce chapitre'], 400);
            }

            // Check if already has full access (no need to buy chapter)
            $existingFull = Enrollment::where('student_id', $student->id)
                ->where('module_id', $module->id)
                ->where('subscription_type', 'full')
                ->where('status', 'active')
                ->first();

            if ($existingFull) {
                return response()->json(['message' => 'Vous avez déjà un accès complet à ce module'], 400);
            }

            // Verify the chapter belongs to this module
            $chapter = Folder::where('id', $request->chapter_id)
                ->where('module_id', $module->id)
                ->whereNull('parent_folder_id')
                ->first();

            if (!$chapter) {
                return response()->json(['message' => 'Chapitre invalide pour ce module'], 422);
            }
        }

        // Calculate price
        $price = $this->calculatePrice($module, $request->subscription_type, $request->chapter_id, $student);

        // Check wallet balance
        if ($student->wallet_balance < $price) {
            return response()->json([
                'message' => 'Solde insuffisant',
                'required' => $price,
                'current_balance' => $student->wallet_balance,
                'shortfall' => $price - $student->wallet_balance,
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Deduct from wallet
            $student->wallet_balance -= $price;
            $student->save();

            // Create enrollment
            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'module_id' => $module->id,
                'subscription_type' => $request->subscription_type,
                'chapter_id' => $request->subscription_type === 'chapter' ? $request->chapter_id : null,
                'resource_types' => null,
                'status' => 'active',
                'expires_at' => null,
            ]);

            // Build description
            $description = "Inscription au module {$module->name}";
            if ($request->subscription_type === 'chapter') {
                $chapterName = Folder::find($request->chapter_id)?->name ?? 'Chapitre';
                $description = "Inscription au chapitre \"{$chapterName}\" du module {$module->name}";
            }

            // Create transaction record
            Transaction::create([
                'student_id' => $student->id,
                'type' => 'purchase',
                'amount' => -$price,
                'status' => 'completed',
                'description' => $description,
                'module_id' => $module->id,
                'enrollment_id' => $enrollment->id,
            ]);

            // If buying full module, deactivate any existing chapter enrollments
            if ($request->subscription_type === 'full') {
                Enrollment::where('student_id', $student->id)
                    ->where('module_id', $module->id)
                    ->where('subscription_type', 'chapter')
                    ->where('status', 'active')
                    ->where('id', '!=', $enrollment->id)
                    ->update(['status' => 'expired']);
            }

            DB::commit();

            return response()->json([
                'message' => 'Inscription réussie !',
                'enrollment' => [
                    'id' => $enrollment->id,
                    'module_id' => $enrollment->module_id,
                    'subscription_type' => $enrollment->subscription_type,
                    'chapter_id' => $enrollment->chapter_id,
                    'created_at' => $enrollment->created_at,
                ],
                'new_balance' => $student->wallet_balance,
                'amount_paid' => $price,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Inscription échouée', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get student's active enrollments
     */
    public function index(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        $enrollments = Enrollment::with(['module.teacher.user'])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->get()
            ->map(function ($enrollment) {
                return [
                    'id' => $enrollment->id,
                    'module' => [
                        'id' => $enrollment->module->id,
                        'name' => $enrollment->module->name,
                        'subject' => $enrollment->module->subject,
                    ],
                    'teacher' => [
                        'name' => $enrollment->module->teacher->user->name,
                    ],
                    'subscription_type' => $enrollment->subscription_type,
                    'chapter_id' => $enrollment->chapter_id,
                    'enrolled_at' => $enrollment->created_at,
                    'expires_at' => $enrollment->expires_at,
                ];
            });

        return response()->json(['enrollments' => $enrollments]);
    }

    /**
     * Get teacher's enrollments (for teacher dashboard)
     */
    public function teacherEnrollments(Request $request)
    {
        $teacher = $request->user()->teacher;

        if (!$teacher) {
            return response()->json(['message' => 'Only teachers can access this endpoint'], 403);
        }

        $enrollments = Enrollment::with(['student.user', 'module', 'chapter'])
            ->whereHas('module', function ($query) use ($teacher) {
                $query->where('teacher_id', $teacher->id);
            })
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($enrollment) {
                return [
                    'id' => $enrollment->id,
                    'student' => [
                        'id' => $enrollment->student->id ?? null,
                        'user' => [
                            'name' => $enrollment->student->user->name ?? 'Unknown',
                            'email' => $enrollment->student->user->email ?? '',
                        ]
                    ],
                    'module' => [
                        'id' => $enrollment->module->id,
                        'name' => $enrollment->module->name,
                    ],
                    'subscription_type' => $enrollment->subscription_type,
                    'chapter_id' => $enrollment->chapter_id,
                    'chapter' => $enrollment->chapter ? [
                        'id' => $enrollment->chapter->id,
                        'name' => $enrollment->chapter->name,
                    ] : null,
                    'enrolled_at' => $enrollment->created_at,
                ];
            });

        return response()->json(['enrollments' => $enrollments]);
    }

    /**
     * Calculate price based on subscription type using actual folder prices
     */
    private function calculatePrice($module, $type, $chapterId = null, $student = null)
    {
        switch ($type) {
            case 'chapter':
                $chapter = Folder::where('id', $chapterId)
                    ->where('module_id', $module->id)
                    ->whereNull('parent_folder_id')
                    ->first();
                return $chapter ? (float) $chapter->price : 0;

            case 'full':
                // Base price = sum of all chapter prices
                $totalPrice = (float) $module->folders()
                    ->whereNull('parent_folder_id')
                    ->sum('price');
                
                if ($student) {
                    // Find chapters the student has already bought
                    $purchasedChapterIds = Enrollment::where('student_id', $student->id)
                        ->where('module_id', $module->id)
                        ->where('subscription_type', 'chapter')
                        ->where('status', 'active')
                        ->pluck('chapter_id');
                        
                    if ($purchasedChapterIds->isNotEmpty()) {
                        $discount = (float) Folder::whereIn('id', $purchasedChapterIds)->sum('price');
                        $totalPrice = max(0, $totalPrice - $discount);
                    }
                }
                
                return $totalPrice;

            default:
                return 0;
        }
    }
}
