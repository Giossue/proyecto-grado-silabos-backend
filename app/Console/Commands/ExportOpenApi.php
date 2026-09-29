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
                    ...$this->curricularImportSchemas(),
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
                            'code' => ['type' => 'string'],
                            'errors' => [
                                'type' => 'object',
                                'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
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
            str_contains($name, '.documents.'), str_contains($name, '.signature.') => 'Documentos',
            default => 'Operación',
        };
        $isDownload = $name === 'api.v1.documents.download';
        $isLogoUpload = str_ends_with($name, '.logo.store');
        $isSignaturePdf = $name === 'api.v1.signature.pdf';
        $isCurricularOriginal = $name === 'api.v1.coordination.curricular-imports.documents.original';
        $isSignatureUpload = $name === 'api.v1.signature.store';
        $isPreparePeriod = $name === 'api.v1.coordination.academic.period.prepare';
        $isCurricularImport = str_starts_with($name, 'api.v1.coordination.curricular-imports.');
        $isCurricularImportCreate = $name === 'api.v1.coordination.curricular-imports.store';
        $isCurricularImportRetry = $name === 'api.v1.coordination.curricular-imports.retry';
        $successContent = $isDownload
            ? ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
            : ($isSignaturePdf
                ? ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
                : ['application/json' => ['schema' => ['type' => 'object']]]);
        if ($isCurricularOriginal) {
            $binary = ['schema' => ['type' => 'string', 'format' => 'binary']];
            $successContent = ['application/pdf' => $binary, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => $binary];
        }
        if ($isCurricularImport && ! $isCurricularOriginal) {
            $schema = str_ends_with($name, '.index') ? 'CurricularImportsIndex' : 'CurricularImport';
            $successContent = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]];
        }
        $operation = [
            'tags' => [$tag],
            'operationId' => str_replace('.', '_', $name),
            'summary' => $name,
            'responses' => [
                ($isCurricularImportCreate || $isCurricularImportRetry ? '202' : ($isSignatureUpload ? '201' : '200')) => [
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
        if ($isCurricularImport) {
            $operation['responses']['404'] = $this->errorResponse('El registro o la materia no pertenecen a la carrera activa o no existen.');
            $operation['responses']['409'] = $this->errorResponse('La propuesta, las fuentes o la base curricular cambiaron; consulte de nuevo antes de confirmar.');
        }
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && ! $isCurricularImportRetry) {
            $content = ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]];
            if ($isCurricularImportCreate) {
                $content = ['multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/CurricularImportInput']]];
            } elseif ($isCurricularImport) {
                $content = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/CurricularImportApplyInput']]];
            } elseif ($isLogoUpload) {
                $content = ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['logo'],
                    'properties' => ['logo' => ['type' => 'string', 'format' => 'binary']],
                ]]];
            } elseif ($isSignatureUpload) {
                // I-139: solo viaja el PDF firmado; el certificado y su clave no salen del navegador.
                $content = ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'required' => ['signed_pdf', 'base_sha256'],
                    'properties' => [
                        'signed_pdf' => ['type' => 'string', 'format' => 'binary'],
                        'base_sha256' => ['type' => 'string', 'pattern' => '^[A-Fa-f0-9]{64}$'],
                    ],
                ]]];
            } elseif ($isPreparePeriod) {
                $content = ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['period_id', 'subjects'],
                    'properties' => [
                        'period_id' => ['type' => 'string', 'format' => 'uuid'],
                        'subjects' => ['type' => 'array', 'items' => [
                            'type' => 'object',
                            'required' => ['id', 'parallels'],
                            'properties' => [
                                'id' => ['type' => 'string', 'format' => 'uuid'],
                                'parallels' => ['type' => 'array', 'items' => [
                                    'type' => 'object',
                                    'required' => ['code'],
                                    'properties' => [
                                        'code' => ['type' => 'string'],
                                        'shift' => ['type' => 'string', 'nullable' => true],
                                        'teacher_user_id' => ['type' => 'string', 'format' => 'uuid', 'nullable' => true],
                                    ],
                                ]],
                            ],
                        ]],
                    ],
                ]]];
            }
            $operation['requestBody'] = [
                'required' => $isPreparePeriod || $isCurricularImport,
                'content' => $content,
            ];
        }
        if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            $operation['security'] = [['sanctumCookie' => []]];
        }

        return $operation;
    }

    /** @return array<string, array<string, mixed>> */
    private function curricularImportSchemas(): array
    {
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $nullableUuid = ['type' => ['string', 'null'], 'format' => 'uuid'];
        $nullableText = ['type' => ['string', 'null']];
        $fingerprint = ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'];
        $status = ['type' => 'string', 'enum' => ['pendiente', 'en_ejecucion', 'lista', 'aplicada', 'fallida']];
        $selection = [
            'type' => 'object', 'required' => ['id'], 'additionalProperties' => false,
            'properties' => ['id' => $uuid, 'subject_id' => $nullableUuid, 'accept_conflict' => ['type' => 'boolean']],
        ];

        return [
            'CurricularConceptDefinition' => [
                'type' => 'object', 'required' => ['key', 'label', 'type', 'columns'],
                'properties' => [
                    'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,99}$'],
                    'label' => ['type' => 'string', 'maxLength' => 180],
                    'type' => ['type' => 'string', 'enum' => ['texto', 'lista', 'tabla']],
                    'columns' => ['type' => ['array', 'null'], 'items' => [
                        'type' => 'object', 'required' => ['key', 'label'],
                        'properties' => ['key' => ['type' => 'string'], 'label' => ['type' => 'string']],
                    ]],
                ],
            ],
            'ConfirmedCurricularValue' => [
                'type' => 'object',
                'required' => ['id', 'key', 'concept_key', 'label', 'type', 'columns', 'subject_id',
                    'subject_name', 'subject_code', 'value', 'evidence', 'confirmed_at', 'origin'],
                'properties' => [
                    'id' => $uuid, 'key' => ['type' => 'string'], 'concept_key' => ['type' => 'string'],
                    'label' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['texto', 'lista', 'tabla']],
                    'columns' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['key', 'label'],
                        'properties' => ['key' => ['type' => 'string'], 'label' => ['type' => 'string']]]],
                    'subject_id' => $uuid, 'subject_name' => $nullableText, 'subject_code' => $nullableText,
                    'value' => ['description' => 'Texto, lista de textos o tabla confirmada que usa un sílabo nuevo.'],
                    'evidence' => ['type' => 'array', 'items' => ['type' => 'object',
                        'required' => ['source_name', 'locator', 'quote'],
                        'properties' => ['source_name' => ['type' => 'string'], 'locator' => ['type' => 'string'],
                            'quote' => $nullableText]]],
                    'confirmed_at' => ['type' => 'string', 'format' => 'date-time'],
                    'origin' => ['type' => 'string', 'enum' => ['importacion', 'anterior'],
                        'description' => 'El registro I-144 solo aparece si no lo sustituyó un resultado importado.'],
                ],
            ],
            'CurricularImportProposal' => [
                'type' => 'object', 'required' => ['definitions', 'values', 'issues'],
                'properties' => [
                    'definitions' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CurricularConceptDefinition']],
                    'values' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'required' => ['id', 'concept_key', 'subject_id', 'subject_name', 'value', 'evidence', 'issue', 'issue_code'],
                        'properties' => [
                            'id' => $uuid, 'concept_key' => ['type' => 'string'], 'subject_id' => $nullableUuid, 'subject_name' => $nullableText,
                            'value' => ['description' => 'Texto, lista de textos o filas con celdas de texto según el concepto. Un candidato invalid_value conserva el JSON original para mostrar la excepción.'],
                            'current_value' => ['description' => 'Contenido vigente para el destino identificado, o null si está ausente o sin resolver. Solo lectura.'],
                            'change' => ['type' => ['string', 'null'], 'enum' => [null, 'new', 'unchanged', 'replace'], 'description' => 'Comparación del contenido; null cuando la asignatura todavía no está resuelta.'],
                            'evidence' => ['type' => 'array', 'items' => [
                                'type' => 'object', 'required' => ['source_id', 'locator', 'quote'],
                                'properties' => ['source_id' => $uuid, 'locator' => ['type' => 'string'], 'quote' => ['type' => 'string']],
                            ]],
                            'issue' => $nullableText,
                            'issue_code' => ['type' => ['string', 'null'], 'enum' => [null, 'subject_unmatched', 'invalid_value', 'invalid_evidence', 'conflict', 'source_conflict']],
                        ],
                    ]],
                    'issues' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ],
            'CurricularImport' => [
                'type' => 'object', 'required' => ['id', 'career_id', 'status', 'documents', 'proposal', 'applied_items', 'fingerprint', 'error', 'editable', 'lock_reason'],
                'properties' => [
                    'id' => $uuid, 'career_id' => $uuid, 'status' => $status,
                    'documents' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'required' => ['source_id', 'name', 'hash', 'kind', 'execution_id', 'original_name', 'original_url'],
                        'properties' => [
                            'source_id' => $uuid, 'name' => ['type' => 'string'],
                            'original_name' => $nullableText,
                            'original_url' => ['type' => ['string', 'null'], 'description' => 'Descarga privada autorizada del PDF/XLSX original de esta importación.'],
                            'hash' => ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$'],
                            'kind' => ['type' => 'string', 'enum' => ['pdf', 'xlsx', 'source']], 'execution_id' => $nullableUuid,
                            'file_hash' => $fingerprint, 'original_object_id' => $nullableUuid,
                        ],
                    ]],
                    'proposal' => ['anyOf' => [['$ref' => '#/components/schemas/CurricularImportProposal'], ['type' => 'null']]],
                    'applied_items' => ['type' => 'array', 'items' => [...$selection, 'required' => ['id', 'subject_id', 'accept_conflict']]],
                    'fingerprint' => $fingerprint, 'error' => $nullableText, 'editable' => ['type' => 'boolean'], 'lock_reason' => $nullableText,
                    'message' => ['type' => 'string'],
                ],
            ],
            'CurricularImportsIndex' => [
                'type' => 'object', 'required' => ['imports', 'subjects', 'current_values', 'concepts', 'lock_reason', 'capabilities'],
                'properties' => [
                    'imports' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'required' => ['id', 'status', 'created_at', 'applied_at', 'error'],
                        'properties' => ['id' => $uuid, 'status' => $status, 'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'applied_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'error' => $nullableText],
                    ]],
                    'subjects' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'name', 'code'],
                        'properties' => ['id' => $uuid, 'name' => ['type' => 'string'], 'code' => ['type' => 'string']]]],
                    'current_values' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ConfirmedCurricularValue']],
                    'concepts' => ['type' => 'array', 'description' => 'Datos @ProyectoCarrera que usa la plantilla, con las secciones donde aparecen (I-155); la importación solo busca estos.',
                        'items' => ['type' => 'object', 'required' => ['key', 'label', 'type', 'sections'], 'properties' => [
                            'key' => ['type' => 'string'], 'label' => ['type' => 'string'],
                            'type' => ['type' => 'string', 'enum' => ['texto', 'lista', 'tabla']],
                            'sections' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ]]],
                    'lock_reason' => $nullableText,
                    'capabilities' => ['type' => 'object', 'required' => ['pdf', 'xlsx', 'extraction'], 'properties' => [
                        'pdf' => ['type' => 'boolean'], 'xlsx' => ['type' => 'boolean'], 'extraction' => ['type' => 'boolean'],
                    ]],
                ],
            ],
            'CurricularImportInput' => [
                'type' => 'object', 'required' => ['files'],
                'description' => 'Cada importación trae sus archivos; los documentos son temporales y se eliminan al guardar o descartar (I-152).',
                'properties' => [
                    'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'string', 'format' => 'binary'],
                        'description' => 'Hasta 10 archivos PDF/XLSX privados, de hasta 20 MiB cada uno y 30 MiB combinados.'],
                    'instruction' => ['type' => ['string', 'null'], 'maxLength' => 4000],
                ],
            ],
            'CurricularImportApplyInput' => [
                'type' => 'object', 'required' => ['expected_fingerprint', 'confirmed', 'items'],
                'properties' => [
                    'expected_fingerprint' => $fingerprint, 'confirmed' => ['type' => 'boolean', 'const' => true],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10000, 'items' => $selection],
                    'confirm_purge' => ['type' => 'boolean'],
                ],
            ],
        ];
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
