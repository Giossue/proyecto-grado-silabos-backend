<?php

it('deja fuera de la imagen los archivos privados y la carpeta temp', function (): void {
    $lines = array_map('trim', file(dirname(__DIR__, 2).'/.dockerignore') ?: []);

    expect($lines)
        ->toContain('storage/app/private')
        ->toContain('temp');
});

it('crea la carpeta privada dentro de la imagen aunque el contexto no la traiga', function (): void {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/Dockerfile'))->toContain('storage/app/private');
});
