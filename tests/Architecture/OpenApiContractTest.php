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
                '/api/v1/admin/template/{template}/sections',
                '/api/v1/teacher/syllabi/{syllabus}',
                '/api/v1/teacher/syllabi/{syllabus}/ai-chat',
                '/api/v1/coordination/curricular-imports',
                '/api/v1/coordination/curricular-imports/{import}/documents/{source}/original',
                '/api/v1/coordination/reviews/{revision}',
                '/api/v1/exports/{artifact}/{format}',
            ])
            ->and($committed['paths']['/api/v1/session']['get']['security'])->toBe([['sanctumCookie' => []]])
            ->and($committed['paths']['/api/v1/admin/template/logo']['post']['requestBody']['content'])
            ->toHaveKey('multipart/form-data')
            ->and($committed['paths']['/api/v1/exports/{artifact}/{format}']['get']['responses']['200']['content'])
            ->toHaveKey('application/octet-stream');
        foreach (array_keys($committed['paths']) as $path) {
            expect($path)->not->toStartWith('/api/v1/coordination/sources')
                ->and($path)->not->toEndWith('/ai-review')
                ->and($path)->not->toContain('/fields/{field}/ai');
        }
        expect($committed['components']['schemas'])->not->toHaveKey('SourceDocument')
            ->and($committed['paths']['/api/v1/coordination/curricular-imports/{import}/documents/{source}/original']['get']['responses']['200']['content'])
            ->toHaveKeys(['application/pdf', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    } finally {
        if (is_string($temporaryPath) && file_exists($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
});
