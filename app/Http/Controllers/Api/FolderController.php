<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Folder;
use App\Models\Module;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    /**
     * Create a new chapter for a module.
     * Auto-creates 3 sub-folders: Cours, TD, TP.
     */
    public function store(Request $request, $moduleId)
    {
        $module = Module::findOrFail($moduleId);

        // Check ownership
        if ($module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'order' => 'nullable|integer|min:0',
        ]);

        // Auto-assign order if not provided (among top-level chapters only)
        if (!isset($validated['order'])) {
            $maxOrder = $module->folders()
                ->whereNull('parent_folder_id')
                ->max('order') ?? 0;
            $validated['order'] = $maxOrder + 1;
        }

        // Create the chapter (top-level folder)
        $chapter = $module->folders()->create([
            'name' => $validated['name'],
            'order' => $validated['order'],
            'type' => 'chapter',
            'price' => $validated['price'],
        ]);

        // Auto-create 3 sub-folders inside the chapter
        $subFolders = [
            ['name' => 'Cours', 'type' => 'cours', 'order' => 1],
            ['name' => 'TD', 'type' => 'td', 'order' => 2],
            ['name' => 'TP', 'type' => 'tp', 'order' => 3],
        ];

        foreach ($subFolders as $sub) {
            $module->folders()->create([
                'parent_folder_id' => $chapter->id,
                'name' => $sub['name'],
                'type' => $sub['type'],
                'order' => $sub['order'],
            ]);
        }

        // Load children
        $chapter->load('children');

        return response()->json([
            'message' => 'Chapitre créé avec succès',
            'folder' => $chapter
        ], 201);
    }

    /**
     * Update a folder
     */
    public function update(Request $request, $id)
    {
        $folder = Folder::findOrFail($id);

        // Check ownership via module
        if ($folder->module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'order' => 'sometimes|integer|min:0',
            'price' => 'sometimes|numeric|min:0',
        ]);

        $folder->update($validated);

        return response()->json([
            'message' => 'Chapitre mis à jour',
            'folder' => $folder
        ]);
    }

    /**
     * Delete a folder (chapter or sub-folder).
     * Cascades to children and their resources.
     */
    public function destroy($id)
    {
        $folder = Folder::findOrFail($id);

        // Check ownership
        if ($folder->module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        // Delete the folder — cascade will handle children & resources via DB foreign keys
        $folder->delete();

        return response()->json([
            'message' => 'Chapitre supprimé'
        ]);
    }

    /**
     * Reorder folders (chapters)
     */
    public function reorder(Request $request, $moduleId)
    {
        $module = Module::findOrFail($moduleId);

        // Check ownership
        if ($module->teacher_id !== auth()->user()->teacher?->id) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        $validated = $request->validate([
            'folders' => 'required|array',
            'folders.*.id' => 'required|exists:folders,id',
            'folders.*.order' => 'required|integer|min:0',
        ]);

        foreach ($validated['folders'] as $folderData) {
            Folder::where('id', $folderData['id'])
                ->where('module_id', $moduleId)
                ->update(['order' => $folderData['order']]);
        }

        return response()->json([
            'message' => 'Ordre des chapitres mis à jour'
        ]);
    }
}
