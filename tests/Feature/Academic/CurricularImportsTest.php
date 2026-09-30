<?php

namespace Tests\Feature\Academic;

use App\Models\User;
use App\Modules\Academic\Application\CurricularImportProposal;
use App\Modules\Academic\Infrastructure\Persistence\Models\AcademicPeriod;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularConcept;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularImport;
use App\Modules\Academic\Infrastructure\Persistence\Models\CurricularValue;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\ScheduledSubject;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Academic\Infrastructure\Persistence\Models\TeacherAssignment;
use App\Modules\Configuration\Infrastructure\Persistence\Models\AcademicSource;
use App\Modules\Documents\Infrastructure\Persistence\Models\StoredObject;
use App\Modules\Identity\Infrastructure\Persistence\Models\RoleAssignment;
use App\Modules\Operations\Infrastructure\Persistence\Models\AuditEvent;
use App\Modules\Syllabus\Application\GenerateConvocationSyllabi;
use App\Modules\Syllabus\Application\SyllabusSnapshot;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusProcess;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use App\Support\CanonicalHasher;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SyllabusTemplateSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CurricularImportsTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    private RoleAssignment $role;

    private Subject $subject;

    private AcademicSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->coordinator = User::query()->where('correo_electronico', 'coordinador@silabos.test')->firstOrFail();
        $this->role = $this->coordinator->roleAssignments()->firstOrFail();
        $this->subject = Subject::query()->firstOrFail();
        $this->actingAs($this->coordinator)->withSession(['active_role_assignment_id' => $this->role->id]);
        $this->source = $this->newSource();
    }

    private function newSource(string $name = 'Proyecto de carrera'): AcademicSource
    {
        $text = 'Evalúa la calidad del software. Pensamiento crítico. Bibliografía institucional. Resultado actualizado.';

        return AcademicSource::query()->create([
            'carrera_id' => $this->subject->carrera_id, 'nombre' => $name, 'activo' => true,
            'documento' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'attrs' => ['sourcePage' => 141], 'content' => [['type' => 'text', 'text' => $text]]]]],
        ]);
    }

    public function test_stages_typed_data_and_applies_only_selected_rows_with_source_evidence(): void
    {
        $proposal = $this->proposal();
        $proposal['definitions'][] = ['key' => 'habilidades', 'label' => 'Habilidades', 'type' => 'lista'];
        $proposal['definitions'][] = ['key' => 'bibliografia', 'label' => 'Bibliografía', 'type' => 'tabla',
            'columns' => [['key' => 'titulo', 'label' => 'Título'], ['key' => 'autor', 'label' => 'Autor']]];
        $proposal['definitions'][] = ['key' => 'omitido', 'label' => 'Omitido', 'type' => 'texto'];
        $proposal['values'][] = $this->row('habilidades', ['Pensamiento crítico.']);
        $proposal['values'][] = $this->row('bibliografia', [['titulo' => 'Bibliografía institucional.', 'autor' => '']]);
        $proposal['values'][] = $this->row('omitido', 'Dato no seleccionado.');
        $import = $this->staged($proposal);
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->getJson(route('api.v1.coordination.curricular-imports.index'))->assertOk()
            ->assertJsonPath('imports.0.status', 'lista')->assertJsonPath('subjects.0.code', $this->subject->codigo_asignatura)
            ->assertJsonMissingPath('sources')->assertJsonStructure(['capabilities' => ['pdf', 'xlsx', 'extraction']]);
        $payload = $this->payload($import, array_slice($proposal['values'], 0, 3));
        $this->postJson($this->url($import).'/apply', $payload)->assertOk()
            ->assertJsonPath('status', 'aplicada')->assertJsonPath('editable', false)->assertJsonCount(3, 'applied_items');
        $this->assertDatabaseCount('conceptos_curriculares', 3);
        $this->assertDatabaseCount('valores_curriculares', 3);
        $this->assertDatabaseMissing('conceptos_curriculares', ['clave_concepto' => 'omitido']);
        $value = CurricularValue::query()->whereHas('concept', fn ($q) => $q->where('clave_concepto', 'resultados'))->firstOrFail();
        $this->assertSame('Evalúa la calidad del software.', $value->valor_curricular);
        $this->assertSame($this->source->id, $value->evidencia_curricular[0]['source_id']);
        $this->assertSame(app(CanonicalHasher::class)->hash($this->source->documento), $value->evidencia_curricular[0]['source_hash']);
        $this->assertSame($this->coordinator->id, $value->confirmado_por);
        $table = CurricularValue::query()->whereHas('concept', fn ($q) => $q->where('clave_concepto', 'bibliografia'))->firstOrFail();
        $this->assertSame($this->subject->id, $table->asignatura_id);
        $this->assertSame([['autor' => '', 'titulo' => 'Bibliografía institucional.']], $table->valor_curricular);
        $audit = AuditEvent::query()->where('accion', 'academico.importacion_curricular.aplicada')->firstOrFail();
        $this->assertStringNotContainsString('Evalúa', json_encode($audit->getAttributes(), JSON_UNESCAPED_UNICODE));
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict();
        $this->assertDatabaseCount('valores_curriculares', 3);
        $this->assertDatabaseMissing('fuentes_academicas', ['id' => $this->source->id]);
        $this->assertSame('Proyecto de carrera', $value->fresh()->evidencia_curricular[0]['source_name']);
        $this->assertSame('Evalúa la calidad del software.', $value->fresh()->evidencia_curricular[0]['quote']);
        $this->getJson($this->url($import))->assertOk()->assertJsonPath('documents.0.original_url', null);
    }

    public function test_discarding_removes_the_import_and_its_documents_but_keeps_shared_ones(): void
    {
        $shared = $this->staged();
        $import = $this->staged();
        $this->deleteJson($this->url($import))->assertOk();
        $this->assertModelMissing($import);
        $this->assertModelExists($this->source);
        $this->deleteJson($this->url($shared))->assertOk();
        $this->assertModelMissing($this->source);
        $this->assertTrue(AuditEvent::query()->where('accion', 'academico.importacion_curricular.descartada')->exists());
        $this->source = $this->newSource();
        $applied = $this->staged();
        $this->postJson($this->url($applied).'/apply', $this->payload($applied))->assertOk();
        $this->deleteJson($this->url($applied))->assertConflict()->assertJsonPath('code', 'importacion_curricular_no_descartable');
        $this->assertDatabaseCount('valores_curriculares', 1);
    }

    public function test_previous_manual_learning_outcomes_routes_are_retired(): void
    {
        $path = '/api/v1/coordination/academic/subjects/'.$this->subject->id.'/learning-outcomes';
        $this->getJson($path)->assertNotFound();
        $this->putJson($path, ['outcomes' => [['text' => 'No debe guardarse.']]])->assertNotFound();
    }

    public function test_requires_explicit_confirmation_fingerprint_and_known_selected_ids(): void
    {
        $import = $this->staged();
        $this->postJson($this->url($import).'/apply', ['confirmed' => false, 'items' => []])->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmed', 'expected_fingerprint', 'items']);
        $this->postJson($this->url($import).'/apply', [...$this->payload($import), 'items' => [['id' => (string) Str::uuid()]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $row = $this->payload($import)['items'][0];
        $this->postJson($this->url($import).'/apply', [...$this->payload($import), 'items' => [$row, $row]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.id');
        $this->postJson($this->url($import).'/apply', [...$this->payload($import), 'items' => [[...$row, 'value' => 'Texto manual']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_proposal_and_other_import_changes_invalidate_the_confirmation_fingerprint(): void
    {
        $import = $this->staged();
        $payload = $this->payload($import);
        $proposal = $import->propuesta_importacion;
        $proposal['issues'][] = 'Se amplió la cobertura de revisión.';
        $import->update(['propuesta_importacion' => $proposal]);
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict()->assertJsonPath('code', 'conflicto_importacion_curricular');
        $payload = $this->payload($import);
        $other = $this->staged();
        $this->postJson($this->url($other).'/apply', $this->payload($other))->assertOk();
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict()->assertJsonPath('code', 'conflicto_importacion_curricular');
        $this->assertDatabaseCount('valores_curriculares', 1);
    }

    public function test_detail_compares_candidates_to_current_data_without_changing_the_staged_proposal(): void
    {
        $import = $this->staged();
        $this->getJson($this->url($import))->assertJsonPath('proposal.values.0.change', 'new')->assertJsonPath('proposal.values.0.current_value', null);
        $this->postJson($this->url($import).'/apply', $this->payload($import))->assertOk();
        $proposal = $this->proposal();
        $same = $this->staged($proposal);
        $this->getJson($this->url($same))->assertJsonPath('proposal.values.0.change', 'unchanged');
        $proposal['values'][0]['value'] = 'Resultado actualizado.';
        $replacement = $this->staged($proposal);
        $before = $replacement->fresh()->propuesta_importacion;
        $this->getJson($this->url($replacement))->assertJsonPath('proposal.values.0.change', 'replace')
            ->assertJsonPath('proposal.values.0.current_value', 'Evalúa la calidad del software.');
        $this->assertSame($before, $replacement->fresh()->propuesta_importacion);
        $proposal['values'][0]['subject_id'] = null;
        $unresolved = $this->staged($proposal);
        $this->getJson($this->url($unresolved))->assertJsonPath('proposal.values.0.change', null);
    }

    public function test_changed_inactive_or_foreign_sources_cannot_be_confirmed(): void
    {
        $import = $this->staged();
        $payload = $this->payload($import);
        $document = $this->source->documento;
        $this->source->update(['documento' => ['type' => 'doc', 'content' => []]]);
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict()->assertJsonPath('code', 'fuente_importacion_cambiada');
        $this->source->update(['documento' => $document, 'activo' => false]);
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict();
        $this->source->update(['activo' => true, 'carrera_id' => $this->otherSubject()->carrera_id]);
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict();
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->assertSame('lista', $import->fresh()->estado_importacion);
    }

    public function test_permissions_and_career_scope_are_enforced_for_reads_and_application(): void
    {
        $import = $this->staged();
        $payload = $this->payload($import);
        $foreignSubject = $this->otherSubject();
        $this->postJson($this->url($import).'/apply', [...$payload, 'items' => [[...$payload['items'][0], 'subject_id' => $foreignSubject->id]]])
            ->assertNotFound();
        $foreign = $this->staged();
        $foreign->update(['carrera_id' => $foreignSubject->carrera_id]);
        $this->getJson($this->url($foreign))->assertNotFound();
        $this->postJson($this->url($foreign).'/apply', $payload)->assertNotFound();
        foreach (['docente@silabos.test', 'admin@silabos.test'] as $email) {
            $user = User::query()->where('correo_electronico', $email)->firstOrFail();
            $this->actingAs($user)->withSession(['active_role_assignment_id' => $user->roleAssignments()->firstOrFail()->id]);
            $this->getJson(route('api.v1.coordination.curricular-imports.index'))->assertForbidden();
            $this->getJson($this->url($import))->assertForbidden();
            $this->postJson($this->url($import).'/apply', $payload)->assertForbidden();
        }
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_resolved_subject_cannot_be_redirected_to_another_subject_in_the_same_career(): void
    {
        $other = $this->subject->replicate();
        $other->codigo_asignatura = 'SW-OTRA-IDENTIFICADA';
        $other->save();
        $import = $this->staged();
        $payload = $this->payload($import);
        $this->postJson($this->url($import).'/apply', [
            ...$payload, 'items' => [[...$payload['items'][0], 'subject_id' => $other->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items.0.subject_id');
        $this->assertDatabaseCount('conceptos_curriculares', 0);
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->assertSame('lista', $import->fresh()->estado_importacion);
        $this->postJson($this->url($import).'/apply', [
            ...$payload, 'items' => [[...$payload['items'][0], 'subject_id' => $this->subject->id]],
        ])->assertOk();
        $this->assertSame($this->subject->id, CurricularValue::query()->firstOrFail()->asignatura_id);
    }

    public function test_pending_failed_and_replaced_pdf_sources_block_application_even_when_document_hash_is_unchanged(): void
    {
        $import = $this->staged();
        $payload = $this->payload($import);
        foreach (['pendiente', 'en_ejecucion', 'fallida'] as $status) {
            $this->source->update(['estado_ingesta' => $status]);
            $this->postJson($this->url($import).'/apply', $payload)->assertConflict()->assertJsonPath('code', 'fuente_importacion_cambiada');
        }
        $original = StoredObject::query()->create([
            'disco' => 'private', 'ruta_interna' => 'pruebas/original.pdf', 'nombre_logico' => 'original.pdf', 'mime' => 'application/pdf',
            'tamano_bytes' => 10, 'huella_sha256' => str_repeat('a', 64), 'clasificacion' => 'academic_source_pdf',
            'estado' => 'activo', 'propietario_usuario_id' => $this->coordinator->id, 'carrera_id' => $this->subject->carrera_id, 'almacenado_en' => now(),
        ]);
        $replacement = $original->replicate();
        $replacement->ruta_interna = 'pruebas/sustitucion.pdf';
        $replacement->huella_sha256 = str_repeat('b', 64);
        $replacement->save();
        $documents = $import->documentos_importacion;
        $documents[0]['kind'] = 'pdf';
        $documents[0]['original_object_id'] = $original->id;
        $import->update(['documentos_importacion' => $documents]);
        $payload = $this->payload($import);
        $this->source->update(['estado_ingesta' => 'completada', 'objeto_pdf_id' => $replacement->id]);
        $this->postJson($this->url($import).'/apply', $payload)->assertConflict()->assertJsonPath('code', 'fuente_importacion_cambiada');
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->source->update(['objeto_pdf_id' => $original->id]);
        $this->postJson($this->url($import).'/apply', $payload)->assertOk();
        $this->assertDatabaseCount('valores_curriculares', 1);
    }

    public function test_only_unmatched_subject_can_be_resolved_without_changing_extracted_content(): void
    {
        $proposal = $this->proposal();
        $proposal['values'][0]['subject_id'] = null;
        $proposal['values'][0]['subject_name'] = 'Nombre sin coincidencia';
        $import = $this->staged($proposal);
        $this->getJson($this->url($import))->assertOk()->assertJsonPath('proposal.values.0.issue_code', 'subject_unmatched');
        $payload = $this->payload($import);
        $this->postJson($this->url($import).'/apply', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0.subject_id');
        $payload['items'][0]['subject_id'] = $this->subject->id;
        $this->postJson($this->url($import).'/apply', $payload)->assertOk();
        $this->assertSame('Evalúa la calidad del software.', CurricularValue::query()->firstOrFail()->valor_curricular);

        foreach (['conflict', 'invalid_value', 'invalid_evidence'] as $issue) {
            $this->source = $this->newSource("Proyecto de carrera ({$issue})");
            $proposal = $this->proposal();
            $proposal['values'][0]['subject_id'] = null;
            $proposal['values'][0]['subject_name'] = 'Nombre sin coincidencia';
            $proposal['values'][0]['issue'] = 'Necesita otra revisión.';
            $proposal['values'][0]['issue_code'] = $issue;
            $blocked = $this->staged($proposal);
            $payload = $this->payload($blocked);
            $payload['items'][0]['subject_id'] = $this->subject->id;
            $this->postJson($this->url($blocked).'/apply', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0');
        }
    }

    public function test_duplicate_destinations_wrong_table_shape_and_unverified_quotes_are_rejected(): void
    {
        $proposal = $this->proposal();
        $proposal['values'][] = $this->row('resultados', 'Resultado actualizado.');
        $duplicate = $this->staged($proposal);
        $this->postJson($this->url($duplicate).'/apply', $this->payload($duplicate))->assertUnprocessable()->assertJsonValidationErrors('items');

        $proposal = $this->proposal();
        $proposal['definitions'][0]['type'] = 'tabla';
        $proposal['definitions'][0]['columns'] = [['key' => 'texto', 'label' => 'Texto']];
        $proposal['values'][0]['value'] = [['texto' => 'Texto', 'columna_no_definida' => 'Otra']];
        $invalid = $this->staged($proposal);
        $this->getJson($this->url($invalid))->assertJsonPath('proposal.values.0.issue_code', 'invalid_value');
        $this->postJson($this->url($invalid).'/apply', $this->payload($invalid))->assertUnprocessable();

        $proposal = $this->proposal();
        $proposal['values'][0]['evidence'][0]['quote'] = 'Este extracto no aparece en el documento.';
        $uncited = $this->staged($proposal);
        $this->postJson($this->url($uncited).'/apply', $this->payload($uncited))->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->assertDatabaseCount('conceptos_curriculares', 0);
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_discrepant_sources_require_a_single_selected_value_and_explicit_acceptance(): void
    {
        $proposal = $this->proposal();
        $proposal['values'][0]['issue'] = 'Las fuentes presentan resultados diferentes.';
        $proposal['values'][0]['issue_code'] = 'source_conflict';
        $proposal['values'][] = [...$this->row('resultados', 'Resultado actualizado.'),
            'issue' => 'Las fuentes presentan resultados diferentes.', 'issue_code' => 'source_conflict'];
        $import = $this->staged($proposal);
        $payload = $this->payload($import);
        $this->postJson($this->url($import).'/apply', $payload)->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $payload['items'] = array_map(fn (array $row): array => [...$row, 'accept_conflict' => true], $payload['items']);
        $this->postJson($this->url($import).'/apply', $payload)->assertUnprocessable()->assertJsonValidationErrors('items');
        $payload['items'] = [$payload['items'][1]];
        $this->postJson($this->url($import).'/apply', $payload)->assertOk()
            ->assertJsonPath('applied_items.0.id', $payload['items'][0]['id'])->assertJsonPath('applied_items.0.accept_conflict', true);
        $this->assertSame('Resultado actualizado.', CurricularValue::query()->firstOrFail()->valor_curricular);
        $this->assertDatabaseCount('valores_curriculares', 1);
    }

    public function test_evidence_requires_nonempty_complete_normalized_words(): void
    {
        $text = 'La materia tiene 100 horas.';
        $this->source->update(['documento' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['sourcePage' => 141], 'content' => [['type' => 'text', 'text' => $text]]],
        ]]]);
        foreach (['10', '---'] as $quote) {
            $proposal = $this->proposal();
            $proposal['values'][0]['evidence'][0]['quote'] = $quote;
            $import = $this->staged($proposal);
            $this->postJson($this->url($import).'/apply', $this->payload($import))->assertUnprocessable()->assertJsonValidationErrors('items.0');
        }
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_global_concept_structure_conflict_rolls_back_all_selected_values(): void
    {
        CurricularConcept::query()->create(['clave_concepto' => 'resultados', 'etiqueta_concepto' => 'Resultados previos',
            'tipo_concepto' => 'lista']);
        $proposal = $this->proposal();
        array_unshift($proposal['definitions'], ['key' => 'nuevo', 'label' => 'Nuevo', 'type' => 'texto']);
        array_unshift($proposal['values'], $this->row('nuevo', 'Pensamiento crítico.'));
        $import = $this->staged($proposal);
        $this->postJson($this->url($import).'/apply', $this->payload($import))->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseMissing('conceptos_curriculares', ['clave_concepto' => 'nuevo']);
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->assertSame('lista', $import->fresh()->estado_importacion);
    }

    public function test_batch_requires_pause_and_confirmation_and_preserves_other_subjects_and_approved_history(): void
    {
        $second = $this->additionalSubject('SW-SEGUNDA');
        $third = $this->additionalSubject('SW-TERCERA');
        $convocation = $this->convocation();
        $original = Syllabus::query()->forSubjects([$this->subject->id])->firstOrFail();
        $untouched = Syllabus::query()->forSubjects([$third->id])->firstOrFail();
        $approved = Syllabus::query()->forSubjects([$second->id])->firstOrFail();
        $context = $approved->contexto_academico;
        $snapshot = app(SyllabusSnapshot::class)->build($approved);
        $revision = SyllabusRevision::query()->create([
            'silabo_id' => $approved->id, 'numero_revision' => 1, 'clave_idempotencia' => (string) Str::uuid(),
            'version_bloqueo_origen' => $approved->version_bloqueo, 'fotografia' => $snapshot,
            'huella_sha256' => app(CanonicalHasher::class)->hash($snapshot), 'enviado_por' => $this->coordinator->id, 'enviado_en' => now(),
        ]);
        Approval::query()->create(['revision_silabo_id' => $revision->id, 'clave_idempotencia' => (string) Str::uuid(),
            'huella_sha256' => $revision->huella_sha256, 'aprobado_por' => $this->coordinator->id, 'aprobado_en' => now()]);
        $approved->update(['estado' => 'correccion_solicitada']);
        $proposal = $this->proposal();
        $proposal['values'][] = $this->row('resultados', 'Resultado actualizado.', $second->id);
        $import = $this->staged($proposal);
        $payload = $this->payload($import);
        $this->postJson($this->url($import).'/apply', $payload)->assertUnprocessable()->assertJsonValidationErrors('process');
        $convocation->update(['estado' => Convocation::STATE_PAUSED]);
        $pending = Parallel::query()->create(['programacion_asignatura_id' => $third->scheduledSubjects()->firstOrFail()->id, 'codigo' => 'B', 'jornada' => 'matutina']);
        $this->postJson($this->url($import).'/apply', $payload)->assertUnprocessable()->assertJsonPath('errors.purge_count.0', '1');
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->postJson($this->url($import).'/apply', [...$payload, 'confirm_purge' => true])->assertOk();
        $this->assertDatabaseMissing('silabos', ['id' => $original->id]);
        $this->assertDatabaseHas('silabos', ['id' => $untouched->id]);
        $this->assertDatabaseHas('silabos', ['id' => $approved->id]);
        $this->assertDatabaseMissing('silabos', ['paralelo_id' => $pending->id]);
        $this->assertSame($context, $approved->fresh()->contexto_academico);
        $this->assertSame($revision->huella_sha256, $revision->fresh()->huella_sha256);
        $this->assertDatabaseCount('aprobaciones', 1);
        $this->assertDatabaseCount('silabos', 3);
        $this->assertDatabaseCount('valores_curriculares', 2);
    }

    public function test_failed_regeneration_rolls_back_import_concepts_values_and_syllabus(): void
    {
        $convocation = $this->convocation();
        $original = Syllabus::query()->firstOrFail();
        $convocation->update(['estado' => Convocation::STATE_PAUSED]);
        TeacherAssignment::query()->update(['activo' => false]);
        $import = $this->staged();
        $this->postJson($this->url($import).'/apply', [...$this->payload($import), 'confirm_purge' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('convocation');
        $this->assertDatabaseHas('silabos', ['id' => $original->id]);
        $this->assertSame('lista', $import->fresh()->estado_importacion);
        $this->assertDatabaseCount('conceptos_curriculares', 0);
        $this->assertDatabaseCount('valores_curriculares', 0);
    }

    public function test_deleting_a_subject_without_programming_removes_current_data_but_keeps_import_record(): void
    {
        $subject = $this->subject->replicate();
        $subject->codigo_asignatura = 'SW-NUEVA';
        $subject->save();
        $proposal = $this->proposal();
        $proposal['values'][0]['subject_id'] = $subject->id;
        $import = $this->staged($proposal);
        $this->postJson($this->url($import).'/apply', $this->payload($import))->assertOk();
        $this->deleteJson(route('api.v1.coordination.academic.curriculum.subjects.destroy', [$subject->carrera_id, $subject->id]))->assertOk();
        $this->assertDatabaseMissing('asignaturas', ['id' => $subject->id]);
        $this->assertDatabaseCount('valores_curriculares', 0);
        $this->assertDatabaseHas('importaciones_curriculares', ['id' => $import->id, 'estado_importacion' => 'aplicada']);
    }

    public function test_database_rejects_cross_career_values_and_mutating_concept_shape(): void
    {
        $import = $this->staged();
        $this->postJson($this->url($import).'/apply', $this->payload($import))->assertOk();
        $value = CurricularValue::query()->firstOrFail();
        $other = $this->otherSubject();
        try {
            DB::transaction(fn () => $value->update(['asignatura_id' => $other->id]));
            $this->fail('La base debe impedir una asignatura de otra carrera.');
        } catch (QueryException $exception) {
            $this->assertSame('23503', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => $value->update(['asignatura_id' => null]));
            $this->fail('La base debe exigir la asignatura de cada dato.');
        } catch (QueryException $exception) {
            $this->assertSame('23502', $exception->errorInfo[0]);
        }
        try {
            DB::transaction(fn () => $value->concept->update(['tipo_concepto' => 'lista']));
            $this->fail('La estructura del concepto debe conservarse.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    /** @return array<string, mixed> */
    private function proposal(): array
    {
        return ['definitions' => [['key' => 'resultados', 'label' => 'Resultados de aprendizaje', 'type' => 'texto']],
            'values' => [$this->row('resultados', 'Evalúa la calidad del software.')], 'issues' => []];
    }

    /** @return array<string, mixed> */
    private function row(string $key, mixed $value, ?string $subjectId = 'default'): array
    {
        return ['id' => (string) Str::uuid(), 'concept_key' => $key, 'subject_id' => $subjectId === 'default' ? $this->subject->id : $subjectId,
            'subject_name' => $subjectId === null ? null : $this->subject->nombre, 'value' => $value,
            'evidence' => [['source_id' => $this->source->id, 'locator' => 'Página 141, bloque 1', 'quote' => 'Evalúa la calidad del software.']],
            'issue' => null, 'issue_code' => null];
    }

    /** @param array<string, mixed>|null $proposal */
    private function staged(?array $proposal = null): CurricularImport
    {
        return CurricularImport::query()->create([
            'carrera_id' => $this->subject->carrera_id, 'solicitado_por' => $this->coordinator->id, 'estado_importacion' => 'lista',
            'documentos_importacion' => [['source_id' => $this->source->id, 'name' => $this->source->nombre,
                'hash' => app(CanonicalHasher::class)->hash($this->source->documento), 'kind' => 'pdf', 'execution_id' => null]],
            'propuesta_importacion' => app(CurricularImportProposal::class)->normalize($proposal ?? $this->proposal()), 'solicitado_en' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|null  $rows
     * @return array<string, mixed>
     */
    private function payload(CurricularImport $import, ?array $rows = null): array
    {
        return ['expected_fingerprint' => $this->getJson($this->url($import))->assertOk()->json('fingerprint'), 'confirmed' => true,
            'items' => array_map(fn (array $row): array => ['id' => $row['id']], $rows ?? $import->propuesta_importacion['values'])];
    }

    private function url(CurricularImport $import): string
    {
        return route('api.v1.coordination.curricular-imports.show', $import->id);
    }

    private function otherSubject(): Subject
    {
        $career = $this->subject->career->replicate();
        $career->codigo_carrera = 'OTRA';
        $career->nombre = 'Otra carrera';
        $career->save();
        $subject = $this->subject->replicate();
        $subject->carrera_id = $career->id;
        $subject->save();

        return $subject;
    }

    private function additionalSubject(string $code): Subject
    {
        $subject = $this->subject->replicate();
        $subject->codigo_asignatura = $code;
        $subject->save();
        $scheduled = ScheduledSubject::query()->firstOrFail()->replicate();
        $scheduled->asignatura_id = $subject->id;
        $scheduled->save();
        $parallel = Parallel::query()->create(['programacion_asignatura_id' => $scheduled->id, 'codigo' => 'A', 'jornada' => 'matutina']);
        TeacherAssignment::query()->create(['asignacion_rol_id' => TeacherAssignment::query()->firstOrFail()->asignacion_rol_id,
            'paralelo_id' => $parallel->id, 'activo' => true]);

        return $subject;
    }

    private function convocation(): Convocation
    {
        $process = SyllabusProcess::query()->create(['periodo_academico_id' => AcademicPeriod::query()->firstOrFail()->id,
            'inicia_en' => now(), 'entrega_en' => now()->addMonth(), 'estado' => SyllabusProcess::STATE_OPEN]);
        $convocation = Convocation::query()->create(['carrera_id' => $this->subject->carrera_id, 'proceso_id' => $process->id, 'estado' => Convocation::STATE_OPEN]);
        $this->seed(SyllabusTemplateSeeder::class);
        app(GenerateConvocationSyllabi::class)->execute($convocation);

        return $convocation;
    }
}
