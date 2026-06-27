<?php

namespace App\Http\Controllers\Api\Student;

use App\Models\Module;
use App\Models\Folder;
use App\Models\Enrollment;
use App\Models\ResourceProgress;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ModuleController extends Controller
{
    /**
     * Get all modules, optionally filtered by search, with pricing and possession status
     */
    public function index(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        $query = Module::with(['teacher.user', 'folders' => function($q) {
            $q->whereNull('parent_folder_id'); // Load only chapters for pricing
        }])
        ->where('status', 'active')
        ->whereHas('teacher.user', function ($q) {
            $q->where('status', 'active');
        });

        // Search filter
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                  ->orWhere('subject', 'like', '%' . $searchTerm . '%')
                  ->orWhereHas('teacher.user', function($tq) use ($searchTerm) {
                      $tq->where('name', 'like', '%' . $searchTerm . '%');
                  });
            });
        }

        $modules = $query->get()->map(function ($module) use ($student) {
            // Get enrollments for this module and student
            $enrollments = Enrollment::where('student_id', $student->id)
                ->where('module_id', $module->id)
                ->where('status', 'active')
                ->get();

            $hasFullAccess = $enrollments->contains('subscription_type', 'full');
            $purchasedChaptersCount = $enrollments->where('subscription_type', 'chapter')->count();
            
            $possessionStatus = 'none';
            if ($hasFullAccess) {
                $possessionStatus = 'full';
            } elseif ($purchasedChaptersCount > 0) {
                $possessionStatus = 'partial';
            }

            // Calculate total price using folders.price
            $totalPrice = $module->folders->sum('price');

            // Apply discount if partially purchased
            $remainingPrice = $totalPrice;
            if ($possessionStatus === 'partial') {
                $purchasedChapterIds = $enrollments->where('subscription_type', 'chapter')->pluck('chapter_id');
                $discount = $module->folders->whereIn('id', $purchasedChapterIds)->sum('price');
                $remainingPrice = max(0, $totalPrice - $discount);
            }

            return [
                'id' => $module->id,
                'name' => $module->name,
                'subject' => $module->subject,
                'year' => $module->year,
                'level' => $module->level,
                'status' => $module->status,
                'description' => $module->description,
                'total_price' => $totalPrice,
                'remaining_price' => $remainingPrice,
                'teacher' => [
                    'id' => $module->teacher->id,
                    'name' => $module->teacher->user->name,
                    'pseudo' => $module->teacher->user->pseudo,
                    'avatar_url' => $module->teacher->user->avatar_url,
                ],
                'chapters_count' => $module->folders->count(),
                'possession_status' => $possessionStatus,
                'purchased_chapters_count' => $purchasedChaptersCount,
            ];
        });

        // Price filtering
        if ($request->has('min_price')) {
            $modules = $modules->where('total_price', '>=', (float)$request->min_price);
        }
        if ($request->has('max_price')) {
            $modules = $modules->where('total_price', '<=', (float)$request->max_price);
        }

        return response()->json([
            'modules' => $modules->values()
        ]);
    }

    /**
     * Get module details with folder tree, pricing, and access info
     */
    public function show(Request $request, $id)
    {
        $module = Module::with(['teacher.user'])->findOrFail($id);

        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        // Get ALL active enrollments for this student + module (could have multiple chapter enrollments)
        $enrollments = Enrollment::where('student_id', $student->id)
            ->where('module_id', $module->id)
            ->where('status', 'active')
            ->get();

        if ($module->status !== 'active' && $enrollments->isEmpty()) {
            return response()->json(['message' => 'Module not found or unavailable'], 404);
        }

        $progressByResource = ResourceProgress::where('student_id', $student->id)
            ->get()
            ->keyBy('resource_id');

        // Build folder tree with access information
        $folderTree = $this->buildFolderTreeWithAccess($module, $enrollments, $progressByResource);

        // Calculate total stats across all chapters
        $totalStats = $this->calculateModuleStats($folderTree);

        // Determine overall access level
        $hasFullAccess = $enrollments->contains('subscription_type', 'full');
        $enrolledChapterIds = $enrollments->where('subscription_type', 'chapter')->pluck('chapter_id')->toArray();

        return response()->json([
            'module' => [
                'id' => $module->id,
                'name' => $module->name,
                'subject' => $module->subject,
                'year' => $module->year,
                'level' => $module->level,
                'status' => $module->status,
                'description' => $module->description,
                'total_price' => $module->total_price,
                'teacher' => [
                    'id' => $module->teacher->id,
                    'name' => $module->teacher->user->name,
                    'pseudo' => $module->teacher->user->pseudo,
                ],
            ],
            'folder_tree' => $folderTree,
            'stats' => $totalStats,
            'enrollments' => $enrollments->map(fn($e) => [
                'id' => $e->id,
                'subscription_type' => $e->subscription_type,
                'chapter_id' => $e->chapter_id,
                'resource_types' => $e->resource_types,
                'expires_at' => $e->expires_at,
            ]),
            'has_full_access' => $hasFullAccess,
            'enrolled_chapter_ids' => $enrolledChapterIds,
            'has_any_access' => $enrollments->isNotEmpty(),
        ]);
    }

    /**
     * Get pricing options for a module — derived from actual chapter prices
     */
    public function pricing(Request $request, $id)
    {
        if (!$request->user()->student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        $module = Module::findOrFail($id);

        if ($module->status !== 'active') {
            return response()->json(['message' => 'Module not found or unavailable'], 404);
        }

        // Get chapters (top-level folders) with their prices
        $chapters = $module->folders()
            ->whereNull('parent_folder_id')
            ->orderBy('order')
            ->get(['id', 'name', 'price', 'order']);

        $totalPrice = $chapters->sum('price');

        return response()->json([
            'total_price' => $totalPrice,
            'chapters' => $chapters->map(fn($ch) => [
                'id' => $ch->id,
                'name' => $ch->name,
                'price' => $ch->price,
                'order' => $ch->order,
            ]),
            'options' => [
                'per_chapter' => [
                    'name' => 'Par chapitre',
                    'description' => 'Accès à un seul chapitre',
                    'chapters' => $chapters->map(fn($ch) => [
                        'id' => $ch->id,
                        'name' => $ch->name,
                        'price' => $ch->price,
                    ]),
                ],
                'full_module' => [
                    'name' => 'Module complet',
                    'price' => $totalPrice,
                    'description' => 'Accès illimité à tout le contenu',
                    'recommended' => true,
                ],
            ],
        ]);
    }

    /**
     * Get student's enrolled modules
     */
    public function myModules(Request $request)
    {
        $student = $request->user()->student;
        if (!$student) {
            return response()->json(['message' => 'Only students can access this endpoint'], 403);
        }

        $enrollments = Enrollment::with(['module.teacher.user', 'module.folders'])
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->get();

        // Group enrollments by module
        $moduleMap = [];
        foreach ($enrollments as $enrollment) {
            $moduleId = $enrollment->module_id;
            if (!isset($moduleMap[$moduleId])) {
                $moduleMap[$moduleId] = [
                    'module' => [
                        'id' => $enrollment->module->id,
                        'name' => $enrollment->module->name,
                        'subject' => $enrollment->module->subject,
                        'year' => $enrollment->module->year,
                        'level' => $enrollment->module->level,
                        'status' => $enrollment->module->status,
                        'total_price' => $enrollment->module->total_price,
                    ],
                    'teacher' => [
                        'name' => $enrollment->module->teacher->user->name,
                        'pseudo' => $enrollment->module->teacher->user->pseudo,
                    ],
                    'enrollments' => [],
                ];
            }
            $moduleMap[$moduleId]['enrollments'][] = [
                'id' => $enrollment->id,
                'subscription_type' => $enrollment->subscription_type,
                'chapter_id' => $enrollment->chapter_id,
                'expires_at' => $enrollment->expires_at,
                'enrolled_at' => $enrollment->created_at,
            ];
        }

        return response()->json(['modules' => array_values($moduleMap)]);
    }

    /**
     * Build folder tree with access control information
     */
    private function buildFolderTreeWithAccess($module, $enrollments, $progressByResource)
    {
        $chapters = $module->folders()
            ->with(['children.resources', 'resources'])
            ->whereNull('parent_folder_id')
            ->orderBy('order')
            ->get();

        return $chapters->map(function ($chapter) use ($enrollments, $progressByResource) {
            return $this->formatChapterWithAccess($chapter, $enrollments, $progressByResource);
        });
    }

    /**
     * Format a chapter (top-level folder) with its sub-folders and access info
     */
    private function formatChapterWithAccess($chapter, $enrollments, $progressByResource)
    {
        $hasFullAccess = $enrollments->contains('subscription_type', 'full');
        $hasChapterAccess = $enrollments->where('subscription_type', 'chapter')
            ->where('chapter_id', $chapter->id)
            ->isNotEmpty();
        $chapterUnlocked = $hasFullAccess || $hasChapterAccess;

        // Sub-folders (Cours, TD, TP)
        $children = $chapter->children()
            ->with('resources')
            ->orderBy('order')
            ->get()
            ->map(function ($subFolder) use ($chapterUnlocked, $enrollments, $progressByResource) {
                // Check type-based access
                $hasTypeAccess = $enrollments->where('subscription_type', 'type')
                    ->filter(function ($e) use ($subFolder) {
                        $types = is_array($e->resource_types)
                            ? $e->resource_types
                            : json_decode($e->resource_types ?? '[]', true);
                        return in_array($subFolder->type, $types);
                    })->isNotEmpty();

                $subFolderUnlocked = $chapterUnlocked || $hasTypeAccess;

                $resources = $subFolder->resources->map(function ($resource) use ($subFolderUnlocked, $progressByResource) {
                    $progress = $progressByResource->get($resource->id);

                    return [
                        'id' => $resource->id,
                        'name' => $resource->name,
                        'type' => $resource->type,
                        'format' => $resource->format,
                        'mime_type' => $resource->mime_type,
                        'file_size' => $resource->file_size,
                        'size' => $resource->size,
                        'duration' => $resource->duration,
                        'is_public' => $resource->is_public,
                        'has_access' => $subFolderUnlocked || $resource->is_public,
                        'locked' => !($subFolderUnlocked || $resource->is_public),
                        'viewed' => (bool) $progress?->viewed_at,
                        'completed' => (bool) $progress?->completed_at,
                        'viewed_at' => $progress?->viewed_at,
                        'completed_at' => $progress?->completed_at,
                        'last_position_seconds' => $progress?->last_position_seconds ?? 0,
                    ];
                });

                return [
                    'id' => $subFolder->id,
                    'name' => $subFolder->name,
                    'type' => $subFolder->type,
                    'order' => $subFolder->order,
                    'resources' => $resources,
                    'resources_count' => $resources->count(),
                    'has_access' => $subFolderUnlocked,
                ];
            });

        // Count total resources across all sub-folders
        $totalResources = $children->sum('resources_count');

        return [
            'id' => $chapter->id,
            'name' => $chapter->name,
            'type' => $chapter->type,
            'chapter_number' => $chapter->chapter_number,
            'order' => $chapter->order,
            'price' => $chapter->price,
            'has_access' => $chapterUnlocked,
            'children' => $children,
            'resources_count' => $totalResources,
        ];
    }

    /**
     * Calculate aggregate stats for the module
     */
    private function calculateModuleStats($folderTree)
    {
        $totalResources = 0;
        $totalVideos = 0;
        $totalDocs = 0;
        $totalChapters = count($folderTree);

        foreach ($folderTree as $chapter) {
            foreach ($chapter['children'] as $subFolder) {
                foreach ($subFolder['resources'] as $resource) {
                    $totalResources++;
                    if (isset($resource['mime_type']) && str_starts_with($resource['mime_type'] ?? '', 'video/')) {
                        $totalVideos++;
                    } else {
                        $totalDocs++;
                    }
                }
            }
        }

        return [
            'total_resources' => $totalResources,
            'total_videos' => $totalVideos,
            'total_docs' => $totalDocs,
            'total_chapters' => $totalChapters,
        ];
    }
}
