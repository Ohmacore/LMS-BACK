<?php

namespace Tests\Feature;

use App\Models\Enrollment;
use App\Models\Folder;
use App\Models\Module;
use App\Models\Resource;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CoreAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_login_requires_approved_active_account(): void
    {
        [$pendingUser] = $this->createTeacher('pending', 'pending');

        $this->postJson('/api/login', [
            'email' => $pendingUser->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account is pending approval');

        [$rejectedUser] = $this->createTeacher('rejected', 'active', [
            'notes' => 'Missing identity document',
        ]);

        $this->postJson('/api/login', [
            'email' => $rejectedUser->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your teacher account was rejected: Missing identity document');

        [$blockedUser] = $this->createTeacher('approved', 'blocked');

        $this->postJson('/api/login', [
            'email' => $blockedUser->email,
            'password' => 'password',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Your account has been blocked');
    }

    public function test_teacher_cannot_manage_another_teachers_module(): void
    {
        [$ownerUser, $ownerTeacher] = $this->createTeacher();
        [$otherUser] = $this->createTeacher();
        $module = $this->createModule($ownerTeacher);

        Sanctum::actingAs($otherUser);

        $this->getJson("/api/teacher/modules/{$module->id}")
            ->assertNotFound();

        $this->putJson("/api/teacher/modules/{$module->id}", [
            'name' => 'Stolen module name',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized');

        Sanctum::actingAs($ownerUser);

        $this->putJson("/api/teacher/modules/{$module->id}", [
            'name' => 'Updated module name',
        ])->assertOk()
            ->assertJsonPath('module.name', 'Updated module name');
    }

    public function test_students_only_discover_and_purchase_active_modules(): void
    {
        [, $teacher] = $this->createTeacher();
        [$studentUser] = $this->createStudent(walletBalance: 1000);
        $activeModule = $this->createModule($teacher, [
            'name' => 'Active Module',
            'status' => 'active',
        ]);
        $draftModule = $this->createModule($teacher, [
            'name' => 'Draft Module',
            'status' => 'draft',
        ]);
        $this->createChapter($activeModule);
        $this->createChapter($draftModule);

        Sanctum::actingAs($studentUser);

        $response = $this->getJson('/api/student/modules')
            ->assertOk();

        $moduleIds = collect($response->json('modules'))->pluck('id')->all();
        $this->assertContains($activeModule->id, $moduleIds);
        $this->assertNotContains($draftModule->id, $moduleIds);

        $this->getJson("/api/student/modules/{$draftModule->id}/pricing")
            ->assertNotFound();

        $this->postJson('/api/student/enroll', [
            'module_id' => $draftModule->id,
            'subscription_type' => 'full',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Ce module n’est pas disponible pour inscription');
    }

    public function test_non_students_cannot_access_student_module_endpoints(): void
    {
        [$teacherUser, $teacher] = $this->createTeacher();
        $module = $this->createModule($teacher);

        Sanctum::actingAs($teacherUser);

        $this->getJson('/api/student/modules')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');

        $this->getJson("/api/student/modules/{$module->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');

        $this->getJson("/api/student/modules/{$module->id}/pricing")
            ->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');

        $this->getJson('/api/student/my-modules')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');

        $this->postJson('/api/student/enroll', [
            'module_id' => $module->id,
            'subscription_type' => 'full',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');

        $this->getJson('/api/student/wallet')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');

        $this->getJson('/api/student/wallet/transactions')
            ->assertForbidden()
            ->assertJsonPath('message', 'Only students can access this endpoint');
    }

    public function test_teacher_registration_does_not_require_year(): void
    {
        $this->postJson('/api/register/teacher', [
            'first_name' => 'Nadia',
            'last_name' => 'Belaid',
            'email' => 'nadia.belaid@example.com',
            'pseudo' => 'prof_nadia',
            'domain_of_interest' => 'Physique',
            'password' => 'password',
        ])->assertCreated()
            ->assertJsonPath('teacher.status', 'pending')
            ->assertJsonPath('teacher.year', null);

        $this->assertDatabaseHas('users', [
            'email' => 'nadia.belaid@example.com',
            'role' => 'teacher',
            'status' => 'pending',
        ]);
    }

    public function test_student_can_buy_a_chapter_and_cannot_buy_it_twice(): void
    {
        [, $teacher] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent(walletBalance: 500);
        $module = $this->createModule($teacher);
        $chapter = $this->createChapter($module, price: 250);

        Sanctum::actingAs($studentUser);

        $this->postJson('/api/student/enroll', [
            'module_id' => $module->id,
            'subscription_type' => 'chapter',
            'chapter_id' => $chapter->id,
        ])->assertCreated()
            ->assertJsonPath('amount_paid', 250)
            ->assertJsonPath('new_balance', '250.00');

        $this->assertDatabaseHas('enrollments', [
            'student_id' => $student->id,
            'module_id' => $module->id,
            'subscription_type' => 'chapter',
            'chapter_id' => $chapter->id,
            'status' => 'active',
        ]);

        $this->assertSame('250.00', $student->fresh()->wallet_balance);

        $this->postJson('/api/student/enroll', [
            'module_id' => $module->id,
            'subscription_type' => 'chapter',
            'chapter_id' => $chapter->id,
        ])->assertStatus(400)
            ->assertJsonPath('message', 'Vous êtes déjà inscrit à ce chapitre');
    }

    public function test_private_resources_require_valid_enrollment_and_stream_inline(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('resources/private.pdf', '%PDF-1.4 test');

        [, $teacher] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent(walletBalance: 1000);
        $module = $this->createModule($teacher);
        $chapter = $this->createChapter($module);
        $courseFolder = $this->createSubFolder($module, $chapter, 'Cours', 'cours');
        $resource = Resource::create([
            'folder_id' => $courseFolder->id,
            'name' => 'Private PDF',
            'type' => 'cours',
            'format' => 'pdf',
            'file_path' => 'resources/private.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 13,
            'is_public' => false,
            'order' => 1,
        ]);
        $token = $studentUser->createToken('resource-test')->plainTextToken;

        Sanctum::actingAs($studentUser);

        $this->getJson("/api/resources/{$resource->id}")
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized access to this resource');

        $this->get("/api/resources/{$resource->id}/view?token={$token}")
            ->assertForbidden();

        Enrollment::create([
            'student_id' => $student->id,
            'module_id' => $module->id,
            'subscription_type' => 'chapter',
            'chapter_id' => $chapter->id,
            'status' => 'active',
        ]);

        $this->getJson("/api/resources/{$resource->id}")
            ->assertOk()
            ->assertJsonPath('resource.id', $resource->id)
            ->assertJsonPath('resource.chapter.id', $chapter->id);

        $this->get("/api/resources/{$resource->id}/view?token={$token}")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="private.pdf"');
    }

    public function test_live_session_schedule_start_notifications_and_student_join(): void
    {
        config([
            'services.jitsi.base_url' => 'https://live.test',
            'services.jitsi.jwt_app_id' => 'ohmacore-test',
            'services.jitsi.jwt_app_secret' => 'test-secret',
            'services.jitsi.jwt_ttl_minutes' => 60,
        ]);

        [$teacherUser, $teacher] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent(walletBalance: 1000);
        [$blockedStudentUser] = $this->createStudent(walletBalance: 1000);
        $module = $this->createModule($teacher);
        $chapter = $this->createChapter($module);

        Enrollment::create([
            'student_id' => $student->id,
            'module_id' => $module->id,
            'subscription_type' => 'chapter',
            'chapter_id' => $chapter->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($teacherUser);

        $scheduleResponse = $this->postJson("/api/teacher/modules/{$module->id}/live-sessions", [
            'title' => 'Live Chapter 1',
            'description' => 'A focused live class.',
            'chapter_id' => $chapter->id,
            'scheduled_at' => now()->addHour()->toISOString(),
        ])->assertCreated()
            ->assertJsonPath('live_session.status', 'scheduled')
            ->assertJsonPath('live_session.provider', 'jitsi');

        $liveSessionId = $scheduleResponse->json('live_session.id');
        $room = $scheduleResponse->json('live_session.provider_room');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $studentUser->id,
            'type' => 'live_scheduled',
        ]);

        Sanctum::actingAs($studentUser);

        $this->getJson("/api/student/modules/{$module->id}/live-sessions")
            ->assertOk()
            ->assertJsonPath('live_sessions.0.id', $liveSessionId)
            ->assertJsonPath('live_sessions.0.can_join', false)
            ->assertJsonPath('live_sessions.0.join_url', null);

        $this->postJson("/api/student/live-sessions/{$liveSessionId}/join")
            ->assertStatus(409);

        Sanctum::actingAs($teacherUser);

        $this->postJson("/api/teacher/live-sessions/{$liveSessionId}/start")
            ->assertOk()
            ->assertJsonPath('live_session.status', 'live')
            ->assertJson(fn($json) => $json->whereType('live_session.start_url', 'string')->etc());

        $teacherLaunch = $this->postJson("/api/teacher/live-sessions/{$liveSessionId}/launch")
            ->assertOk()
            ->assertJson(fn($json) => $json->whereType('launch_url', 'string')->etc());

        $teacherPayload = $this->jwtPayloadFromUrl($teacherLaunch->json('launch_url'));
        $this->assertSame($room, $teacherPayload['room']);
        $this->assertTrue($teacherPayload['context']['user']['moderator']);
        $this->assertSame('owner', $teacherPayload['context']['user']['affiliation']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $studentUser->id,
            'type' => 'live_started',
        ]);

        Sanctum::actingAs($studentUser);

        $this->postJson("/api/student/live-sessions/{$liveSessionId}/join")
            ->assertOk()
            ->assertJsonPath('live_session.can_join', true)
            ->assertJsonPath('live_session.status', 'live')
            ->assertJson(fn($json) => $json->whereType('join_url', 'string')->etc());

        $studentJoin = $this->postJson("/api/student/live-sessions/{$liveSessionId}/join")
            ->assertOk();

        $studentPayload = $this->jwtPayloadFromUrl($studentJoin->json('join_url'));
        $this->assertSame($room, $studentPayload['room']);
        $this->assertFalse($studentPayload['context']['user']['moderator']);
        $this->assertSame('member', $studentPayload['context']['user']['affiliation']);

        Sanctum::actingAs($blockedStudentUser);

        $this->postJson("/api/student/live-sessions/{$liveSessionId}/join")
            ->assertForbidden();
    }

    public function test_student_resource_progress_summary_and_continue_learning(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('resources/progress.pdf', '%PDF-1.4 progress');

        [, $teacher] = $this->createTeacher();
        [$studentUser, $student] = $this->createStudent(walletBalance: 1000);
        $module = $this->createModule($teacher);
        $chapter = $this->createChapter($module);
        $courseFolder = $this->createSubFolder($module, $chapter, 'Cours', 'cours');
        $resource = Resource::create([
            'folder_id' => $courseFolder->id,
            'name' => 'Progress PDF',
            'type' => 'cours',
            'format' => 'pdf',
            'file_path' => 'resources/progress.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 17,
            'is_public' => false,
            'order' => 1,
        ]);

        Enrollment::create([
            'student_id' => $student->id,
            'module_id' => $module->id,
            'subscription_type' => 'chapter',
            'chapter_id' => $chapter->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($studentUser);

        $this->postJson("/api/student/resources/{$resource->id}/progress", [
            'status' => 'viewed',
            'last_position_seconds' => 12,
        ])->assertOk()
            ->assertJsonPath('progress.resource_id', $resource->id)
            ->assertJsonPath('progress.last_position_seconds', 12);

        $this->postJson("/api/student/resources/{$resource->id}/progress", [
            'status' => 'completed',
        ])->assertOk();

        $this->getJson('/api/student/progress/summary')
            ->assertOk()
            ->assertJsonPath('modules.0.module_id', $module->id)
            ->assertJsonPath('modules.0.progress_percent', 100);

        $this->getJson('/api/student/progress/continue')
            ->assertOk()
            ->assertJsonPath('resource.resource_id', $resource->id)
            ->assertJsonPath('resource.module.id', $module->id);
    }

    private function createUser(string $role, string $status = 'active'): User
    {
        $unique = str_replace('.', '', uniqid($role, true));

        return User::create([
            'name' => ucfirst($role) . ' User',
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'pseudo' => $unique,
            'email' => "{$unique}@example.com",
            'password' => Hash::make('password'),
            'role' => $role,
            'status' => $status,
            'email_verified_at' => now(),
        ]);
    }

    private function jwtPayloadFromUrl(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $parts = explode('.', $query['jwt'] ?? '');
        $payload = strtr($parts[1] ?? '', '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

        return json_decode(base64_decode($payload), true);
    }

    private function createTeacher(string $teacherStatus = 'approved', string $userStatus = 'active', array $overrides = []): array
    {
        $user = $this->createUser('teacher', $userStatus);
        $teacher = Teacher::create(array_merge([
            'user_id' => $user->id,
            'domain_of_interest' => 'Mathematics',
            'year' => '2026',
            'rating' => 0,
            'total_students' => 0,
            'status' => $teacherStatus,
        ], $overrides));

        return [$user, $teacher];
    }

    private function createStudent(float $walletBalance = 0): array
    {
        $user = $this->createUser('student');
        $student = Student::create([
            'user_id' => $user->id,
            'wallet_balance' => $walletBalance,
            'referral_code' => strtoupper(substr(md5($user->id . uniqid('', true)), 0, 8)),
        ]);

        return [$user, $student];
    }

    private function createModule(Teacher $teacher, array $overrides = []): Module
    {
        return Module::create(array_merge([
            'teacher_id' => $teacher->id,
            'name' => 'Core Module',
            'subject' => 'Core Subject',
            'year' => '1',
            'level' => 'college',
            'description' => 'A module used by feature tests.',
            'pricing_settings' => [],
            'status' => 'active',
        ], $overrides));
    }

    private function createChapter(Module $module, float $price = 100): Folder
    {
        return Folder::create([
            'module_id' => $module->id,
            'name' => 'Chapter 1',
            'type' => 'chapter',
            'order' => 1,
            'price' => $price,
        ]);
    }

    private function createSubFolder(Module $module, Folder $chapter, string $name, string $type): Folder
    {
        return Folder::create([
            'module_id' => $module->id,
            'parent_folder_id' => $chapter->id,
            'name' => $name,
            'type' => $type,
            'order' => 1,
        ]);
    }
}
