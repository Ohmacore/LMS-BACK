<?php

namespace App\Http\Controllers\Api\Student;

use App\Models\Teacher;
use App\Models\Module;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class TeacherController extends Controller
{
    /**
     * Get list of all active teachers with their modules
     */
    public function index()
    {
        $teachers = Teacher::with(['user', 'modules'])
            ->whereHas('user', function ($query) {
                $query->where('status', 'active');
            })
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'user_id' => $teacher->user_id,
                    'name' => $teacher->user->name,
                    'pseudo' => $teacher->user->pseudo,
                    'domain' => $teacher->domain_of_interest,
                    'year' => $teacher->year,
                    'bio' => $teacher->bio,
                    'rating' => $teacher->rating,
                    'total_students' => $teacher->total_students,
                    'avatar_url' => $teacher->user->avatar_url,
                    'modules_count' => $teacher->modules->count(),
                ];
            });

        return response()->json(['teachers' => $teachers]);
    }

    /**
     * Get teacher profile with complete module tree
     */
    public function show($id)
    {
        $teacher = Teacher::with(['user'])->findOrFail($id);

        // Check if teacher is active
        if ($teacher->user->status !== 'active') {
            return response()->json(['message' => 'Teacher not found or inactive'], 404);
        }

        // Load modules with chapters and sub-folders
        $modules = Module::where('teacher_id', $teacher->id)
            ->with([
                'folders' => function ($query) {
                    $query->whereNull('parent_folder_id')->orderBy('order');
                },
                'folders.children' => function ($query) {
                    $query->orderBy('order');
                },
                'folders.children.resources',
            ])
            ->get()
            ->map(function ($module) {
                $folderTree = $this->buildFolderTree($module);
                $totalResources = 0;
                foreach ($folderTree as $chapter) {
                    $totalResources += $chapter['resources_count'];
                }

                return [
                    'id' => $module->id,
                    'name' => $module->name,
                    'subject' => $module->subject,
                    'year' => $module->year,
                    'level' => $module->level,
                    'description' => $module->description,
                    'total_price' => $module->total_price,
                    'chapters_count' => count($folderTree),
                    'resources_count' => $totalResources,
                    'folder_tree' => $folderTree,
                ];
            });

        return response()->json([
            'teacher' => [
                'id' => $teacher->id,
                'name' => $teacher->user->name,
                'pseudo' => $teacher->user->pseudo,
                'domain' => $teacher->domain_of_interest,
                'year' => $teacher->year,
                'bio' => $teacher->bio,
                'rating' => $teacher->rating,
                'total_students' => $teacher->total_students,
                'avatar_url' => $teacher->user->avatar_url,
            ],
            'modules' => $modules,
        ]);
    }

    /**
     * Build hierarchical folder tree for a module
     */
    private function buildFolderTree($module)
    {
        $chapters = $module->folders
            ->where('parent_folder_id', null)
            ->sortBy('order');

        return $chapters->map(function ($chapter) {
            return $this->formatChapter($chapter);
        })->values()->toArray();
    }

    /**
     * Format a chapter with its sub-folders and resource counts
     */
    private function formatChapter($chapter)
    {
        $children = $chapter->children
            ->sortBy('order')
            ->map(function ($subFolder) {
                return [
                    'id' => $subFolder->id,
                    'name' => $subFolder->name,
                    'type' => $subFolder->type,
                    'order' => $subFolder->order,
                    'resources_count' => $subFolder->resources->count(),
                    'resources' => $subFolder->resources->map(function ($resource) {
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
                        ];
                    })->values(),
                ];
            })->values();

        $totalResources = $children->sum('resources_count');

        return [
            'id' => $chapter->id,
            'name' => $chapter->name,
            'type' => $chapter->type,
            'chapter_number' => $chapter->chapter_number,
            'order' => $chapter->order,
            'price' => $chapter->price,
            'resources_count' => $totalResources,
            'children' => $children,
        ];
    }
}
