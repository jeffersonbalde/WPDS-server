<?php

use App\Http\Controllers\Api\AdmissionController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClassSectionController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GradeChangeRequestController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\GradeSubmissionController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProgramController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SchoolTermController;
use App\Http\Controllers\Api\StudentProfileController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\TeacherDirectoryController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Middleware\EnsureUserHasRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

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

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

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
        ->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder');
    Route::get('/programs/{program}/usage', [ProgramController::class, 'usage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/programs/{program}/majors/{major}/usage', [ProgramController::class, 'majorUsage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::get('/subjects/export', [SubjectController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder');
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
        ->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder');
    Route::get('/students/{student}', [StudentProfileController::class, 'show']);
    Route::get('/students/{student}/usage', [StudentProfileController::class, 'usage'])
        ->middleware(EnsureUserHasRole::class.':registrar');
    Route::get('/teachers', function () {
        return User::where('role', 'teacher')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    })->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder,it');
    Route::get('/teacher-directory', [TeacherDirectoryController::class, 'index'])
        ->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder,it');
    Route::get('/teacher-directory/export', [TeacherDirectoryController::class, 'export'])
        ->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder,it');
    Route::get('/teacher-directory/{teacher}', [TeacherDirectoryController::class, 'show'])
        ->middleware(EnsureUserHasRole::class.':registrar,admin,stakeholder,it');

    Route::get('/announcements/feed', [AnnouncementController::class, 'feed'])
        ->middleware(EnsureUserHasRole::class.':student');
    Route::get('/announcements', [AnnouncementController::class, 'index'])
        ->middleware(EnsureUserHasRole::class.':registrar');

    Route::get('/grade-change-requests', [GradeChangeRequestController::class, 'index'])
        ->middleware(EnsureUserHasRole::class.':teacher,registrar,admin,stakeholder');

    Route::get('/grade-submissions', [GradeSubmissionController::class, 'index'])
        ->middleware(EnsureUserHasRole::class.':teacher,registrar,admin,stakeholder');
    Route::get('/grade-submissions/{gradeSubmission}', [GradeSubmissionController::class, 'show'])
        ->middleware(EnsureUserHasRole::class.':teacher,registrar,admin,stakeholder');

    Route::middleware(EnsureUserHasRole::class.':teacher')->group(function () {
        Route::post('/class-sections/{classSection}/grades', [GradeController::class, 'updateForClass']);
        Route::post('/class-sections/{classSection}/grades/submit', [GradeController::class, 'submitForClass']);
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

        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy']);

        Route::post('/students', [StudentProfileController::class, 'store']);
        Route::put('/students/{student}', [StudentProfileController::class, 'update']);
        Route::delete('/students/{student}', [StudentProfileController::class, 'destroy']);

        Route::post('/admissions', [AdmissionController::class, 'store']);
        Route::patch('/admissions/{admission}/status', [AdmissionController::class, 'updateStatus']);
        Route::delete('/admissions/{admission}', [AdmissionController::class, 'destroy']);
        Route::post('/admissions/{admission}/enroll-subjects', [AdmissionController::class, 'enrollSubjects']);

        Route::post('/class-sections', [ClassSectionController::class, 'store']);
        Route::put('/class-sections/{classSection}', [ClassSectionController::class, 'update']);
        Route::delete('/class-sections/{classSection}', [ClassSectionController::class, 'destroy']);

        Route::post('/grade-change-requests/{gradeChangeRequest}/review', [GradeChangeRequestController::class, 'review']);
        Route::post('/grade-submissions/{gradeSubmission}/review', [GradeSubmissionController::class, 'review']);
    });

    Route::middleware(EnsureUserHasRole::class.':it')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::get('/users/{user}', [UserManagementController::class, 'show']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::put('/users/{user}', [UserManagementController::class, 'update']);
        Route::post('/users/{user}/reset-password', [UserManagementController::class, 'resetPassword']);
        Route::post('/users/{user}/avatar', [UserManagementController::class, 'updateAvatar']);
        Route::delete('/users/{user}/avatar', [UserManagementController::class, 'destroyAvatar']);
        Route::get('/system/status', [SystemController::class, 'status']);
        Route::get('/system/backups', [SystemController::class, 'indexBackups']);
        Route::post('/system/backups', [SystemController::class, 'createBackup']);
        Route::get('/system/backups/{filename}', [SystemController::class, 'downloadBackup'])
            ->where('filename', 'wpds-backup-\d{8}-\d{6}\.(sql|json)');
        Route::delete('/system/backups/{filename}', [SystemController::class, 'destroyBackup'])
            ->where('filename', 'wpds-backup-\d{8}-\d{6}\.(sql|json)');
        Route::get('/system/backup', [SystemController::class, 'downloadFreshBackup']);
        Route::get('/system/backup-schedule', [SystemController::class, 'showSchedule']);
        Route::put('/system/backup-schedule', [SystemController::class, 'updateSchedule']);
        Route::put('/system/password', [SystemController::class, 'changePassword']);
    });

    Route::middleware(EnsureUserHasRole::class.':admin,stakeholder')->group(function () {
        Route::get('/reports/population', [ReportController::class, 'population']);
        Route::get('/reports/population/export', [ReportController::class, 'exportPopulation']);
        Route::get('/reports/population/students', [ReportController::class, 'populationStudents']);
        Route::get('/reports/performance', [ReportController::class, 'performance']);
        Route::get('/reports/performance/export', [ReportController::class, 'exportPerformance']);
        Route::get('/reports/performance/students', [ReportController::class, 'performanceStudents']);
        Route::get('/reports/grade-operations', [ReportController::class, 'gradeOperations']);
        Route::get('/reports/grade-operations/export', [ReportController::class, 'exportGradeOperations']);
    });

    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware(EnsureUserHasRole::class.':it,admin,stakeholder');
});
