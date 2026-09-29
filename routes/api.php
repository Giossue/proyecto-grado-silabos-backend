<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Presentation\Http\Controllers\AcademicGovernanceController;
use App\Modules\Academic\Presentation\Http\Controllers\CareerAcademicStructureController;
use App\Modules\Academic\Presentation\Http\Controllers\CurricularImportsController;
use App\Modules\Academic\Presentation\Http\Controllers\CurricularImportUploadController;
use App\Modules\AiAssistance\Presentation\Http\Controllers\AiChatController;
use App\Modules\Configuration\Presentation\Http\Controllers\TemplateController;
use App\Modules\Documents\Presentation\Http\Controllers\DocumentController;
use App\Modules\Documents\Presentation\Http\Controllers\SignatureController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\ActivationController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\EmailVerificationController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionManagementController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\TwoFactorController;
use App\Modules\Identity\Presentation\Http\Controllers\CareerTeacherController;
use App\Modules\Identity\Presentation\Http\Controllers\ManagedUserController;
use App\Modules\Operations\Presentation\Http\Controllers\AuditEventController;
use App\Modules\Operations\Presentation\Http\Controllers\JobExecutionController;
use App\Modules\Operations\Presentation\Http\Controllers\NotificationController;
use App\Modules\Syllabus\Presentation\Http\Controllers\ConvocationController;
use App\Modules\Syllabus\Presentation\Http\Controllers\ReviewController;
use App\Modules\Syllabus\Presentation\Http\Controllers\SyllabusController;
use App\Modules\Syllabus\Presentation\Http\Controllers\SyllabusProcessController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('authentication/login', [SessionController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.authentication.login');
    Route::post('authentication/password-recovery', [PasswordRecoveryController::class, 'sendLink'])
        ->middleware('throttle:password-recovery')
        ->name('api.v1.authentication.password-recovery.send');
    Route::post('authentication/password-reset', [PasswordRecoveryController::class, 'reset'])
        ->middleware('throttle:password-recovery')
        ->name('api.v1.authentication.password-recovery.reset');
    Route::post('authentication/account-activation', [ActivationController::class, 'activate'])
        ->middleware('throttle:password-recovery')
        ->name('api.v1.authentication.account-activation');
    Route::post('authentication/email-verification/{user}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('api.v1.authentication.email-verification.verify');
    Route::post('authentication/two-factor/challenge', [TwoFactorController::class, 'challenge'])
        ->middleware('throttle:two-factor')
        ->name('api.v1.authentication.two-factor.challenge');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('session', [SessionController::class, 'show'])->name('api.v1.session.show');
        Route::post('authentication/logout', [SessionController::class, 'logout'])->name('api.v1.authentication.logout');
        Route::put('authentication/password', [SessionController::class, 'updatePassword'])
            ->name('api.v1.authentication.password.update');
        Route::post('authentication/email-verification/resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:password-recovery')
            ->name('api.v1.authentication.email-verification.resend');
        Route::get('authentication/two-factor', [TwoFactorController::class, 'status'])
            ->name('api.v1.authentication.two-factor.status');
        Route::post('authentication/two-factor', [TwoFactorController::class, 'enable'])
            ->name('api.v1.authentication.two-factor.enable');
        Route::post('authentication/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->name('api.v1.authentication.two-factor.confirm');
        Route::post('authentication/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])
            ->name('api.v1.authentication.two-factor.recovery-codes.regenerate');
        Route::delete('authentication/two-factor', [TwoFactorController::class, 'disable'])
            ->name('api.v1.authentication.two-factor.disable');
        Route::get('authentication/sessions', [SessionManagementController::class, 'index'])
            ->name('api.v1.authentication.sessions.index');
        Route::delete('authentication/sessions/others', [SessionManagementController::class, 'destroyOthers'])
            ->name('api.v1.authentication.sessions.destroy-others');
        Route::delete('authentication/sessions/{session}', [SessionManagementController::class, 'destroy'])
            ->name('api.v1.authentication.sessions.destroy');

        // La SPA oculta el producto mientras el correo no esté verificado, pero la
        // frontera de seguridad también debe vivir en servidor: un cliente HTTP no
        // puede eludirla llamando directamente a la API.
        Route::middleware('verified')->group(function (): void {
            Route::put('active-role', [SessionController::class, 'selectActiveRole'])
                ->name('api.v1.active-role.update');
            Route::put('authentication/profile', [SessionController::class, 'updateProfile'])
                ->middleware('active-role')
                ->name('api.v1.authentication.profile.update');
            Route::get('dashboard', [DashboardController::class, 'index'])
                ->middleware('active-role')
                ->name('api.v1.dashboard.show');
            Route::get('notifications', [NotificationController::class, 'index'])
                ->name('api.v1.notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
                ->name('api.v1.notifications.read-all');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
                ->name('api.v1.notifications.read');
            Route::post('notifications/{notification}/open', [NotificationController::class, 'open'])
                ->name('api.v1.notifications.open');
            Route::get('admin/users', [ManagedUserController::class, 'index'])
                ->middleware('active-role')
                ->name('api.v1.admin.users.index');
            Route::post('admin/users', [ManagedUserController::class, 'store'])
                ->middleware('active-role')
                ->name('api.v1.admin.users.store');
            Route::patch('admin/users/{user}', [ManagedUserController::class, 'update'])
                ->middleware('active-role')->name('api.v1.admin.users.update');
            Route::post('admin/users/{user}/resend-activation', [ManagedUserController::class, 'resendActivation'])
                ->middleware(['active-role', 'throttle:password-recovery'])->name('api.v1.admin.users.activation.resend');
            Route::delete('admin/users/{user}', [ManagedUserController::class, 'destroy'])
                ->middleware('active-role')->name('api.v1.admin.users.destroy');
            Route::get('admin/audit-events', [AuditEventController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.audit-events.index');
            Route::get('admin/job-executions', [JobExecutionController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.job-executions.index');
            Route::post('admin/job-executions/{execution}/retry', [JobExecutionController::class, 'retry'])
                ->middleware('active-role')->name('api.v1.admin.job-executions.retry');
            Route::get('admin/academic/{section?}', [AcademicGovernanceController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.academic.index');
            Route::post('admin/academic/{entity}', [AcademicGovernanceController::class, 'store'])
                ->middleware('active-role')->name('api.v1.admin.academic.store');
            Route::post('admin/academic/careers/{career}/coordinator', [AcademicGovernanceController::class, 'replaceCoordinator'])
                ->middleware('active-role')->whereUuid('career')->name('api.v1.admin.academic.careers.coordinator.replace');
            Route::patch('admin/academic/{entity}/{record}', [AcademicGovernanceController::class, 'update'])
                ->middleware('active-role')->name('api.v1.admin.academic.update');
            Route::patch('admin/academic/{entity}/{record}/status', [AcademicGovernanceController::class, 'setStatus'])
                ->middleware('active-role')->name('api.v1.admin.academic.status');
            Route::delete('admin/academic/{entity}/{record}', [AcademicGovernanceController::class, 'destroy'])
                ->middleware('active-role')->name('api.v1.admin.academic.destroy');
            Route::get('admin/processes', [SyllabusProcessController::class, 'index'])->middleware('active-role')->name('api.v1.admin.processes.index');
            Route::post('admin/processes', [SyllabusProcessController::class, 'store'])->middleware('active-role')->name('api.v1.admin.processes.store');
            Route::delete('admin/processes/{process}', [SyllabusProcessController::class, 'destroy'])->middleware('active-role')->name('api.v1.admin.processes.destroy');
            Route::post('admin/processes/{process}/deadline', [SyllabusProcessController::class, 'extendDeadline'])->middleware('active-role')->name('api.v1.admin.processes.deadline.extend');
            Route::post('admin/processes/{process}/{transition}', [SyllabusProcessController::class, 'transition'])->middleware('active-role')->whereIn('transition', ['pausar', 'reanudar', 'cerrar'])->name('api.v1.admin.processes.transition');
            Route::get('admin/template', [TemplateController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.template.index');
            Route::post('admin/template', [TemplateController::class, 'store'])
                ->middleware('active-role')->name('api.v1.admin.template.store');
            Route::post('admin/template/{template}/format', [TemplateController::class, 'format'])
                ->middleware('active-role')->name('api.v1.admin.template.format');
            Route::post('admin/template/logo', [TemplateController::class, 'storeLogo'])
                ->middleware('active-role')->name('api.v1.admin.template.logo.store');
            Route::get('admin/template/{template}', [TemplateController::class, 'show'])
                ->middleware('active-role')->name('api.v1.admin.template.show');
            Route::patch('admin/template/{template}/appearance', [TemplateController::class, 'updateAppearance'])
                ->middleware('active-role')->name('api.v1.admin.template.appearance.update');
            Route::patch('admin/template/{template}/title', [TemplateController::class, 'updateTitle'])
                ->middleware('active-role')->name('api.v1.admin.template.title.update');
            Route::post('admin/template/{template}/sections', [TemplateController::class, 'storeSection'])
                ->middleware('active-role')->name('api.v1.admin.template.sections.store');
            Route::patch('admin/template/{template}/sections/order', [TemplateController::class, 'reorderSections'])
                ->middleware('active-role')->name('api.v1.admin.template.sections.reorder');
            Route::patch('admin/template/{template}/sections/{section}', [TemplateController::class, 'updateSection'])
                ->middleware('active-role')->name('api.v1.admin.template.sections.update');
            Route::delete('admin/template/{template}/sections/{section}', [TemplateController::class, 'destroySection'])
                ->middleware('active-role')->name('api.v1.admin.template.sections.destroy');
            Route::post('admin/template/{template}/fields', [TemplateController::class, 'storeField'])
                ->middleware('active-role')->name('api.v1.admin.template.fields.store');
            Route::patch('admin/template/{template}/fields/{field}', [TemplateController::class, 'updateField'])
                ->middleware('active-role')->name('api.v1.admin.template.fields.update');
            Route::patch('admin/template/{template}/blocks/order', [TemplateController::class, 'reorderBlocks'])
                ->middleware('active-role')->name('api.v1.admin.template.blocks.reorder');
            Route::patch('admin/template/{template}/blocks/{block}/document', [TemplateController::class, 'updateDocument'])
                ->middleware('active-role')->name('api.v1.admin.template.blocks.document.update');
            Route::delete('admin/template/{template}/blocks/{block}', [TemplateController::class, 'destroyBlock'])
                ->middleware('active-role')->name('api.v1.admin.template.blocks.destroy');
            Route::get('coordination/convocations', [ConvocationController::class, 'index'])
                ->middleware('active-role')->name('api.v1.coordination.convocations.index');
            Route::post('coordination/convocations', [ConvocationController::class, 'store'])
                ->middleware('active-role')->name('api.v1.coordination.convocations.store');
            Route::get('coordination/convocations/{convocation}', [ConvocationController::class, 'show'])
                ->middleware('active-role')->name('api.v1.coordination.convocations.show');
            Route::post('coordination/convocations/{convocation}/{transition}', [ConvocationController::class, 'transition'])
                ->middleware('active-role')->whereIn('transition', ['pausar', 'reanudar'])->name('api.v1.coordination.convocations.transition');
            Route::get('coordination/reviews', [ReviewController::class, 'index'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.index');
            Route::get('coordination/reviews/{before}/compare/{after}', [ReviewController::class, 'compare'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.compare');
            Route::get('coordination/reviews/{revision}', [ReviewController::class, 'show'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.show');
            Route::post('coordination/reviews/{revision}/observations', [ReviewController::class, 'storeObservation'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.observations.store');
            Route::post('coordination/reviews/{revision}/request-correction', [ReviewController::class, 'requestCorrection'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.correction.store');
            Route::post('coordination/reviews/{revision}/approve', [ReviewController::class, 'approve'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.approve');
            Route::post('coordination/syllabi/{syllabus}/reopen', [ReviewController::class, 'reopen'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.reopen');
            Route::post('coordination/syllabi/{syllabus}/reset', [ReviewController::class, 'reset'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.reset');
            Route::get('coordination/academic/curriculum/{curriculum}', [CareerAcademicStructureController::class, 'curriculumBuilder'])
                ->middleware('active-role')->whereUuid('curriculum')->name('api.v1.coordination.academic.curriculum.show');
            Route::get('coordination/curricular-imports', [CurricularImportsController::class, 'index'])
                ->middleware('active-role')->name('api.v1.coordination.curricular-imports.index');
            Route::post('coordination/curricular-imports', [CurricularImportUploadController::class, 'store'])
                ->middleware(['active-role', 'throttle:source-ocr'])->name('api.v1.coordination.curricular-imports.store');
            Route::get('coordination/curricular-imports/{import}', [CurricularImportsController::class, 'show'])
                ->middleware('active-role')->whereUuid('import')->name('api.v1.coordination.curricular-imports.show');
            Route::delete('coordination/curricular-imports/{import}', [CurricularImportsController::class, 'destroy'])
                ->middleware('active-role')->whereUuid('import')->name('api.v1.coordination.curricular-imports.destroy');
            Route::post('coordination/curricular-imports/{import}/apply', [CurricularImportsController::class, 'apply'])
                ->middleware('active-role')->whereUuid('import')->name('api.v1.coordination.curricular-imports.apply');
            Route::get('coordination/curricular-imports/{import}/documents/{source}/original', [CurricularImportUploadController::class, 'original'])
                ->middleware('active-role')->whereUuid(['import', 'source'])->name('api.v1.coordination.curricular-imports.documents.original');
            Route::post('coordination/curricular-imports/{import}/retry', [CurricularImportUploadController::class, 'retry'])
                ->middleware(['active-role', 'throttle:source-ocr'])->whereUuid('import')->name('api.v1.coordination.curricular-imports.retry');
            Route::get('coordination/academic/scheduled-subjects', [CareerAcademicStructureController::class, 'scheduledSubjects'])
                ->middleware('active-role')->name('api.v1.coordination.academic.scheduled-subjects.index');
            Route::get('coordination/academic/teacher-assignments', [CareerAcademicStructureController::class, 'teacherAssignments'])
                ->middleware('active-role')->name('api.v1.coordination.academic.teacher-assignments.index');
            Route::post('coordination/academic/teachers', [CareerTeacherController::class, 'store'])
                ->middleware('active-role')->name('api.v1.coordination.academic.teachers.store');
            Route::post('coordination/academic/teachers/{user}/resend-activation', [ManagedUserController::class, 'resendActivation'])
                ->middleware(['active-role', 'throttle:password-recovery'])->name('api.v1.coordination.academic.teachers.activation.resend');
            Route::post('coordination/academic/prepare-period', [CareerAcademicStructureController::class, 'preparePeriod'])
                ->middleware('active-role')->name('api.v1.coordination.academic.period.prepare');
            Route::post('coordination/academic/parallels', [CareerAcademicStructureController::class, 'storeParallels'])
                ->middleware('active-role')->name('api.v1.coordination.academic.parallels.store');
            Route::patch('coordination/academic/parallels/{parallel}', [CareerAcademicStructureController::class, 'updateParallel'])
                ->middleware('active-role')->whereUuid('parallel')->name('api.v1.coordination.academic.parallels.update');
            Route::delete('coordination/academic/parallels/{parallel}', [CareerAcademicStructureController::class, 'destroyParallel'])
                ->middleware('active-role')->whereUuid('parallel')->name('api.v1.coordination.academic.parallels.destroy');
            Route::post('coordination/academic/{entity}', [CareerAcademicStructureController::class, 'store'])
                ->middleware('active-role')->whereIn('entity', AcademicStructurePermissions::CAREER_GENERIC_CREATE_ENTITIES)->name('api.v1.coordination.academic.store');
            Route::patch('coordination/academic/{entity}/{record}', [CareerAcademicStructureController::class, 'update'])
                ->middleware('active-role')->whereIn('entity', AcademicStructurePermissions::CAREER_UPDATE_ENTITIES)->whereUuid('record')->name('api.v1.coordination.academic.update');
            Route::delete('coordination/academic/{entity}/{record}', [CareerAcademicStructureController::class, 'destroy'])
                ->middleware('active-role')->whereIn('entity', AcademicStructurePermissions::CAREER_GENERIC_DELETE_ENTITIES)->whereUuid('record')->name('api.v1.coordination.academic.destroy');
            Route::delete('coordination/academic/scheduled-subjects/{scheduledSubject}', [CareerAcademicStructureController::class, 'destroyScheduledSubject'])
                ->middleware('active-role')->whereUuid('scheduledSubject')->name('api.v1.coordination.academic.scheduled-subjects.destroy');
            Route::patch('coordination/academic/curriculum/{curriculum}/configuration', [CareerAcademicStructureController::class, 'updateCurriculumConfiguration'])
                ->middleware('active-role')->whereUuid('curriculum')->name('api.v1.coordination.academic.curriculum.configuration.update');
            Route::post('coordination/academic/curriculum/{curriculum}/requirements', [CareerAcademicStructureController::class, 'storeSubjectRequirement'])
                ->middleware('active-role')->whereUuid('curriculum')->name('api.v1.coordination.academic.curriculum.requirements.store');
            Route::delete('coordination/academic/curriculum/{curriculum}/requirements/{requirement}', [CareerAcademicStructureController::class, 'destroySubjectRequirement'])
                ->middleware('active-role')->whereUuid(['curriculum', 'requirement'])->name('api.v1.coordination.academic.curriculum.requirements.destroy');
            Route::delete('coordination/academic/curriculum/{curriculum}/subjects/{subject}', [CareerAcademicStructureController::class, 'destroySubject'])
                ->middleware('active-role')->whereUuid(['curriculum', 'subject'])->name('api.v1.coordination.academic.curriculum.subjects.destroy');
            Route::patch('coordination/academic/curriculum/{curriculum}/subject-layout', [CareerAcademicStructureController::class, 'updateSubjectLayout'])
                ->middleware('active-role')->whereUuid('curriculum')->name('api.v1.coordination.academic.curriculum.layout.update');
            Route::get('teacher/syllabi', [SyllabusController::class, 'index'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.index');
            Route::get('teacher/syllabi/{syllabus}', [SyllabusController::class, 'show'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.show');
            Route::post('teacher/syllabi/{syllabus}/start', [SyllabusController::class, 'start'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.start');
            Route::patch('teacher/syllabi/{syllabus}/fields/{field}', [SyllabusController::class, 'updateField'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.fields.update');
            Route::post('teacher/syllabi/{syllabus}/validate', [SyllabusController::class, 'validateDraft'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.validate');
            Route::post('teacher/syllabi/{syllabus}/submit', [SyllabusController::class, 'submit'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.submit');
            Route::get('teacher/syllabi/{syllabus}/ai-chat', [AiChatController::class, 'show'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.ai-chat.show');
            Route::post('teacher/syllabi/{syllabus}/ai-chat/messages', [AiChatController::class, 'store'])
                ->middleware(['active-role', 'throttle:ai-analysis'])
                ->name('api.v1.teacher.syllabi.ai-chat.messages.store');
            Route::delete('teacher/syllabi/{syllabus}/ai-chat', [AiChatController::class, 'clear'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.ai-chat.clear');
            Route::get('revisions/{revision}/documents', [DocumentController::class, 'show'])
                ->middleware('active-role')->name('api.v1.documents.show');
            Route::post('revisions/{revision}/documents', [DocumentController::class, 'store'])
                ->middleware('active-role')->name('api.v1.documents.store');
            Route::get('exports/{artifact}/{format}', [DocumentController::class, 'download'])
                ->middleware('active-role')->whereIn('format', ['docx', 'pdf'])->name('api.v1.documents.download');
            // I-139: firma electrónica en el navegador; el servidor solo valida el PDF firmado.
            Route::get('revisions/{revision}/signature', [SignatureController::class, 'show'])
                ->middleware('active-role')->name('api.v1.signature.show');
            Route::get('revisions/{revision}/signature/pdf', [SignatureController::class, 'pdf'])
                ->middleware('active-role')->name('api.v1.signature.pdf');
            Route::post('revisions/{revision}/signature', [SignatureController::class, 'store'])
                ->middleware('active-role')->name('api.v1.signature.store');
        });
    });
});
