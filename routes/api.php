<?php

use App\Http\Controllers\DashboardController;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Presentation\Http\Controllers\AcademicGovernanceController;
use App\Modules\Academic\Presentation\Http\Controllers\CareerAcademicStructureController;
use App\Modules\Academic\Presentation\Http\Controllers\CareerImportController;
use App\Modules\Academic\Presentation\Http\Controllers\CurriculumImportController;
use App\Modules\Academic\Presentation\Http\Controllers\FichaImportsController;
use App\Modules\Academic\Presentation\Http\Controllers\SubjectProfileController;
use App\Modules\Academic\Presentation\Http\Controllers\SupportDocumentController;
use App\Modules\AiAssistance\Presentation\Http\Controllers\AiChatController;
use App\Modules\AiAssistance\Presentation\Http\Controllers\TemplateAssistantController;
use App\Modules\Configuration\Presentation\Http\Controllers\TemplateController;
use App\Modules\Configuration\Presentation\Http\Controllers\TemplateStructureController;
use App\Modules\Documents\Presentation\Http\Controllers\DocumentController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\AccountActivationResendController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\ActivationController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\PasswordRecoveryController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\SessionManagementController;
use App\Modules\Identity\Presentation\Http\Controllers\Api\V1\TwoFactorController;
use App\Modules\Identity\Presentation\Http\Controllers\CareerTeacherController;
use App\Modules\Identity\Presentation\Http\Controllers\ManagedUserController;
use App\Modules\Library\Presentation\Http\Controllers\BibliographicCatalogController;
use App\Modules\Library\Presentation\Http\Controllers\LibraryReviewController;
use App\Modules\Operations\Presentation\Http\Controllers\AuditEventController;
use App\Modules\Operations\Presentation\Http\Controllers\EmailPreferencesController;
use App\Modules\Operations\Presentation\Http\Controllers\NotificationController;
use App\Modules\Operations\Presentation\Http\Controllers\ScreenHelpController;
use App\Modules\Operations\Presentation\Http\Controllers\SystemSettingsController;
use App\Modules\Syllabus\Presentation\Http\Controllers\ConvocationController;
use App\Modules\Syllabus\Presentation\Http\Controllers\CoordinationHistoryController;
use App\Modules\Syllabus\Presentation\Http\Controllers\ReviewController;
use App\Modules\Syllabus\Presentation\Http\Controllers\SyllabusController;
use App\Modules\Syllabus\Presentation\Http\Controllers\SyllabusProcessController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('authentication/login', [SessionController::class, 'login'])
        ->middleware('throttle:login')
        ->name('api.v1.authentication.login');
    Route::post('authentication/password-recovery', [PasswordRecoveryController::class, 'sendLink'])
        ->middleware('throttle:password-recovery-link')
        ->name('api.v1.authentication.password-recovery.send');
    Route::post('authentication/password-reset', [PasswordRecoveryController::class, 'reset'])
        ->middleware('throttle:password-recovery')
        ->name('api.v1.authentication.password-recovery.reset');
    Route::post('authentication/account-activation', [ActivationController::class, 'activate'])
        ->middleware('throttle:password-recovery')
        ->name('api.v1.authentication.account-activation');
    Route::post('authentication/two-factor/challenge', [TwoFactorController::class, 'challenge'])
        ->middleware('throttle:two-factor')
        ->name('api.v1.authentication.two-factor.challenge');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('session', [SessionController::class, 'show'])->name('api.v1.session.show');
        Route::post('authentication/logout', [SessionController::class, 'logout'])->name('api.v1.authentication.logout');
        Route::put('authentication/password', [SessionController::class, 'updatePassword'])
            ->name('api.v1.authentication.password.update');
        Route::post('authentication/account-activation/resend', AccountActivationResendController::class)
            ->middleware('throttle:password-recovery')
            ->name('api.v1.authentication.account-activation.resend');
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

        Route::middleware('verified')->group(function (): void {
            Route::put('active-role', [SessionController::class, 'selectActiveRole'])
                ->name('api.v1.active-role.update');
            Route::delete('active-role', [SessionController::class, 'leaveActiveRole'])
                ->name('api.v1.active-role.destroy');
            Route::put('authentication/profile', [SessionController::class, 'updateProfile'])
                ->middleware('active-role')
                ->name('api.v1.authentication.profile.update');
            Route::put('authentication/accessibility', [SessionController::class, 'updateAccessibility'])
                ->name('api.v1.authentication.accessibility.update');
            Route::put('authentication/formation', [SessionController::class, 'updateFormation'])
                ->middleware('active-role')
                ->name('api.v1.authentication.formation.update');
            Route::get('dashboard', [DashboardController::class, 'index'])
                ->middleware('active-role')
                ->name('api.v1.dashboard.show');
            Route::get('dashboard/deadline', [DashboardController::class, 'deadline'])
                ->middleware('active-role')
                ->name('api.v1.dashboard.deadline');
            Route::get('notifications', [NotificationController::class, 'index'])
                ->name('api.v1.notifications.index');
            Route::post('notifications/read-all', [NotificationController::class, 'markAllRead'])
                ->name('api.v1.notifications.read-all');
            Route::get('notifications/email-preferences', [EmailPreferencesController::class, 'show'])
                ->name('api.v1.notifications.email-preferences.show');
            Route::put('notifications/email-preferences', [EmailPreferencesController::class, 'update'])
                ->middleware('throttle:20,1')->name('api.v1.notifications.email-preferences.update');
            Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])
                ->name('api.v1.notifications.read');
            Route::post('notifications/{notification}/open', [NotificationController::class, 'open'])
                ->name('api.v1.notifications.open');
            Route::get('help/available', [ScreenHelpController::class, 'available'])
                ->middleware('active-role')->name('api.v1.help.available');
            Route::get('help', [ScreenHelpController::class, 'show'])
                ->middleware('active-role')->name('api.v1.help.show');
            Route::get('admin/users', [ManagedUserController::class, 'index'])
                ->middleware('active-role')
                ->name('api.v1.admin.users.index');
            Route::post('admin/users', [ManagedUserController::class, 'store'])
                ->middleware('active-role')
                ->name('api.v1.admin.users.store');
            Route::get('admin/users/import-template', [ManagedUserController::class, 'importTemplate'])
                ->middleware('active-role')->name('api.v1.admin.users.import-template');
            Route::post('admin/users/import', [ManagedUserController::class, 'import'])
                ->middleware(['active-role', 'throttle:10,1'])->name('api.v1.admin.users.import');
            Route::patch('admin/users/{user}', [ManagedUserController::class, 'update'])
                ->middleware('active-role')->name('api.v1.admin.users.update');
            Route::post('admin/users/{user}/resend-activation', [ManagedUserController::class, 'resendActivation'])
                ->middleware(['active-role', 'throttle:password-recovery'])->name('api.v1.admin.users.activation.resend');
            Route::delete('admin/users/{user}/roles/{assignment}', [ManagedUserController::class, 'removeRole'])
                ->middleware('active-role')->name('api.v1.admin.users.roles.destroy');
            Route::delete('admin/users/{user}', [ManagedUserController::class, 'destroy'])
                ->middleware('active-role')->name('api.v1.admin.users.destroy');
            Route::get('admin/system-settings', [SystemSettingsController::class, 'show'])
                ->middleware('active-role')->name('api.v1.admin.system-settings.show');
            Route::put('admin/system-settings/{section}', [SystemSettingsController::class, 'update'])
                ->middleware(['active-role', 'throttle:10,1'])->whereIn('section', ['notificaciones', 'ia', 'biblioteca'])
                ->name('api.v1.admin.system-settings.update');
            Route::post('admin/system-settings/{section}/test', [SystemSettingsController::class, 'test'])
                ->middleware(['active-role', 'throttle:10,1'])->whereIn('section', ['ia', 'biblioteca'])
                ->name('api.v1.admin.system-settings.test');
            Route::get('admin/audit-events', [AuditEventController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.audit-events.index');
            Route::get('admin/library-catalog/libraries', [BibliographicCatalogController::class, 'libraries'])
                ->middleware(['active-role', 'throttle:library-catalog'])->name('api.v1.admin.library-catalog.libraries');
            Route::get('admin/academic/{section?}', [AcademicGovernanceController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.academic.index');
            Route::post('admin/academic/{entity}', [AcademicGovernanceController::class, 'store'])
                ->middleware('active-role')->name('api.v1.admin.academic.store');
            Route::get('admin/academic/careers/import-template', [CareerImportController::class, 'template'])
                ->middleware('active-role')->name('api.v1.admin.academic.careers.import-template');
            Route::post('admin/academic/careers/import', [CareerImportController::class, 'store'])
                ->middleware(['active-role', 'throttle:10,1'])->name('api.v1.admin.academic.careers.import');
            Route::patch('admin/academic/{entity}/{record}', [AcademicGovernanceController::class, 'update'])
                ->middleware('active-role')->name('api.v1.admin.academic.update');
            Route::patch('admin/academic/{entity}/{record}/status', [AcademicGovernanceController::class, 'setStatus'])
                ->middleware('active-role')->name('api.v1.admin.academic.status');
            Route::delete('admin/academic/{entity}/{record}', [AcademicGovernanceController::class, 'destroy'])
                ->middleware('active-role')->name('api.v1.admin.academic.destroy');
            Route::get('admin/processes', [SyllabusProcessController::class, 'index'])->middleware('active-role')->name('api.v1.admin.processes.index');
            Route::post('admin/processes', [SyllabusProcessController::class, 'store'])->middleware('active-role')->name('api.v1.admin.processes.store');
            Route::delete('admin/processes/{process}', [SyllabusProcessController::class, 'destroy'])->middleware('active-role')->name('api.v1.admin.processes.destroy');
            Route::get('admin/processes/{process}/impact', [SyllabusProcessController::class, 'impact'])->middleware('active-role')->name('api.v1.admin.processes.impact');
            Route::post('admin/processes/{process}/deadline', [SyllabusProcessController::class, 'extendDeadline'])->middleware('active-role')->name('api.v1.admin.processes.deadline.extend');
            Route::post('admin/processes/{process}/{transition}', [SyllabusProcessController::class, 'transition'])->middleware('active-role')->whereIn('transition', ['pausar', 'reanudar', 'cerrar'])->name('api.v1.admin.processes.transition');
            Route::get('admin/template', [TemplateController::class, 'index'])
                ->middleware('active-role')->name('api.v1.admin.template.index');
            Route::post('admin/template', [TemplateController::class, 'store'])
                ->middleware('active-role')->name('api.v1.admin.template.store');
            Route::post('admin/template/{template}/format', [TemplateController::class, 'format'])
                ->middleware('active-role')->name('api.v1.admin.template.format');
            Route::get('admin/template/assistant', [TemplateAssistantController::class, 'show'])
                ->middleware('active-role')->name('api.v1.admin.template.assistant.show');
            Route::post('admin/template/assistant/messages', [TemplateAssistantController::class, 'store'])
                ->middleware('active-role')->name('api.v1.admin.template.assistant.messages.store');
            Route::delete('admin/template/assistant', [TemplateAssistantController::class, 'clear'])
                ->middleware('active-role')->name('api.v1.admin.template.assistant.clear');
            Route::get('admin/template/assistant/proposals/{proposal}', [TemplateAssistantController::class, 'proposal'])
                ->middleware('active-role')->name('api.v1.admin.template.assistant.proposals.show');
            Route::post('admin/template/assistant/proposals/{proposal}/apply', [TemplateAssistantController::class, 'apply'])
                ->middleware('active-role')->name('api.v1.admin.template.assistant.proposals.apply');
            Route::post('admin/template/assistant/proposals/{proposal}/discard', [TemplateAssistantController::class, 'discard'])
                ->middleware('active-role')->name('api.v1.admin.template.assistant.proposals.discard');
            Route::get('admin/template/{template}/json', [TemplateController::class, 'exportJson'])
                ->middleware('active-role')->name('api.v1.admin.template.json.export');
            Route::put('admin/template/{template}/json', [TemplateController::class, 'importJson'])
                ->middleware('active-role')->name('api.v1.admin.template.json.import');
            Route::post('admin/template/{template}/logo', [TemplateController::class, 'uploadInstitutionLogo'])
                ->middleware('active-role')->name('api.v1.admin.template.logo.store');
            Route::delete('admin/template/{template}/structure', [TemplateController::class, 'clear'])
                ->middleware('active-role')->name('api.v1.admin.template.clear');
            Route::get('admin/template/{template}', [TemplateController::class, 'show'])
                ->middleware('active-role')->name('api.v1.admin.template.show');
            Route::post('admin/template/{template}/structure/sections', [TemplateStructureController::class, 'storeSection'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.sections.store');
            Route::patch('admin/template/{template}/structure/sections/order', [TemplateStructureController::class, 'reorderSections'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.sections.reorder');
            Route::patch('admin/template/{template}/structure/sections/{section}', [TemplateStructureController::class, 'updateSection'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.sections.update');
            Route::delete('admin/template/{template}/structure/sections/{section}', [TemplateStructureController::class, 'destroySection'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.sections.destroy');
            Route::post('admin/template/{template}/structure/sections/{section}/fields', [TemplateStructureController::class, 'storeField'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.fields.store');
            Route::patch('admin/template/{template}/structure/sections/{section}/fields/order', [TemplateStructureController::class, 'reorderFields'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.fields.reorder');
            Route::patch('admin/template/{template}/structure/fields/{field}', [TemplateStructureController::class, 'updateField'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.fields.update');
            Route::delete('admin/template/{template}/structure/fields/{field}', [TemplateStructureController::class, 'destroyField'])
                ->middleware('active-role')->name('api.v1.admin.template.structure.fields.destroy');
            Route::get('coordination/convocations', [ConvocationController::class, 'index'])
                ->middleware('active-role')->name('api.v1.coordination.convocations.index');
            Route::post('coordination/convocations', [ConvocationController::class, 'store'])
                ->middleware('active-role')->name('api.v1.coordination.convocations.store');
            Route::get('coordination/convocations/{convocation}', [ConvocationController::class, 'show'])
                ->middleware('active-role')->name('api.v1.coordination.convocations.show');
            Route::post('coordination/convocations/{convocation}/{transition}', [ConvocationController::class, 'transition'])
                ->middleware('active-role')->whereIn('transition', ['pausar', 'reanudar'])->name('api.v1.coordination.convocations.transition');
            Route::get('coordination/history', [CoordinationHistoryController::class, 'index'])
                ->middleware('active-role')->name('api.v1.coordination.history.index');
            Route::get('coordination/reviews', [ReviewController::class, 'index'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.index');
            Route::get('coordination/reviews/{before}/compare/{after}', [ReviewController::class, 'compare'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.compare');
            Route::get('coordination/reviews/{revision}', [ReviewController::class, 'show'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.show');
            Route::post('coordination/reviews/{revision}/request-correction', [ReviewController::class, 'requestCorrection'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.correction.store');
            Route::post('coordination/reviews/{revision}/approve', [ReviewController::class, 'approve'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.approve');
            Route::post('coordination/syllabi/{syllabus}/reset', [ReviewController::class, 'reset'])
                ->middleware('active-role')->name('api.v1.coordination.reviews.reset');
            Route::get('coordination/academic/curriculum/{curriculum}', [CareerAcademicStructureController::class, 'curriculumBuilder'])
                ->middleware('active-role')->whereUuid('curriculum')->name('api.v1.coordination.academic.curriculum.show');
            Route::get('academic/asignaturas/{asignatura}/ficha', [SubjectProfileController::class, 'show'])
                ->middleware('active-role')->whereUuid('asignatura')->name('api.v1.academic.subjects.profile.show');
            Route::put('academic/asignaturas/{asignatura}/ficha', [SubjectProfileController::class, 'update'])
                ->middleware('active-role')->whereUuid('asignatura')->name('api.v1.academic.subjects.profile.update');
            Route::get('coordination/ficha-imports/template', [FichaImportsController::class, 'template'])
                ->middleware('active-role')->name('api.v1.coordination.ficha-imports.template');
            Route::post('coordination/ficha-imports/spreadsheet', [FichaImportsController::class, 'spreadsheet'])
                ->middleware(['active-role', 'throttle:20,1'])->name('api.v1.coordination.ficha-imports.spreadsheet');
            Route::post('coordination/ficha-imports/apply', [FichaImportsController::class, 'apply'])
                ->middleware(['active-role', 'throttle:20,1'])->name('api.v1.coordination.ficha-imports.apply');
            Route::get('coordination/academic/scheduled-subjects', [CareerAcademicStructureController::class, 'scheduledSubjects'])
                ->middleware('active-role')->name('api.v1.coordination.academic.scheduled-subjects.index');
            Route::get('coordination/academic/teacher-assignments', [CareerAcademicStructureController::class, 'teacherAssignments'])
                ->middleware('active-role')->name('api.v1.coordination.academic.teacher-assignments.index');
            Route::post('coordination/academic/teachers', [CareerTeacherController::class, 'store'])
                ->middleware('active-role')->name('api.v1.coordination.academic.teachers.store');
            Route::get('coordination/academic/curriculum-import/template', [CurriculumImportController::class, 'template'])
                ->middleware('active-role')->name('api.v1.coordination.academic.curriculum-import.template');
            Route::post('coordination/academic/curriculum-import', [CurriculumImportController::class, 'store'])
                ->middleware(['active-role', 'throttle:10,1'])->name('api.v1.coordination.academic.curriculum-import.store');
            Route::get('coordination/academic/teachers/import-template', [CareerTeacherController::class, 'importTemplate'])
                ->middleware('active-role')->name('api.v1.coordination.academic.teachers.import-template');
            Route::post('coordination/academic/teachers/import', [CareerTeacherController::class, 'import'])
                ->middleware(['active-role', 'throttle:10,1'])->name('api.v1.coordination.academic.teachers.import');
            Route::post('coordination/academic/teachers/{user}/resend-activation', [CareerTeacherController::class, 'resendActivation'])
                ->middleware(['active-role', 'throttle:password-recovery'])->name('api.v1.coordination.academic.teachers.activation.resend');
            Route::delete('coordination/academic/teachers/{user}', [CareerTeacherController::class, 'destroy'])
                ->middleware('active-role')->name('api.v1.coordination.academic.teachers.destroy');
            Route::get('coordination/support-documents', [SupportDocumentController::class, 'coordinationIndex'])
                ->middleware('active-role')->name('api.v1.coordination.support-documents.index');
            Route::post('coordination/support-documents', [SupportDocumentController::class, 'store'])
                ->middleware(['active-role', 'throttle:20,1'])->name('api.v1.coordination.support-documents.store');
            Route::delete('coordination/support-documents/{document}', [SupportDocumentController::class, 'destroy'])
                ->middleware('active-role')->whereUuid('document')->name('api.v1.coordination.support-documents.destroy');
            Route::get('support-documents/{document}/view', [SupportDocumentController::class, 'view'])
                ->middleware('active-role')->whereUuid('document')->name('api.v1.support-documents.view');
            Route::post('coordination/academic/parallels', [CareerAcademicStructureController::class, 'storeParallels'])
                ->middleware('active-role')->name('api.v1.coordination.academic.parallels.store');
            Route::patch('coordination/academic/parallels/{parallel}', [CareerAcademicStructureController::class, 'updateParallel'])
                ->middleware('active-role')->whereUuid('parallel')->name('api.v1.coordination.academic.parallels.update');
            Route::put('coordination/academic/parallels/{parallel}/teacher', [CareerAcademicStructureController::class, 'replaceTeacher'])
                ->middleware('active-role')->whereUuid('parallel')->name('api.v1.coordination.academic.parallels.teacher.update');
            Route::delete('coordination/academic/parallels/{parallel}', [CareerAcademicStructureController::class, 'destroyParallel'])
                ->middleware('active-role')->whereUuid('parallel')->name('api.v1.coordination.academic.parallels.destroy');
            Route::post('coordination/academic/{entity}', [CareerAcademicStructureController::class, 'store'])
                ->middleware('active-role')->whereIn('entity', AcademicStructurePermissions::CAREER_GENERIC_CREATE_ENTITIES)->name('api.v1.coordination.academic.store');
            Route::patch('coordination/academic/{entity}/{record}', [CareerAcademicStructureController::class, 'update'])
                ->middleware('active-role')->whereIn('entity', AcademicStructurePermissions::CAREER_UPDATE_ENTITIES)->whereUuid('record')->name('api.v1.coordination.academic.update');
            Route::delete('coordination/academic/{entity}/{record}', [CareerAcademicStructureController::class, 'destroy'])
                ->middleware('active-role')->whereIn('entity', AcademicStructurePermissions::CAREER_GENERIC_DELETE_ENTITIES)->whereUuid('record')->name('api.v1.coordination.academic.destroy');
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
            Route::get('teacher/history', [SyllabusController::class, 'history'])
                ->middleware('active-role')
                ->name('api.v1.teacher.history.index');
            Route::get('teacher/history/{career}', [SyllabusController::class, 'historyCareer'])
                ->middleware('active-role')->whereUuid('career')
                ->name('api.v1.teacher.history.show');
            Route::get('teacher/syllabi/{syllabus}', [SyllabusController::class, 'show'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.show');
            Route::post('teacher/syllabi/{syllabus}/start', [SyllabusController::class, 'start'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.start');
            Route::patch('teacher/syllabi/{syllabus}/fields/{field}', [SyllabusController::class, 'updateField'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.fields.update');
            Route::post('teacher/syllabi/{syllabus}/copy-from/{source}', [SyllabusController::class, 'copyFrom'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.copy-from');
            Route::post('teacher/syllabi/{syllabus}/validate', [SyllabusController::class, 'validateDraft'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.validate');
            Route::post('teacher/syllabi/{syllabus}/signing-statement', [SyllabusController::class, 'signingStatement'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.signing-statement');
            Route::post('teacher/syllabi/{syllabus}/submit', [SyllabusController::class, 'submit'])
                ->middleware('active-role')
                ->name('api.v1.teacher.syllabi.submit');
            Route::get('teacher/support-documents', [SupportDocumentController::class, 'teacherIndex'])
                ->middleware('active-role')
                ->name('api.v1.teacher.support-documents.index');
            Route::get('teacher/library-catalog/search', [BibliographicCatalogController::class, 'search'])
                ->middleware(['active-role', 'throttle:library-catalog'])
                ->name('api.v1.teacher.library-catalog.search');
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
                ->middleware(['active-role', 'throttle:20,1'])->name('api.v1.documents.store');
            Route::get('exports/{artifact}/pdf', [DocumentController::class, 'download'])
                ->middleware('active-role')->name('api.v1.documents.download');
            Route::get('library/reviews', [LibraryReviewController::class, 'index'])
                ->middleware('active-role')->name('api.v1.library.reviews.index');
            Route::get('library/history', [LibraryReviewController::class, 'history'])
                ->middleware('active-role')->name('api.v1.library.history.index');
            Route::get('library/reviews/{revision}', [LibraryReviewController::class, 'show'])
                ->middleware('active-role')->name('api.v1.library.reviews.show');
            Route::post('library/reviews/{revision}/verify', [LibraryReviewController::class, 'verify'])
                ->middleware('active-role')->name('api.v1.library.reviews.verify');
            Route::post('library/reviews/{revision}/comments', [LibraryReviewController::class, 'comment'])
                ->middleware('active-role')->name('api.v1.library.reviews.comments.store');
        });
    });
});
