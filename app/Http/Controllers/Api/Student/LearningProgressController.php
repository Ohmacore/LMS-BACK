<?php

namespace App\Http\Controllers\Api\Student;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Module;
use App\Models\Resource;
use App\Models\ResourceProgress;
use App\Services\AccessService;
use Illuminate\Http\Request;

class LearningProgressController extends Controller
{
    public function __construct(private AccessService $accessService)
    {
    }

    public function updateResourceProgress(Request $request, int $resourceId)
    {
        $validated = $request->validate([
            'status' => 'required|in:viewed,completed',
            'last_position_seconds' => 'nullable|integer|min:0',
        ]);

        $resource = Resource::with('folder.module', 'folder.parent')->findOrFail($resourceId);

        if (!$this->accessService->canAccessResource($resource, $request->user())) {
            return response()->json(['message' => 'Unauthorized access to this resource'], 403);
        }

        $student = $request->user()->student;

        $progress = ResourceProgress::firstOrNew([
            'student_id' => $student->id,
            'resource_id' => $resource->id,
        ]);

        if ($validated['status'] === 'viewed') {
            $progress->viewed_at = $progress->viewed_at ?? now();
        }

        if ($validated['status'] === 'completed') {
            $progress->viewed_at = $progress->viewed_at ?? now();
            $progress->completed_at = $progress->completed_at ?? now();
        }

        if (isset($validated['last_position_seconds'])) {
            $progress->last_position_seconds = $validated['last_position_seconds'];
        }

        $progress->save();

        return response()->json([
            'message' => 'Progress updated',
            'progress' => $this->formatProgress($progress->fresh('resource.folder.module')),
        ]);
    }

    public function summary(Request $request)
    {
        $student = $request->user()->student;

        $moduleIds = Enrollment::where('student_id', $student->id)
            ->where('status', 'active')
            ->pluck('module_id')
            ->unique()
            ->values();

        $modules = Module::with('teacher.user')
            ->whereIn('id', $moduleIds)
            ->get()
            ->map(function (Module $module) use ($student) {
                $resourceIds = $this->accessService->accessibleResourceIdsForStudentModule($student, $module);
                $total = count($resourceIds);

                $completed = ResourceProgress::where('student_id', $student->id)
                    ->whereIn('resource_id', $resourceIds)
                    ->whereNotNull('completed_at')
                    ->count();

                $viewed = ResourceProgress::where('student_id', $student->id)
                    ->whereIn('resource_id', $resourceIds)
                    ->whereNotNull('viewed_at')
                    ->count();

                return [
                    'module_id' => $module->id,
                    'module_name' => $module->name,
                    'teacher' => [
                        'id' => $module->teacher->id,
                        'name' => $module->teacher->user->name,
                        'pseudo' => $module->teacher->user->pseudo,
                    ],
                    'total_resources' => $total,
                    'viewed_resources' => $viewed,
                    'completed_resources' => $completed,
                    'progress_percent' => $total > 0 ? round(($completed / $total) * 100) : 0,
                ];
            });

        return response()->json(['modules' => $modules]);
    }

    public function continueLearning(Request $request)
    {
        $student = $request->user()->student;

        $progress = ResourceProgress::with([
            'resource.folder.module.teacher.user',
            'resource.folder.parent',
        ])
            ->where('student_id', $student->id)
            ->whereNotNull('viewed_at')
            ->orderBy('updated_at', 'desc')
            ->first();

        if (!$progress) {
            return response()->json(['resource' => null]);
        }

        return response()->json([
            'resource' => $this->formatProgress($progress),
        ]);
    }

    private function formatProgress(ResourceProgress $progress): array
    {
        $resource = $progress->resource;
        $chapter = $this->accessService->resolveChapterFolder($resource->folder);

        return [
            'resource_id' => $resource->id,
            'resource_name' => $resource->name,
            'viewed_at' => $progress->viewed_at,
            'completed_at' => $progress->completed_at,
            'last_position_seconds' => $progress->last_position_seconds,
            'module' => [
                'id' => $resource->folder->module->id,
                'name' => $resource->folder->module->name,
            ],
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'name' => $chapter->name,
            ] : null,
            'folder' => [
                'id' => $resource->folder->id,
                'name' => $resource->folder->name,
                'type' => $resource->folder->type,
            ],
        ];
    }
}
