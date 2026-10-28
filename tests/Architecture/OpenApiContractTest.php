<?php

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

it('mantiene el contrato OpenAPI sincronizado con las rutas públicas v1', function (): void {
    $temporaryPath = tempnam(sys_get_temp_dir(), 'silabos-openapi-');

    expect($temporaryPath)->toBeString();

    try {
        Artisan::call('openapi:export', ['--output' => $temporaryPath]);

        $generated = json_decode((string) file_get_contents($temporaryPath), true, 512, JSON_THROW_ON_ERROR);
        $committed = json_decode((string) file_get_contents(base_path('docs/architecture/openapi.json')), true, 512, JSON_THROW_ON_ERROR);

        expect($generated)->toBe($committed)
            ->and($committed['openapi'])->toBe('3.1.0')
            ->and($committed['paths'])->toHaveKeys([
                '/api/v1/session',
                '/api/v1/admin/template/{template}/structure/sections',
                '/api/v1/admin/template/{template}/structure/sections/{section}/fields',
                '/api/v1/admin/template/{template}/structure/fields/{field}',
                '/api/v1/teacher/syllabi/{syllabus}',
                '/api/v1/teacher/syllabi/{syllabus}/ai-chat',
                '/api/v1/coordination/reviews/{revision}',
                '/api/v1/exports/{artifact}/pdf',
                '/api/v1/teacher/syllabi/{syllabus}/signing-statement',
            ])
            ->and($committed['paths']['/api/v1/session']['get']['security'])->toBe([['sanctumCookie' => []]])
            ->and($committed['paths']['/api/v1/exports/{artifact}/pdf']['get']['responses']['200']['content'])
            ->toHaveKey('application/pdf')
            ->and($committed['paths'])->not->toHaveKey('/api/v1/revisions/{revision}/signature')
            ->and($committed['components']['schemas'])->toHaveKey('DecisionSignature')
            ->and($committed['paths']['/api/v1/admin/processes/{process}/{transition}']['post']['requestBody']['content']['application/json']['schema']['properties'])
            ->toHaveKey('confirmar_rehacer')
            ->and($committed['components']['schemas']['Error']['properties'])
            ->toHaveKey('counts');
        foreach (array_keys($committed['paths']) as $path) {
            expect($path)->not->toStartWith('/api/v1/coordination/sources')
                ->and($path)->not->toStartWith('/api/v1/coordination/curricular-imports')
                ->and($path)->not->toEndWith('/project-data')
                ->and($path)->not->toEndWith('/ai-review')
                ->and($path)->not->toContain('/fields/{field}/ai');
        }
        expect($committed['components']['schemas'])->not->toHaveKey('SourceDocument')
            ->and($committed['components']['schemas'])->not->toHaveKey('SubjectLearningOutcomes')
            ->and($committed['components']['schemas'])->not->toHaveKey('SubjectLearningOutcomesInput')
            ->and($committed['paths'])->not->toHaveKey('/api/v1/coordination/academic/subjects/{subject}/learning-outcomes');
    } finally {
        if (is_string($temporaryPath) && file_exists($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
});
