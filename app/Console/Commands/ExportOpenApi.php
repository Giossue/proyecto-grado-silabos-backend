<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use RuntimeException;

class ExportOpenApi extends Command
{
    protected $signature = 'openapi:export {--output=docs/architecture/openapi.json}';

    protected $description = 'Exporta el inventario OpenAPI de la API pública v1';

    public function handle(): int
    {
        $paths = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (! is_string($name) || ! str_starts_with($name, 'api.v1.')) {
                continue;
            }
            $path = '/'.preg_replace('#/?\{[^}?]+\?\}#', '', $route->uri());
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $paths[$path][strtolower($method)] = $this->operation($route, $name, $path, $method);
            }
        }
        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Sílabos UEB API',
                'version' => '1.0.0',
                'description' => 'Contrato HTTP entre la SPA Vue y la API Laravel. Autenticación de primera parte mediante Sanctum, cookie HttpOnly y CSRF.',
            ],
            'servers' => [['url' => '/', 'description' => 'Servidor configurado por ambiente']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'sanctumCookie' => [
                        'type' => 'apiKey',
                        'in' => 'cookie',
                        'name' => 'laravel_session',
                        'description' => 'Sesión de primera parte; las mutaciones requieren además X-XSRF-TOKEN.',
                    ],
                ],
                'schemas' => [
                    'Message' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => ['message' => ['type' => 'string']],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'errors' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                        ],
                    ],
                    'SourceDocumentNode' => [
                        'type' => 'object',
                        'required' => ['type'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            'attrs' => ['type' => 'object', 'additionalProperties' => true],
                            'marks' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                            'content' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SourceDocumentNode']],
                        ],
                    ],
                    'SourceDocument' => [
                        'allOf' => [
                            ['$ref' => '#/components/schemas/SourceDocumentNode'],
                            [
                                'type' => 'object',
                                'required' => ['schemaVersion', 'type', 'content'],
                                'properties' => [
                                    'schemaVersion' => ['type' => 'integer', 'const' => 1],
                                    'type' => ['type' => 'string', 'const' => 'doc'],
                                    'content' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/SourceDocumentNode']],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    'Unauthorized' => $this->errorResponse('Sesión no autenticada.'),
                    'Forbidden' => $this->errorResponse('La cuenta o el rol activo no tienen acceso.'),
                    'ValidationError' => $this->errorResponse('La entrada no cumple el contrato.'),
                ],
            ],
        ];

        $output = $this->option('output');
        if (! is_string($output) || trim($output) === '') {
            throw new RuntimeException('La ruta de salida OpenAPI no es válida.');
        }
        $path = str_starts_with($output, '/') ? $output : base_path($output);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("No se pudo crear {$directory}.");
        }
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json."\n");
        $this->components->info("OpenAPI exportado en {$path}.");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, string $name, string $path, string $method): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        $parameters = array_map(fn (string $parameter): array => [
            'name' => $parameter,
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
        ], $matches[1]);
        $tag = match (true) {
            str_contains($name, '.authentication.'), $name === 'api.v1.session.show', $name === 'api.v1.active-role.update' => 'Identidad',
            str_contains($name, '.admin.') => 'Administración',
            str_contains($name, '.coordination.') => 'Coordinación',
            str_contains($name, '.teacher.') => 'Docencia',
            str_contains($name, '.documents.') => 'Documentos',
            default => 'Operación',
        };
        $isDownload = $name === 'api.v1.documents.download';
        $isLogoUpload = str_ends_with($name, '.logo.store');
        $isSourcePdfUpload = $name === 'api.v1.coordination.sources.pdf.ingest';
        $isSourceOriginalPdf = $name === 'api.v1.coordination.sources.pdf.original';
        $isSourceAsset = $name === 'api.v1.coordination.sources.assets.show';
        $isSourceContent = $name === 'api.v1.coordination.sources.content.update';
        $successContent = $isDownload
            ? ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
            : ($isSourceOriginalPdf
                ? ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
                : ($isSourceAsset
                ? ['image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
                : ['application/json' => ['schema' => ['type' => 'object']]]));
        $operation = [
            'tags' => [$tag],
            'operationId' => str_replace('.', '_', $name),
            'summary' => $name,
            'responses' => [
                ($isSourcePdfUpload ? '202' : '200') => [
                    'description' => 'Operación completada.',
                    'content' => $successContent,
                ],
                '401' => ['$ref' => '#/components/responses/Unauthorized'],
                '403' => ['$ref' => '#/components/responses/Forbidden'],
                '422' => ['$ref' => '#/components/responses/ValidationError'],
            ],
        ];
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $content = ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]];
            if ($isLogoUpload) {
                $content = ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['logo'],
                    'properties' => ['logo' => ['type' => 'string', 'format' => 'binary']],
                ]]];
            } elseif ($isSourcePdfUpload) {
                $content = ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['pdf'],
                    'properties' => ['pdf' => ['type' => 'string', 'format' => 'binary']],
                ]]];
            } elseif ($isSourceContent) {
                $content = ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['document'],
                    'properties' => ['document' => ['$ref' => '#/components/schemas/SourceDocument']],
                ]]];
            }
            $operation['requestBody'] = [
                'required' => $isSourcePdfUpload,
                'content' => $content,
            ];
        }
        if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            $operation['security'] = [['sanctumCookie' => []]];
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Error'],
                ],
            ],
        ];
    }
}
