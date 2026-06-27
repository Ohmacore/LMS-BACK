<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModuleController extends Controller
{
    /**
     * Year limits per education level
     */
    private const YEAR_LIMITS = [
        'primaire' => 5,
        'college' => 4,
        'lycee' => 3,
        'universite' => 8,
    ];

    /**
     * Teacher-controlled module lifecycle states.
     */
    private const STATUSES = [
        'draft',
        'active',
        'paused',
        'archived',
    ];

    /**
     * Get teacher's modules
     */
    public function index()
    {
        $teacher = auth()->user()->teacher;

        if (!$teacher) {
            return response()->json([
                'message' => 'Only teachers can access this endpoint'
            ], 403);
        }

        $modules = Module::where('teacher_id', $teacher->id)
            ->withCount(['enrollments as enrollments_count' => function ($query) {
                $query->where('status', 'active')
                    ->select(DB::raw('count(distinct student_id)'));
            }])
            ->withCount(['folders as chapters_count' => function ($query) {
                $query->whereNull('parent_folder_id');
            }])
            ->with(['teacher.user'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'modules' => $modules
        ]);
    }

    /**
     * Get single module with full details
     */
    public function show($id)
    {
        $query = Module::with([
            'teacher.user',
            // Load top-level chapters (no parent)
            'folders' => function ($query) {
                $query->whereNull('parent_folder_id')
                    ->orderBy('order');
            },
            // Load children (Cours/TD/TP) of each chapter
            'folders.children' => function ($query) {
                $query->orderBy('order');
            },
            // Load resources of each child folder
            'folders.children.resources' => function ($query) {
                $query->orderBy('order');
            },
        ]);

        $module = $query->findOrFail($id);

        // If authenticated teacher owns the module, load private management data securely.
        $isOwnerTeacher = auth()->check() && auth()->user()->teacher && auth()->user()->teacher->id === $module->teacher_id;
        if ($isOwnerTeacher) {
            $module->load(['enrollments' => function ($query) {
                $query->where('status', 'active')
                    ->with(['student.user', 'chapter'])
                    ->latest();
            }]);

            $revenueQuery = Transaction::where('module_id', $module->id)
                ->where('type', 'purchase');

            $module->setAttribute('revenue_generated', abs((float) (clone $revenueQuery)
                ->where('status', 'completed')
                ->sum('amount')));
            $module->setAttribute('revenue_pending', abs((float) (clone $revenueQuery)
                ->where('status', 'pending')
                ->sum('amount')));
        }

        // Check if current user is enrolled (if authenticated)
        $enrolled = false;
        if (auth()->check() && auth()->user()->student) {
            $enrolled = $module->enrollments()
                ->where('student_id', auth()->user()->student->id)
                ->exists();
        }

        // Count unique active students
        $enrollmentsCount = $module->enrollments()
            ->where('status', 'active')
            ->distinct('student_id')
            ->count('student_id');
        $module->setAttribute('enrollments_count', $enrollmentsCount);

        return response()->json([
            'module' => $module,
            'enrolled' => $enrolled,
            'enrollments_count' => $enrollmentsCount
        ]);
    }

    /**
     * Get a teacher-owned module with full management details.
     */
    public function showForTeacher($id)
    {
        $teacher = auth()->user()->teacher;

        if (!$teacher) {
            return response()->json([
                'message' => 'Only teachers can access this endpoint'
            ], 403);
        }

        $module = Module::where('teacher_id', $teacher->id)->findOrFail($id);

        return $this->show($module->id);
    }

    /**
     * Store a new module (teacher only)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'level' => 'required|in:primaire,college,lycee,universite',
            'year' => 'required|integer|min:1',
            'status' => 'nullable|in:' . implode(',', self::STATUSES),
        ]);

        // Validate year against level
        $maxYear = self::YEAR_LIMITS[$validated['level']] ?? 8;
        if ($validated['year'] > $maxYear) {
            return response()->json([
                'message' => "L'année ne peut pas dépasser {$maxYear} pour le niveau {$validated['level']}"
            ], 422);
        }

        // Get authenticated teacher
        $teacher = auth()->user()->teacher;

        if (!$teacher) {
            return response()->json([
                'message' => 'Only teachers can create modules'
            ], 403);
        }

        // Check if teacher is approved
        if ($teacher->status !== 'approved') {
            return response()->json([
                'message' => 'Your teacher account must be approved first'
            ], 403);
        }

        // Create module (no subject, year stored as integer string)
        $module = Module::create([
            'teacher_id' => $teacher->id,
            'name' => $validated['name'],
            'description' => $validated['description'],
            'level' => $validated['level'],
            'year' => (string) $validated['year'],
            'pricing_settings' => [],
            'status' => $validated['status'] ?? 'draft',
        ]);

        // No auto-created folders — teacher will add chapters manually

        // Load relationships
        $module->load('teacher.user', 'folders');

        return response()->json([
            'message' => 'Module created successfully',
            'module' => $module
        ], 201);
    }

    /**
     * Update module (teacher only - own modules)
     */
    public function update(Request $request, $id)
    {
        $module = Module::findOrFail($id);

        // Check ownership
        if ($module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'level' => 'sometimes|in:primaire,college,lycee,universite',
            'year' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:' . implode(',', self::STATUSES),
        ]);

        // Validate year against level if both provided
        if (isset($validated['year'])) {
            $level = $validated['level'] ?? $module->level;
            $maxYear = self::YEAR_LIMITS[$level] ?? 8;
            if ($validated['year'] > $maxYear) {
                return response()->json([
                    'message' => "L'année ne peut pas dépasser {$maxYear} pour le niveau {$level}"
                ], 422);
            }
        }

        // Update module
        $updateData = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'level' => $validated['level'] ?? null,
            'year' => isset($validated['year']) ? (string) $validated['year'] : null,
            'status' => $validated['status'] ?? null,
        ], fn($value) => $value !== null);

        $module->update($updateData);
        $module->load('teacher.user', 'folders');

        return response()->json([
            'message' => 'Module updated successfully',
            'module' => $module
        ]);
    }

    /**
     * Delete module (teacher only - own modules)
     */
    public function destroy($id)
    {
        $module = Module::findOrFail($id);

        // Check ownership
        if ($module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $module->delete();

        return response()->json([
            'message' => 'Module deleted successfully'
        ]);
    }
}
