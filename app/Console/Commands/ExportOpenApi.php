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
                    'SubjectLearningOutcomes' => $this->learningOutcomesSchema(),
                    'SubjectLearningOutcomesInput' => $this->learningOutcomesInputSchema(),
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
            str_contains($name, '.documents.'), str_contains($name, '.signature.') => 'Documentos',
            default => 'Operación',
        };
        $isDownload = $name === 'api.v1.documents.download';
        $isLogoUpload = str_ends_with($name, '.logo.store');
        $isSourcePdfUpload = $name === 'api.v1.coordination.sources.pdf.ingest';
        $isSourceOriginalPdf = in_array($name, ['api.v1.coordination.sources.pdf.original', 'api.v1.signature.pdf'], true);
        $isSignatureUpload = $name === 'api.v1.signature.store';
        $isSourceAsset = $name === 'api.v1.coordination.sources.assets.show';
        $isSourceContent = $name === 'api.v1.coordination.sources.content.update';
        $isPreparePeriod = $name === 'api.v1.coordination.academic.period.prepare';
        $isLearningOutcomes = str_starts_with($name, 'api.v1.coordination.academic.subjects.learning-outcomes.');
        $isCurricularImport = str_starts_with($name, 'api.v1.coordination.curricular-imports.');
        $isCurricularImportCreate = $name === 'api.v1.coordination.curricular-imports.store';
        $isCurricularImportRetry = $name === 'api.v1.coordination.curricular-imports.retry';
        $successContent = $isDownload
            ? ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
            : ($isSourceOriginalPdf
                ? ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
                : ($isSourceAsset
                ? ['image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']]]
                : ['application/json' => ['schema' => ['type' => 'object']]]));
        if ($isLearningOutcomes) {
            $successContent = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SubjectLearningOutcomes']]];
        }
        if ($isCurricularImport) {
            $schema = str_ends_with($name, '.index') ? 'CurricularImportsIndex' : 'CurricularImport';
            $successContent = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]];
        }
        $operation = [
            'tags' => [$tag],
            'operationId' => str_replace('.', '_', $name),
            'summary' => $name,
            'responses' => [
                ($isSourcePdfUpload || $isCurricularImportCreate || $isCurricularImportRetry ? '202' : ($isSignatureUpload ? '201' : '200')) => [
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
        if ($isLearningOutcomes) {
            $operation['responses']['404'] = $this->errorResponse('La asignatura no pertenece a la carrera activa o no existe.');
            if ($method === 'PUT') {
                $operation['responses']['409'] = $this->errorResponse('Los resultados cambiaron desde la consulta; vuelva a cargarlos.');
            }
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
            } elseif ($isLearningOutcomes) {
                $content = ['application/json' => ['schema' => ['$ref' => '#/components/schemas/SubjectLearningOutcomesInput']]];
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
                'required' => $isSourcePdfUpload || $isPreparePeriod || $isLearningOutcomes || $isCurricularImport,
                'content' => $content,
            ];
        }
        if (in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
            $operation['security'] = [['sanctumCookie' => []]];
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    private function learningOutcomesSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['subject_id', 'source_name', 'source_locator', 'outcomes', 'confirmed_at', 'confirmed_by', 'fingerprint', 'editable', 'lock_reason'],
            'properties' => [
                'subject_id' => ['type' => 'string', 'format' => 'uuid'],
                'source_name' => ['type' => 'string'],
                'source_locator' => ['type' => 'string'],
                'outcomes' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'required' => ['id', 'text', 'position'],
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'text' => ['type' => 'string'],
                        'position' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ]],
                'confirmed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'confirmed_by' => [
                    'type' => ['object', 'null'], 'required' => ['id', 'name'],
                    'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string']],
                ],
                'fingerprint' => ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$'],
                'editable' => ['type' => 'boolean'],
                'lock_reason' => ['type' => ['string', 'null']],
                'message' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function learningOutcomesInputSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['source_name', 'source_locator', 'outcomes', 'confirmed', 'expected_fingerprint'],
            'properties' => [
                'source_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 240],
                'source_locator' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                'outcomes' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => [
                    'type' => 'object', 'required' => ['text'], 'additionalProperties' => false,
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'text' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12000],
                    ],
                ]],
                'confirmed' => ['type' => 'boolean', 'const' => true],
                'expected_fingerprint' => ['type' => ['string', 'null'], 'pattern' => '^[a-f0-9]{64}$'],
                'confirm_purge' => ['type' => 'boolean'],
            ],
        ];
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
                'type' => 'object', 'required' => ['key', 'label', 'type', 'scope', 'columns'],
                'properties' => [
                    'key' => ['type' => 'string', 'pattern' => '^[a-z][a-z0-9_]{0,99}$'],
                    'label' => ['type' => 'string', 'maxLength' => 180],
                    'type' => ['type' => 'string', 'enum' => ['texto', 'lista', 'tabla']],
                    'scope' => ['type' => 'string', 'enum' => ['carrera', 'asignatura']],
                    'columns' => ['type' => ['array', 'null'], 'items' => [
                        'type' => 'object', 'required' => ['key', 'label'],
                        'properties' => ['key' => ['type' => 'string'], 'label' => ['type' => 'string']],
                    ]],
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
                        'type' => 'object', 'required' => ['source_id', 'name', 'hash', 'kind', 'execution_id'],
                        'properties' => [
                            'source_id' => $uuid, 'name' => ['type' => 'string'],
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
                'type' => 'object', 'required' => ['imports', 'subjects', 'sources', 'lock_reason', 'capabilities'],
                'properties' => [
                    'imports' => ['type' => 'array', 'items' => [
                        'type' => 'object', 'required' => ['id', 'status', 'created_at', 'applied_at', 'error'],
                        'properties' => ['id' => $uuid, 'status' => $status, 'created_at' => ['type' => 'string', 'format' => 'date-time'],
                            'applied_at' => ['type' => ['string', 'null'], 'format' => 'date-time'], 'error' => $nullableText],
                    ]],
                    'subjects' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'name', 'code'],
                        'properties' => ['id' => $uuid, 'name' => ['type' => 'string'], 'code' => ['type' => 'string']]]],
                    'sources' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['id', 'name'],
                        'properties' => ['id' => $uuid, 'name' => ['type' => 'string']]]],
                    'lock_reason' => $nullableText,
                    'capabilities' => ['type' => 'object', 'required' => ['pdf', 'xlsx', 'extraction'], 'properties' => [
                        'pdf' => ['type' => 'boolean'], 'xlsx' => ['type' => 'boolean'], 'extraction' => ['type' => 'boolean'],
                    ]],
                ],
            ],
            'CurricularImportInput' => [
                'type' => 'object', 'anyOf' => [['required' => ['files']], ['required' => ['source_ids']]],
                'properties' => [
                    'files' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['type' => 'string', 'format' => 'binary'],
                        'description' => 'Hasta 10 archivos PDF/XLSX privados, de hasta 20 MiB cada uno y 30 MiB combinados.'],
                    'source_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'uniqueItems' => true, 'items' => $uuid],
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
