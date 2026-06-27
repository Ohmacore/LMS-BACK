<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Student\TeacherController;
use App\Http\Controllers\Api\Student\ModuleController as StudentModuleController;
use App\Http\Controllers\Api\Student\WalletController;
use App\Http\Controllers\Api\Student\EnrollmentController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\LiveSessionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Student\LearningProgressController;

use App\Http\Controllers\Api\TeacherDashboardController;
use App\Http\Controllers\Api\Teacher\WithdrawalController;

// Public routes
Route::post('/register/student', [AuthController::class, 'registerStudent']);
Route::post('/register/teacher', [AuthController::class, 'registerTeacher']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    // Module details (accessible to all authenticated users)
    Route::get('/modules/{id}', [ModuleController::class, 'show']);
    Route::get('/resources/{id}', [ResourceController::class, 'show']);

    // Student routes
    Route::prefix('student')->group(function () {
        // Teacher discovery
        Route::get('/teachers', [TeacherController::class, 'index']);
        Route::get('/teachers/{id}', [TeacherController::class, 'show']);

        // Module browsing
        Route::get('/modules', [StudentModuleController::class, 'index']);
        Route::get('/modules/{id}', [StudentModuleController::class, 'show']);
        Route::get('/modules/{id}/pricing', [StudentModuleController::class, 'pricing']);
        Route::get('/my-modules', [StudentModuleController::class, 'myModules']);
        Route::get('/modules/{id}/live-sessions', [LiveSessionController::class, 'studentModuleIndex']);
        Route::get('/live-sessions', [LiveSessionController::class, 'studentIndex']);
        Route::post('/live-sessions/{liveSession}/join', [LiveSessionController::class, 'join']);
        Route::get('/progress/summary', [LearningProgressController::class, 'summary']);
        Route::get('/progress/continue', [LearningProgressController::class, 'continueLearning']);
        Route::post('/resources/{id}/progress', [LearningProgressController::class, 'updateResourceProgress']);

        // Wallet management
        Route::get('/wallet', [WalletController::class, 'index']);
        Route::post('/wallet/recharge', [WalletController::class, 'recharge']);
        Route::get('/wallet/transactions', [WalletController::class, 'transactions']);

        // Enrollment
        Route::post('/enroll', [EnrollmentController::class, 'enroll']);
        Route::get('/enrollments', [EnrollmentController::class, 'index']);
    });

    // Teacher routes
    Route::prefix('teacher')->group(function () {
        // Dashboard & Earnings
        Route::get('/dashboard/stats', [TeacherDashboardController::class, 'stats']);
        Route::get('/dashboard/earnings', [TeacherDashboardController::class, 'earnings']);
        
        // Module management
        Route::get('/modules', [ModuleController::class, 'index']);
        Route::post('/modules', [ModuleController::class, 'store']);
        Route::get('/modules/{id}', [ModuleController::class, 'showForTeacher']);
        Route::put('/modules/{id}', [ModuleController::class, 'update']);
        Route::delete('/modules/{id}', [ModuleController::class, 'destroy']);
        Route::get('/modules/{moduleId}/live-sessions', [LiveSessionController::class, 'teacherIndex']);
        Route::post('/modules/{moduleId}/live-sessions', [LiveSessionController::class, 'store']);
        Route::post('/live-sessions/{liveSession}/start', [LiveSessionController::class, 'start']);
        Route::post('/live-sessions/{liveSession}/launch', [LiveSessionController::class, 'launch']);
        Route::post('/live-sessions/{liveSession}/cancel', [LiveSessionController::class, 'cancel']);
        Route::post('/live-sessions/{liveSession}/complete', [LiveSessionController::class, 'complete']);

        // Folder management
        Route::post('/modules/{moduleId}/folders', [FolderController::class, 'store']);
        Route::put('/folders/{id}', [FolderController::class, 'update']);
        Route::delete('/folders/{id}', [FolderController::class, 'destroy']);
        Route::post('/modules/{moduleId}/folders/reorder', [FolderController::class, 'reorder']);

        // Resource management
        Route::post('/folders/{folderId}/resources', [ResourceController::class, 'store']);
        Route::put('/resources/{id}', [ResourceController::class, 'update']);
        Route::delete('/resources/{id}', [ResourceController::class, 'destroy']);

        // Enrollments
        Route::get('/enrollments', [EnrollmentController::class, 'teacherEnrollments']);

        // Withdrawals
        Route::get('/withdrawals', [WithdrawalController::class, 'index']);
        Route::post('/withdrawals', [WithdrawalController::class, 'store']);
    });
});

// Resource viewing (publicly accessible route, validation done in controller via token)
Route::get('/resources/{id}/view', [ResourceController::class, 'view']);
