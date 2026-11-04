<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Modules\Academic\Infrastructure\Persistence\Models\Parallel;
use App\Modules\Academic\Infrastructure\Persistence\Models\Subject;
use App\Modules\Identity\Infrastructure\Mail\ManagedUserActivationMail;
use App\Modules\Operations\Application\WorkflowNotificationMessages;
use App\Modules\Operations\Infrastructure\Mail\CoordinatorSummaryMail;
use App\Modules\Operations\Infrastructure\Mail\DailySummaryMail;
use App\Modules\Operations\Infrastructure\Mail\WorkflowNotificationMail;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Syllabus;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Los correos no repiten la misma idea en el asunto, el título y el cuerpo (S3, 2026-11-03). */
class MailCopyTest extends TestCase
{
    use RefreshDatabase;

    private const SYLLABUS_EVENTS = [
        'coordinacion.aprobado' => 'Sílabo aprobado por Coordinación',
        'silabo.reenviado_biblioteca' => 'Sílabo corregido para revisar',
        'silabo.correccion_solicitada' => 'Corrección solicitada',
        'biblioteca.aprobado' => 'Biblioteca aprobó un sílabo',
        'biblioteca.correccion_solicitada' => 'Biblioteca pidió una corrección',
        'silabo.aprobado' => 'Sílabo aprobado',
        'silabo.eliminado' => 'Su sílabo volvió a empezar',
        'silabo.devuelto_borrador' => 'Su sílabo volvió a borrador',
        'documento.exportacion_fallida' => 'No se pudieron generar los documentos',
    ];

    public function test_each_notice_title_states_the_event_and_its_message_does_not_repeat_it(): void
    {
        $syllabus = $this->syllabus('Programación I');
        foreach (self::SYLLABUS_EVENTS as $event => $expectedTitle) {
            [$title, $message] = WorkflowNotificationMessages::for($event, $syllabus, 2, 'Se cargó mal la malla.');

            $this->assertSame($expectedTitle, $title, $event);
            $this->assertStringNotContainsString(mb_strtolower($title), mb_strtolower($message), $event);
            $this->assertStringEndsNotWith('.', $title, $event);
            $this->assertStringContainsString('Programación I', $message, $event);
            $this->assertSentencesAreUnique($message);
        }
        [$templateTitle, $templateMessage] = WorkflowNotificationMessages::for(WorkflowNotificationMessages::TEMPLATE_REDONE, null, null);
        $this->assertSame('Sus sílabos volvieron a borrador', $templateTitle);
        $this->assertStringContainsString('revíselos y envíelos', $templateMessage);
    }

    public function test_the_two_notices_for_library_have_different_titles_and_say_what_to_do(): void
    {
        $syllabus = $this->syllabus('Contabilidad');
        [$approved, $approvedMessage] = WorkflowNotificationMessages::for('coordinacion.aprobado', $syllabus, 3);
        [$resent, $resentMessage] = WorkflowNotificationMessages::for('silabo.reenviado_biblioteca', $syllabus, 4);

        $this->assertNotSame($approved, $resent);
        $this->assertSame('Revise la bibliografía del sílabo de Contabilidad, revisión 3.', $approvedMessage);
        $this->assertSame('El docente atendió su pedido en el sílabo de Contabilidad, revisión 4. Revise de nuevo la bibliografía.', $resentMessage);
    }

    public function test_back_to_draft_does_not_blame_coordination_for_changes_that_administration_also_makes(): void
    {
        [, $message] = WorkflowNotificationMessages::for('silabo.devuelto_borrador', $this->syllabus('Física'), null);

        $this->assertStringNotContainsString('Coordinación', $message);
        $this->assertSame('Cambiaron datos de la carrera, la malla o el proyecto de carrera que usa el sílabo de Física. Se conservó lo que había escrito; revíselo, envíelo y fírmelo de nuevo.', $message);
    }

    public function test_the_notice_email_uses_the_title_as_subject_and_the_body_only_adds_the_details(): void
    {
        [$title, $message] = WorkflowNotificationMessages::for('coordinacion.aprobado', $this->syllabus('Programación I'), 2);
        $mail = new WorkflowNotificationMail('Ana', $title, $message, 'https://silabos.ueb.test/');

        $html = $mail->render();
        $text = $this->text($html);
        $this->assertSame('Sílabo aprobado por Coordinación', $mail->envelope()->subject);
        $this->assertNull($this->heading($html));
        $this->assertStringNotContainsString($title, $text);
        $this->assertStringContainsString('Hola Ana:', $text);
        $this->assertStringContainsString('Revise la bibliografía del sílabo de Programación I, revisión 2.', $text);
        $this->assertStringContainsString('Abrir el sistema', $text);
        $this->assertStringContainsString('href="https://silabos.ueb.test/"', $html);
        $this->assertSentencesAreUnique($text);
    }

    public function test_the_daily_summaries_share_one_view_with_a_subject_and_a_heading_that_differ(): void
    {
        $teacher = new DailySummaryMail('Ana', ['Turismo: 1 sílabo por corregir. Entrega hasta el 05/11/2026.'], 'Abrir mis sílabos', 'https://silabos.ueb.test/docente/mis-silabos');
        $coordinator = new CoordinatorSummaryMail('Luis', [
            ['career' => 'Turismo', 'review' => 1],
            ['career' => 'Software', 'review' => 0],
            ['career' => 'Agronomía', 'review' => 3],
        ], 'https://silabos.ueb.test/coordinacion/revisiones');

        foreach ([$teacher, $coordinator] as $mail) {
            $html = $mail->render();
            $text = $this->text($html);
            $this->assertSame('mail.daily-summary', $mail->content()->markdown);
            $this->assertSame('Resumen del día: sílabos pendientes', $mail->envelope()->subject);
            $this->assertSame('Pendientes de hoy', $this->heading($html));
            $this->assertStringNotContainsString('Esto espera su atención', $text);
            $this->assertStringNotContainsString('Resumen del día', $text);
            $this->assertSentencesAreUnique($text);
        }

        $teacherText = $this->text($teacher->render());
        $this->assertStringContainsString('Turismo: 1 sílabo por corregir. Entrega hasta el 05/11/2026.', $teacherText);
        $this->assertStringContainsString('Abrir mis sílabos', $teacherText);

        $coordinatorHtml = $coordinator->render();
        $coordinatorText = $this->text($coordinatorHtml);
        $this->assertSame(['Turismo: 1 sílabo por revisar.', 'Agronomía: 3 sílabos por revisar.'], $coordinator->lines());
        $this->assertSame(2, preg_match_all('/<li\b/', $coordinatorHtml));
        $this->assertStringNotContainsString('Software', $coordinatorText);
        $this->assertStringContainsString('Abrir revisión', $coordinatorText);
        $this->assertStringNotContainsString('Abrir Revisión', $coordinatorText);
    }

    public function test_the_activation_email_welcomes_once_and_names_the_action_only_on_the_button(): void
    {
        config(['app.name' => 'Sílabos UEB']);
        $mail = new ManagedUserActivationMail('Ana', 'Docente', 'https://silabos.ueb.test/activar?token=abc', 'Turismo');

        $html = $mail->render();
        $text = $this->text($html);
        $this->assertSame('Su invitación a Sílabos UEB', $mail->envelope()->subject);
        $this->assertSame('Le damos la bienvenida', $this->heading($html));
        $this->assertStringNotContainsString('active su cuenta', mb_strtolower($text));
        $this->assertSame(1, substr_count($text, 'Activar mi cuenta'));
        $this->assertSame(1, substr_count($text, 'Docente'));
        $this->assertSame(1, substr_count($text, 'Turismo'));
        $this->assertSame(1, substr_count($text, 'Si no esperaba este correo'));
        $this->assertStringContainsString('avise a la coordinación de su carrera', $text);
        $this->assertSentencesAreUnique($text);

        $global = $this->text((new ManagedUserActivationMail('Eva', 'Bibliotecario', 'https://silabos.ueb.test/activar?token=def'))->render());
        $this->assertStringContainsString('Tiene una cuenta como Bibliotecario. Para ingresar, elija su contraseña.', $global);
        $this->assertStringContainsString('avise a la administración del sistema', $global);
    }

    public function test_password_reset_has_a_real_heading_and_a_short_fallback_link(): void
    {
        config(['app.url' => 'https://api.test', 'app.frontend_url' => 'https://frontend.test']);
        $user = User::factory()->create();
        $reset = (new ResetPassword('abc'))->toMail($user);

        foreach ([[$reset, 'Solicitud de cambio de contraseña', 'Restablezca su contraseña', 'Restablecer contraseña']] as [$message, $subject, $heading, $button]) {
            $html = (string) $message->render();
            $text = $this->text($html);
            $this->assertSame($subject, $message->subject);
            $this->assertSame($heading, $this->heading($html));
            $this->assertNotSame($message->subject, $this->heading($html));
            $this->assertStringNotContainsString('Hola:', $text);
            $this->assertStringNotContainsString($subject, $text);
            $this->assertStringContainsString('Si el botón no funciona, copie este enlace en su navegador:', $text);
            $this->assertSame(1, substr_count($text, $button));
            $this->assertSentencesAreUnique($text);
        }
    }

    private function syllabus(string $subjectName): Syllabus
    {
        $parallel = new Parallel;
        $parallel->setRelation('subject', (new Subject)->forceFill(['nombre' => $subjectName]));

        return (new Syllabus)->setRelation('parallel', $parallel);
    }

    private function heading(string $html): ?string
    {
        return preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $match) === 1 ? trim(html_entity_decode(strip_tags($match[1]))) : null;
    }

    /** Texto visible del cuerpo, sin el encabezado ni el pie del diseño. */
    private function text(string $html): string
    {
        $start = strpos($html, 'class="content-cell"');
        $this->assertNotFalse($start, 'El correo no usa el diseño esperado.');
        $end = strpos($html, 'class="footer"', $start);
        $body = '<td '.substr($html, $start, $end === false ? null : $end - $start);

        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($body))));
    }

    private function assertSentencesAreUnique(string $text): void
    {
        $sentences = array_values(array_filter(array_map('trim', preg_split('/(?<=[.:;])\s+/u', $text) ?: [])));

        $this->assertSame($sentences, array_values(array_unique($sentences)), 'Hay una frase repetida: '.$text);
    }
}
