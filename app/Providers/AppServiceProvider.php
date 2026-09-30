<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Domain\Contracts\CurricularExtractionGateway;
use App\Modules\Academic\Infrastructure\Access\AcademicRoleEligibility;
use App\Modules\Academic\Infrastructure\Gateways\HostedCurricularExtractionGateway;
use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\BaselineAiChatGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\DisabledAiChatGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\HostedAiChatGateway;
use App\Modules\Configuration\Application\Contracts\AcademicSourceFigureCropper;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceDocumentNormalizer;
use App\Modules\Configuration\Domain\Contracts\AcademicSourceOcrGateway;
use App\Modules\Configuration\Infrastructure\Documents\PopplerAcademicSourceFigureCropper;
use App\Modules\Configuration\Infrastructure\Gateways\DeepSeekAcademicSourceDocumentNormalizer;
use App\Modules\Configuration\Infrastructure\Gateways\DisabledAcademicSourceDocumentNormalizer;
use App\Modules\Configuration\Infrastructure\Gateways\OpenParserAcademicSourceGateway;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Policies\ExportArtifactPolicy;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Rendering\PhpWordDocumentRenderer;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Application\Contracts\RoleEligibility;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Domain\Policies\UserPolicy;
use App\Modules\Syllabus\Domain\Policies\ConvocationPolicy;
use App\Modules\Syllabus\Domain\Policies\SyllabusPolicy;
use App\Modules\Syllabus\Domain\Policies\SyllabusProcessPolicy;
use App\Modules\Syllabus\Domain\Policies\SyllabusRevisionPolicy;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CurricularExtractionGateway::class, HostedCurricularExtractionGateway::class);
        $this->app->bind(RoleEligibility::class, AcademicRoleEligibility::class);
        $this->app->bind(DocumentRenderer::class, PhpWordDocumentRenderer::class);
        $this->app->bind(AiChatGateway::class, function (): AiChatGateway {
            return match ((string) config('ai.driver')) {
                'baseline' => app(BaselineAiChatGateway::class),
                'openai', 'claude', 'anthropic', 'deepseek' => app(HostedAiChatGateway::class),
                default => app(DisabledAiChatGateway::class),
            };
        });
        $this->app->bind(AcademicSourceDocumentNormalizer::class, function (): AcademicSourceDocumentNormalizer {
            return (bool) config('ai.source_normalization.enabled')
                && strtolower((string) config('ai.source_normalization.driver')) === 'deepseek'
                ? app(DeepSeekAcademicSourceDocumentNormalizer::class)
                : app(DisabledAcademicSourceDocumentNormalizer::class);
        });
        $this->app->bind(AcademicSourceOcrGateway::class, OpenParserAcademicSourceGateway::class);
        $this->app->bind(AcademicSourceFigureCropper::class, PopplerAcademicSourceFigureCropper::class);
    }

    public function boot(): void
    {
        $this->configureDefaults();
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Convocation::class, ConvocationPolicy::class);
        Gate::policy(SyllabusProcess::class, SyllabusProcessPolicy::class);
        Gate::policy(Syllabus::class, SyllabusPolicy::class);
        Gate::policy(SyllabusRevision::class, SyllabusRevisionPolicy::class);
        Gate::policy(ExportArtifact::class, ExportArtifactPolicy::class);
        Gate::define('manage-academic-governance', fn (User $user): bool => $user->activo
            && AcademicStructurePermissions::isGovernanceContext(
                app(ActiveRole::class)->resolve(request()),
            ));
        Gate::define('manage-career-academics', fn (User $user): bool => $user->activo
            && AcademicStructurePermissions::isCareerContext(
                app(ActiveRole::class)->resolve(request()),
            ));
        Gate::define(
            'manage-templates',
            fn (User $user): bool => $user->activo
                && app(ActiveRole::class)->hasRole(request(), RoleCode::Administrator),
        );
        Gate::define(
            'operate-jobs',
            fn (User $user): bool => $user->activo
                && app(ActiveRole::class)->hasRole(request(), RoleCode::Administrator),
        );
        Gate::define(
            'review-library',
            fn (User $user): bool => $user->activo
                && app(ActiveRole::class)->hasRole(request(), RoleCode::Librarian),
        );
        Gate::define(
            'view-audit',
            fn (User $user): bool => $user->activo
                && app(ActiveRole::class)->hasRole(request(), RoleCode::Administrator),
        );
        RateLimiter::for('ai-analysis', function (Request $request): Limit {
            $user = $request->user();
            $syllabus = $request->route('syllabus');
            $actorKey = $user instanceof User ? $user->id : (string) $request->ip();
            $syllabusKey = $syllabus instanceof Syllabus
                ? $syllabus->id
                : (is_string($syllabus) ? $syllabus : 'unknown');

            return Limit::perMinute((int) config('ai.limits.requests_per_minute'))
                ->by("ai-analysis:{$actorKey}:{$syllabusKey}");
        });
        RateLimiter::for('source-ocr', function (Request $request): Limit {
            $user = $request->user();
            $source = $request->route('source');
            $actorKey = $user instanceof User ? $user->id : (string) $request->ip();
            $sourceKey = $source instanceof AcademicSource ? $source->id : 'unknown';

            return Limit::perMinute(3)->by("source-ocr:{$actorKey}:{$sourceKey}");
        });

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
