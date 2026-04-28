<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
    public function view(Request $request, $id)
    {
        $resource = Resource::findOrFail($id);

        // Authenticate user via token in query param
        $user = null;
        if ($token = $request->query('token')) {
            $accessToken = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            if ($accessToken && $accessToken->tokenable) {
                $user = $accessToken->tokenable;
            }
        }

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user->teacher && $resource->folder->module->teacher_id === $user->teacher->id) {
            // Teacher owns this resource
            $canAccess = true;
        } elseif ($user->student) {
            // Check if student is enrolled
            $canAccess = $resource->folder->module->enrollments()
                ->where('student_id', $user->student->id)
                ->exists(); // Simplified check, ideally check subscription type too
        } else {
            $canAccess = false;
        }

        if (!$canAccess) {
            return response()->json([
                'message' => 'Unauthorized access to this resource'
            ], 403);
        }

        $path = Storage::disk('public')->path($resource->file_path);

        if (!file_exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->file($path);
    }
}
