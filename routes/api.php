<?php

use App\Http\Controllers\Api\AdmissionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassSectionController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GradeChangeRequestController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\SchoolTermController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Middleware\EnsureUserHasRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'app' => config('app.name'),
        'time' => now()->toIso8601String(),
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/dashboard', [DashboardController::class, 'summary']);

    Route::get('/my/profile', [StudentProfileController::class, 'myProfile']);
    Route::put('/my/profile', [StudentProfileController::class, 'updateMyProfile']);
    Route::get('/my/curriculum', [CurriculumController::class, 'forStudent']);
    Route::get('/my/admissions', [AdmissionController::class, 'index']);
    Route::get('/admissions', [AdmissionController::class, 'index']);
    Route::get('/admissions/export', [AdmissionController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/admissions/{admission}', [AdmissionController::class, 'show']);

    Route::get('/programs', [ProgramController::class, 'index']);
    Route::get('/programs/export', [ProgramController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/programs/{program}/usage', [ProgramController::class, 'usage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/programs/{program}/majors/{major}/usage', [ProgramController::class, 'majorUsage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/subjects/export', [SubjectController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/subjects/{subject}/usage', [SubjectController::class, 'usage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/school-terms', [SchoolTermController::class, 'index']);
    Route::get('/school-terms/export', [SchoolTermController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/school-terms/{schoolTerm}/usage', [SchoolTermController::class, 'usage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/curriculum/export', [CurriculumController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/curriculum', [CurriculumController::class, 'index']);
    Route::get('/curriculum/{curriculum}/usage', [CurriculumController::class, 'usage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/class-sections', [ClassSectionController::class, 'index']);
    Route::get('/class-sections/export', [ClassSectionController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/class-sections/{classSection}', [ClassSectionController::class, 'show']);
    Route::get('/class-sections/{classSection}/students/export', [ClassSectionController::class, 'exportStudents']);
    Route::get('/students', [StudentProfileController::class, 'index']);
    Route::get('/students/next-number', [StudentProfileController::class, 'nextNumber']);
    Route::get('/students/check-email', [StudentProfileController::class, 'checkEmail']);
    Route::get('/students/export', [StudentProfileController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/students/{student}', [StudentProfileController::class, 'show']);
    Route::get('/teachers', function () {
        return User::where('role', 'teacher')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    });
    Route::get('/grade-change-requests', [GradeChangeRequestController::class, 'index']);

    Route::middleware(EnsureUserHasRole::class.':teacher')->group(function () {
        Route::post('/class-sections/{classSection}/grades', [GradeController::class, 'updateForClass']);
        Route::post('/grade-change-requests', [GradeChangeRequestController::class, 'store']);
    });

    Route::middleware(EnsureUserHasRole::class.':registrar')->group(function () {
        Route::post('/programs', [ProgramController::class, 'store']);
        Route::put('/programs/{program}', [ProgramController::class, 'update']);
        Route::delete('/programs/{program}', [ProgramController::class, 'destroy']);
        Route::delete('/programs/{program}/majors/{major}', [ProgramController::class, 'destroyMajor']);

        Route::post('/subjects', [SubjectController::class, 'store']);
        Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
        Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

        Route::post('/curriculum', [CurriculumController::class, 'store']);
        Route::put('/curriculum/{curriculum}', [CurriculumController::class, 'update']);
        Route::delete('/curriculum/{curriculum}', [CurriculumController::class, 'destroy']);

        Route::post('/school-terms', [SchoolTermController::class, 'store']);
        Route::put('/school-terms/{schoolTerm}', [SchoolTermController::class, 'update']);
        Route::delete('/school-terms/{schoolTerm}', [SchoolTermController::class, 'destroy']);

        Route::post('/students', [StudentProfileController::class, 'store']);
        Route::put('/students/{student}', [StudentProfileController::class, 'update']);

        Route::post('/admissions', [AdmissionController::class, 'store']);
        Route::patch('/admissions/{admission}/status', [AdmissionController::class, 'updateStatus']);
        Route::post('/admissions/{admission}/enroll-subjects', [AdmissionController::class, 'enrollSubjects']);

        Route::post('/class-sections', [ClassSectionController::class, 'store']);
        Route::put('/class-sections/{classSection}', [ClassSectionController::class, 'update']);

        Route::post('/grade-change-requests/{gradeChangeRequest}/review', [GradeChangeRequestController::class, 'review']);
    });

    Route::middleware(EnsureUserHasRole::class.':it')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::put('/users/{user}', [UserManagementController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword']);
        Route::get('/system/status', [DashboardController::class, 'systemStatus']);
    });
});
