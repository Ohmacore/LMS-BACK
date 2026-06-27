<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Folder;
use App\Models\LiveSession;
use App\Models\Module;
use App\Models\Resource;
use App\Models\Student;
use App\Models\User;

class AccessService
{
    public function resolveChapterFolder(Folder $folder): ?Folder
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

    public function canAccessResource(Resource $resource, User $user): bool
    {
        $resource->loadMissing('folder.module', 'folder.parent');

        if ($user->teacher && $resource->folder->module->teacher_id === $user->teacher->id) {
            return true;
        }

        if ($resource->is_public) {
            return true;
        }

        if (!$user->student) {
            return false;
        }

        $chapter = $this->resolveChapterFolder($resource->folder);

        return $this->studentCanAccessModuleArea(
            $user->student,
            $resource->folder->module,
            $chapter,
            $resource->folder->type
        );
    }

    public function canJoinLiveSession(LiveSession $liveSession, User $user): bool
    {
        $liveSession->loadMissing('module', 'chapter');

        if ($user->teacher && $liveSession->module->teacher_id === $user->teacher->id) {
            return true;
        }

        if (!$user->student) {
            return false;
        }

        return $this->studentCanAccessModuleArea(
            $user->student,
            $liveSession->module,
            $liveSession->chapter,
            null
        );
    }

    public function studentCanAccessModuleArea(Student $student, Module $module, ?Folder $chapter = null, ?string $resourceType = null): bool
    {
        $enrollments = Enrollment::where('student_id', $student->id)
            ->where('module_id', $module->id)
            ->where('status', 'active')
            ->get();

        if ($enrollments->contains('subscription_type', 'full')) {
            return true;
        }

        if ($chapter) {
            $hasChapterAccess = $enrollments
                ->where('subscription_type', 'chapter')
                ->where('chapter_id', $chapter->id)
                ->isNotEmpty();

            if ($hasChapterAccess) {
                return true;
            }
        } elseif ($enrollments->isNotEmpty()) {
            return true;
        }

        if (!$resourceType) {
            return false;
        }

        $folderType = strtolower($resourceType);

        return $enrollments
            ->where('subscription_type', 'type')
            ->contains(function (Enrollment $enrollment) use ($folderType) {
                $types = is_array($enrollment->resource_types)
                    ? $enrollment->resource_types
                    : json_decode($enrollment->resource_types ?? '[]', true);

                return in_array($folderType, array_map('strtolower', $types ?? []), true);
            });
    }

    public function eligibleStudentsForLiveSession(LiveSession $liveSession)
    {
        $liveSession->loadMissing('module', 'chapter');

        return Student::with('user')
            ->whereHas('user', fn($query) => $query->where('status', 'active'))
            ->whereIn('id', function ($subQuery) use ($liveSession) {
                $subQuery->select('student_id')
                    ->from('enrollments')
                    ->where('module_id', $liveSession->module_id)
                    ->where('status', 'active')
                    ->where(function ($enrollmentQuery) use ($liveSession) {
                        $enrollmentQuery->where('subscription_type', 'full');

                        if ($liveSession->chapter_id) {
                            $enrollmentQuery->orWhere(function ($chapterQuery) use ($liveSession) {
                                $chapterQuery->where('subscription_type', 'chapter')
                                    ->where('chapter_id', $liveSession->chapter_id);
                            });
                        } else {
                            $enrollmentQuery->orWhereIn('subscription_type', ['chapter', 'type']);
                        }
                    });
            })
            ->get();
    }

    public function accessibleResourceIdsForStudentModule(Student $student, Module $module): array
    {
        $resources = Resource::with('folder.parent')
            ->whereHas('folder', fn($query) => $query->where('module_id', $module->id))
            ->get();

        return $resources
            ->filter(fn(Resource $resource) => $resource->is_public || $this->canAccessResource($resource, $student->user))
            ->pluck('id')
            ->values()
            ->all();
    }
}
