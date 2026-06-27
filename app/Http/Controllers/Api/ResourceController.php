<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Resource;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class ResourceController extends Controller
{
    /**
     * Upload a new resource to a folder
     */
    public function store(Request $request, $folderId)
    {
        $folder = Folder::findOrFail($folderId);

        // Check ownership via module
        if ($folder->module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'type' => 'required|in:cours,TD,TP,exam',
                'format' => 'required|in:pdf,video,other',
                'file' => 'required|file', // Removed size constraints to rely on PHP config
                'order' => 'nullable|integer|min:0',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Resource upload validation failed', [
                'errors' => $e->errors(),
                'input' => $request->except('file'),
                'file_info' => $request->hasFile('file') ? [
                    'name' => $request->file('file')->getClientOriginalName(),
                    'size' => $request->file('file')->getSize(),
                    'mime' => $request->file('file')->getMimeType(),
                ] : 'no file'
            ]);
            throw $e;
        }

        // Handle file upload
        $file = $request->file('file');
        $path = $file->store('resources/' . $folder->module_id . '/folders/' . $folderId, 'public');

        // Auto-assign order if not provided
        if (!isset($validated['order'])) {
            $maxOrder = $folder->resources()->max('order') ?? 0;
            $validated['order'] = $maxOrder + 1;
        }

        $resource = $folder->resources()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'format' => $validated['format'],
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'order' => $validated['order'],
        ]);

        return response()->json([
            'message' => 'Ressource ajoutée avec succès',
            'resource' => $resource
        ], 201);
    }

    /**
     * Update a resource
     */
    public function update(Request $request, $id)
    {
        $resource = Resource::findOrFail($id);

        // Check ownership
        if ($resource->folder->module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:cours,TD,TP,exam',
            'format' => 'sometimes|in:pdf,video,other',
            'order' => 'sometimes|integer|min:0',
        ]);

        $resource->update($validated);

        return response()->json([
            'message' => 'Ressource mise à jour',
            'resource' => $resource
        ]);
    }

    /**
     * Delete a resource
     */
    public function destroy($id)
    {
        $resource = Resource::findOrFail($id);

        // Check ownership
        if ($resource->folder->module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Delete file from storage
        if (Storage::disk('public')->exists($resource->file_path)) {
            Storage::disk('public')->delete($resource->file_path);
        }

        $resource->delete();

        return response()->json([
            'message' => 'Ressource supprimée'
        ]);
    }

    /**
     * Get resource URL for viewing/downloading
     */
    public function show($id)
    {
        $resource = Resource::with('folder.module.teacher.user', 'folder.parent')
            ->findOrFail($id);

        if (!$resource->is_public && !$this->canViewResource($resource, auth()->user())) {
            return response()->json([
                'message' => 'Unauthorized access to this resource'
            ], 403);
        }

        $chapter = $this->resolveChapterFolder($resource->folder);

        return response()->json([
            'resource' => [
                'id' => $resource->id,
                'name' => $resource->name,
                'type' => $resource->type,
                'format' => $resource->format,
                'mime_type' => $resource->mime_type,
                'file_size' => $resource->file_size,
                'size' => $resource->size,
                'duration' => $resource->duration,
                'is_public' => $resource->is_public,
                'folder' => [
                    'id' => $resource->folder->id,
                    'name' => $resource->folder->name,
                    'type' => $resource->folder->type,
                ],
                'chapter' => $chapter ? [
                    'id' => $chapter->id,
                    'name' => $chapter->name,
                ] : null,
                'module' => [
                    'id' => $resource->folder->module->id,
                    'name' => $resource->folder->module->name,
                ],
                'teacher' => [
                    'id' => $resource->folder->module->teacher->id,
                    'name' => $resource->folder->module->teacher->user->name,
                    'pseudo' => $resource->folder->module->teacher->user->pseudo,
                ],
            ],
        ]);
    }

    /**
     * Stream a resource file for inline viewing.
     */
    public function view(Request $request, $id)
    {
        $resource = Resource::with('folder.module', 'folder.parent')
            ->findOrFail($id);

        if ($resource->is_public) {
            return $this->serveResourceFile($resource);
        }

        // Authenticate user via token in query param
        $user = null;
        if ($token = $request->query('token')) {
            $accessToken = PersonalAccessToken::findToken($token);
            if ($accessToken && $accessToken->tokenable) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $canAccess = $this->canViewResource($resource, $user);

        if (!$canAccess) {
            return response()->json([
                'message' => 'Unauthorized access to this resource'
            ], 403);
        }

        return $this->serveResourceFile($resource);
    }

    private function canViewResource(Resource $resource, $user): bool
    {
        if ($user->teacher && $resource->folder->module->teacher_id === $user->teacher->id) {
            return true;
        }

        if (!$user->student) {
            return false;
        }

        $studentId = $user->student->id;
        $moduleId = $resource->folder->module_id;

        $hasFullAccess = Enrollment::where('student_id', $studentId)
            ->where('module_id', $moduleId)
            ->where('subscription_type', 'full')
            ->where('status', 'active')
            ->exists();

        if ($hasFullAccess) {
            return true;
        }

        $chapter = $this->resolveChapterFolder($resource->folder);
        if ($chapter) {
            $hasChapterAccess = Enrollment::where('student_id', $studentId)
                ->where('module_id', $moduleId)
                ->where('subscription_type', 'chapter')
                ->where('chapter_id', $chapter->id)
                ->where('status', 'active')
                ->exists();

            if ($hasChapterAccess) {
                return true;
            }
        }

        $folderType = strtolower($resource->folder->type);
        $typeEnrollments = Enrollment::where('student_id', $studentId)
            ->where('module_id', $moduleId)
            ->where('subscription_type', 'type')
            ->where('status', 'active')
            ->get();

        return $typeEnrollments->contains(function (Enrollment $enrollment) use ($folderType) {
            $resourceTypes = is_array($enrollment->resource_types)
                ? $enrollment->resource_types
                : json_decode($enrollment->resource_types ?? '[]', true);

            return in_array($folderType, array_map('strtolower', $resourceTypes ?? []), true);
        });
    }

    private function resolveChapterFolder(Folder $folder): ?Folder
    {
        $current = $folder;

        while ($current) {
            if ($current->type === 'chapter') {
                return $current;
            }

            $current = $current->parent;
        }

        return null;
    }

    private function serveResourceFile(Resource $resource)
    {
        $path = Storage::disk('public')->path($resource->file_path);

        if (!file_exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        $fileName = basename($resource->file_path);

        return response()->file($path, [
            'Content-Type' => $resource->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="' . addslashes($fileName) . '"',
        ]);
    }
}
