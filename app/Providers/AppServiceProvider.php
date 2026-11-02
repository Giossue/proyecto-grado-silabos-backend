<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Academic\Domain\AcademicStructurePermissions;
use App\Modules\Academic\Infrastructure\Access\AcademicRoleEligibility;
use App\Modules\AiAssistance\Domain\Contracts\AiAgentGateway;
use App\Modules\AiAssistance\Domain\Contracts\AiChatGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\BaselineAiAgentGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\BaselineAiChatGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\DisabledAiAgentGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\DisabledAiChatGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\HostedAiAgentGateway;
use App\Modules\AiAssistance\Infrastructure\Gateways\HostedAiChatGateway;
use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Documents\Domain\Contracts\DocumentRenderer;
use App\Modules\Documents\Domain\Policies\ExportArtifactPolicy;
use App\Modules\Documents\Infrastructure\Persistence\Models\ExportArtifact;
use App\Modules\Documents\Infrastructure\Rendering\PhpWordDocumentRenderer;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Identity\Application\Contracts\RoleEligibility;
use App\Modules\Identity\Domain\Enums\RoleCode;
use App\Modules\Identity\Domain\Policies\UserPolicy;
use App\Modules\Library\Domain\Contracts\BibliographicCatalog;
use App\Modules\Library\Infrastructure\Gateways\DisabledBibliographicCatalog;
use App\Modules\Library\Infrastructure\Gateways\KohaPublicCatalog;
use App\Modules\Operations\Application\SystemSettings;
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
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->scoped(TemplateContext::class);
        $this->app->singleton(SystemSettings::class);
        $this->app->bind(RoleEligibility::class, AcademicRoleEligibility::class);
        $this->app->bind(DocumentRenderer::class, PhpWordDocumentRenderer::class);
        $this->app->bind(AiChatGateway::class, function (): AiChatGateway {
            return match ((string) config('ai.driver')) {
                'baseline' => app(BaselineAiChatGateway::class),
                'openai', 'claude', 'anthropic', 'deepseek' => app(HostedAiChatGateway::class),
                default => app(DisabledAiChatGateway::class),
            };
        });
        $this->app->bind(AiAgentGateway::class, function (): AiAgentGateway {
            return match ((string) config('ai.driver')) {
                'baseline' => app(BaselineAiAgentGateway::class),
                'openai', 'claude', 'anthropic', 'deepseek' => app(HostedAiAgentGateway::class),
                default => app(DisabledAiAgentGateway::class),
            };
        });
        $this->app->bind(BibliographicCatalog::class, function (): BibliographicCatalog {
            return match ((string) config('catalog.driver')) {
                'koha' => app(KohaPublicCatalog::class),
                default => app(DisabledBibliographicCatalog::class),
            };
        });
    }

    public function boot(): void
    {
        // Lo guardado en Configuración › Sistema manda sobre el entorno; las tareas en cola lo releen
        // antes de cada trabajo para tomar los cambios sin reiniciar el proceso.
        $this->app->make(SystemSettings::class)->apply();
        Queue::before(fn () => $this->app->make(SystemSettings::class)->apply());
        $this->configureDefaults();
        DevCommands::artisan('queue:listen --queue=critica,documentos,ia,general --tries=1 --timeout=0', 'queue');
    }

    /**
     * Si la API se publica por HTTPS, las direcciones que arma (logos, enlaces) también: el proxy
     * puede entregar la petición por HTTP y el navegador bloquearía el contenido mixto.
     */
    public function secureUrls(): void
    {
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);
        $proxies = config('app.trusted_proxies');
        TrustProxies::at(array_values(array_filter(array_map('trim', explode(',', is_string($proxies) ? $proxies : '')))));
        $this->secureUrls();
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
            'manage-system-settings',
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
            'search-library-catalog',
            fn (User $user): bool => $user->activo
                && app(ActiveRole::class)->hasRole(request(), RoleCode::Teacher),
        );
        Gate::define(
            'view-audit',
            fn (User $user): bool => $user->activo
                && app(ActiveRole::class)->hasRole(request(), RoleCode::Administrator),
        );
        RateLimiter::for('library-catalog', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute((int) config('catalog.limits.requests_per_minute'))
                ->by('library-catalog:'.($user instanceof User ? $user->id : (string) $request->ip()));
        });
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
