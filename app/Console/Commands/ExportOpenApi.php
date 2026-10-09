<?php

namespace App\Console\Commands;

use App\Modules\Academic\Domain\StudyModality;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
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
                    'AcademicUiOptions' => [
                        'type' => 'object',
                        'required' => ['modalityOptions', 'shiftOptions'],
                        'properties' => [
                            'modalityOptions' => $this->catalogOptions(StudyModality::values()),
                            'shiftOptions' => $this->catalogOptions(Parallel::SHIFTS),
                        ],
                        'additionalProperties' => true,
                    ],
                    'Message' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => ['message' => ['type' => 'string']],
                    ],
                    'FieldDocument' => [
                        'type' => 'object',
                        'required' => ['type', 'content'],
                        'properties' => [
                            'type' => ['const' => 'doc'],
                            'content' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DocumentNode']],
                        ],
                        'additionalProperties' => false,
                    ],
                    'DocumentNode' => [
                        'type' => 'object',
                        'required' => ['type'],
                        'properties' => [
                            'type' => ['enum' => ['paragraph', 'text', 'hardBreak', 'variable', 'bulletList', 'orderedList', 'listItem', 'table', 'tableRow', 'tableCell', 'tableHeader']],
                            'text' => ['type' => 'string'],
                            'attrs' => ['type' => 'object', 'additionalProperties' => true],
                            'marks' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                            'content' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DocumentNode']],
                        ],
                        'additionalProperties' => false,
                    ],
                    'TemplateField' => [
                        'type' => 'object',
                        'required' => ['id', 'key', 'label', 'quien_llena', 'content'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'key' => ['type' => 'string'],
                            'label' => ['type' => 'string'],
                            'help' => ['type' => ['string', 'null']],
                            'quien_llena' => ['enum' => ['docente', 'coordinacion', 'institucional']],
                            'content' => ['$ref' => '#/components/schemas/FieldDocument'],
                        ],
                        'additionalProperties' => false,
                    ],
                    'TemplateSection' => [
                        'type' => 'object',
                        'required' => ['id', 'key', 'title', 'orientation', 'fields'],
                        'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'key' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'description' => ['type' => ['string', 'null']],
                            'orientation' => ['enum' => ['portrait', 'landscape']],
                            'fields' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TemplateField']],
                        ],
                        'additionalProperties' => false,
                    ],
                    'TemplateFieldInput' => [
                        'type' => 'object',
                        'properties' => [
                            'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]*$'],
                            'label' => ['type' => ['string', 'null'], 'maxLength' => 180],
                            'help' => ['type' => ['string', 'null'], 'maxLength' => 2000],
                            'quien_llena' => ['enum' => ['docente', 'coordinacion', 'institucional']],
                            'content' => ['$ref' => '#/components/schemas/FieldDocument'],
                            'position' => ['type' => 'integer', 'minimum' => 1],
                        ],
                        'additionalProperties' => false,
                    ],
                    'DraftFieldInput' => [
                        'type' => 'object',
                        'required' => ['version_bloqueo', 'value'],
                        'properties' => [
                            'version_bloqueo' => ['type' => 'integer', 'minimum' => 0],
                            'value' => ['oneOf' => [['$ref' => '#/components/schemas/FieldDocument'], ['type' => 'null']]],
                        ],
                        'additionalProperties' => false,
                    ],
                    'SubjectProfile' => [
                        'type' => 'object',
                        'required' => ['fields', 'values', 'complete'],
                        'properties' => [
                            'fields' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'required' => ['id', 'key', 'label'],
                                'properties' => [
                                    'id' => ['type' => 'string', 'format' => 'uuid'],
                                    'key' => ['type' => 'string'],
                                    'label' => ['type' => 'string'],
                                    'help' => ['type' => ['string', 'null']],
                                    'section' => ['type' => ['string', 'null']],
                                    'quien_llena' => ['const' => 'coordinacion'],
                                    'content' => ['$ref' => '#/components/schemas/FieldDocument'],
                                ],
                            ]],
                            'values' => ['type' => 'object', 'additionalProperties' => ['type' => ['object', 'null']]],
                            'complete' => ['type' => 'boolean'],
                        ],
                    ],
                    'SubjectProfileInput' => [
                        'type' => 'object',
                        'required' => ['values'],
                        'properties' => [
                            'values' => ['type' => 'object', 'additionalProperties' => ['type' => ['object', 'null']]],
                        ],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            'errors' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                            ],
                            'counts' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'integer', 'minimum' => 0],
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

    /**
     * @return array<string, mixed>
     */
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
            str_contains($name, '.documents.'), str_contains($name, '.signature.') => 'Documentos',
            default => 'Operación',
        };
        $isDownload = $name === 'api.v1.documents.download';
        $isLogoUpload = str_ends_with($name, '.logo.store');
        $isSignaturePdf = $name === 'api.v1.signature.pdf';
        $isSignatureUpload = $name === 'api.v1.signature.store';
        $isSubjectProfile = str_contains($name, '.subjects.profile.');
        $isProcessTransition = $name === 'api.v1.admin.processes.transition';
        $isTemplateRead = in_array($name, ['api.v1.admin.template.index', 'api.v1.admin.template.show'], true);
        $successContent = $isDownload
            ? ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
            : ($isSignaturePdf
                ? ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
                : ['application/json' => ['schema' => ['type' => 'object']]]);
        if ($isSubjectProfile) {
            $successContent = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SubjectProfile']]];
        }
        if ($isTemplateRead) {
            $successContent = ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'templateChangedDuringPause' => ['type' => 'boolean'],
                    'template' => ['oneOf' => [
                        ['type' => 'null'],
                        ['type' => 'object', 'required' => ['id', 'sections'], 'properties' => [
                            'id' => ['type' => 'string', 'format' => 'uuid'],
                            'sections' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TemplateSection']],
                            'titleBlock' => ['type' => 'object', 'properties' => ['text' => ['const' => 'PROGRAMA DE ASIGNATURA (SÍLABO)']]],
                        ], 'additionalProperties' => true],
                    ]],
                ],
                'additionalProperties' => true,
            ]]];
        }
        if ($method === 'GET' && in_array($name, [
            'api.v1.admin.academic.index',
            'api.v1.coordination.academic.curriculum.show',
            'api.v1.coordination.academic.scheduled-subjects.index',
            'api.v1.coordination.academic.teacher-assignments.index',
        ], true)) {
            $successContent = ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['options'],
                'properties' => ['options' => ['$ref' => '#/components/schemas/AcademicUiOptions']],
                'additionalProperties' => true,
            ]]];
        }
        $operation = [
            'tags' => [$tag],
            'operationId' => str_replace('.', '_', $name),
            'summary' => $name,
            'responses' => [
                ($isSignatureUpload ? '201' : '200') => [
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
            } elseif ($isSignatureUpload) {
                $content = ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['signed_pdf', 'base_sha256'],
                    'properties' => [
                        'signed_pdf' => ['type' => 'string', 'format' => 'binary'],
                        'base_sha256' => ['type' => 'string', 'pattern' => '^[A-Fa-f0-9]{64}$'],
                    ],
                ]]];
            } elseif ($isSubjectProfile) {
                $content = ['application/json' => ['schema' => [
                    '$ref' => '#/components/schemas/SubjectProfileInput',
                ]]];
            } elseif ($isProcessTransition) {
                $content = ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['confirmar_rehacer' => ['type' => 'boolean']],
                    'additionalProperties' => false,
                ]]];
            }
            if (in_array($name, ['api.v1.admin.template.structure.fields.store', 'api.v1.admin.template.structure.fields.update'], true)) {
                $content = ['application/json' => ['schema' => [
                    'allOf' => [['$ref' => '#/components/schemas/TemplateFieldInput']],
                    'required' => $method === 'POST' ? ['key', 'quien_llena', 'content'] : [],
                ]]];
            }
            if ($name === 'api.v1.teacher.syllabi.fields.update') {
                $content = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DraftFieldInput']]];
            }
            $operation['requestBody'] = [
                'required' => $isSubjectProfile,
                'content' => $content,
            ];
        }
        if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            $operation['security'] = [['sanctumCookie' => []]];
        }

        return $operation;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, mixed>
     */
    private function catalogOptions(array $values): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'required' => ['value', 'label'],
                'properties' => [
                    'value' => ['type' => 'string', 'enum' => $values],
                    'label' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
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
