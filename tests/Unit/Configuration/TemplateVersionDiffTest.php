<?php

namespace Tests\Unit\Configuration;

use App\Modules\Configuration\Domain\TemplateVersionDiff;
use PHPUnit\Framework\TestCase;

class TemplateVersionDiffTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $sections
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function template(array $sections, array $extra = []): array
    {
        return ['version' => 2, 'document' => ['title' => ['text' => 'Sílabo']], ...$extra, 'sections' => $sections];
    }

    /** @return array<string, mixed> */
    private function field(string $key, string $label, string $who = 'carrera', string $content = '<p>Hola</p>'): array
    {
        return ['key' => $key, 'label' => $label, 'quien_llena' => $who, 'content' => [$content]];
    }

    public function test_equal_templates_have_no_changes(): void
    {
        $template = $this->template([['key' => 'a', 'title' => 'A', 'fields' => [$this->field('x', 'Equis')]]]);

        $this->assertSame([], TemplateVersionDiff::changes($template, $template));
    }

    public function test_describes_sections_and_fields_in_short_phrases(): void
    {
        $before = $this->template([
            ['key' => 'a', 'title' => 'A', 'fields' => [$this->field('x', 'Equis'), $this->field('y', 'Ye'), $this->field('z', 'Zeta', 'institucional')]],
            ['key' => 'b', 'title' => 'B', 'fields' => [$this->field('w', 'Doble')]],
            ['key' => 'c', 'title' => 'C', 'fields' => []],
        ]);
        $after = $this->template([
            ['key' => 'a', 'title' => 'Primera', 'orientation' => 'landscape', 'fields' => [$this->field('x', 'Equis nueva', 'carrera', '<p>Adiós</p>'), $this->field('z', 'Zeta', 'carrera')]],
            ['key' => 'b', 'title' => 'B', 'fields' => [$this->field('y', 'Ye'), $this->field('v', 'Uve')]],
            ['key' => 'd', 'title' => 'D', 'fields' => []],
        ], ['blocks' => ['nuevo' => []]]);

        $this->assertSame([
            'Sección agregada: «D»',
            'Sección quitada: «C»',
            'Sección renombrada: «A» → «Primera»',
            'Orientación cambiada: «Primera»',
            'Campo agregado: «Uve» en «B»',
            'Campo quitado: «Doble»',
            'Campo renombrado: «Equis» → «Equis nueva»',
            'Campo movido: «Ye», de «A» a «B»',
            'Campo «Zeta»: ahora se puede editar',
            'Contenido cambiado: «Equis nueva»',
            'Otros ajustes de la plantilla cambiados',
        ], TemplateVersionDiff::changes($before, $after));
    }

    public function test_reports_order_title_and_fields_without_label(): void
    {
        $before = $this->template([
            ['key' => 'a', 'title' => 'A', 'fields' => [$this->field('x', ''), $this->field('y', 'Ye', 'carrera')]],
            ['key' => 'b', 'title' => 'B', 'fields' => []],
        ]);
        $after = $this->template([
            ['key' => 'b', 'title' => 'B', 'fields' => []],
            ['key' => 'a', 'title' => 'A', 'fields' => [$this->field('y', 'Ye', 'institucional'), $this->field('x', '')]],
        ], ['document' => ['title' => ['text' => 'Nuevo']]]);

        $this->assertSame([
            'Secciones en otro orden',
            'Campos en otro orden en «A»',
            'Campo «Ye»: ahora no se puede editar',
            'Título del documento cambiado',
        ], TemplateVersionDiff::changes($before, $after));
    }
}
