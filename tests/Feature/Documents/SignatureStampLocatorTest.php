<?php

namespace Tests\Feature\Documents;

use App\Modules\Documents\Domain\SignatureAnchor;
use App\Modules\Documents\Infrastructure\Rendering\PlainTextPdf;
use App\Modules\Documents\Infrastructure\Rendering\SignatureStampLocator;
use Tests\TestCase;

class SignatureStampLocatorTest extends TestCase
{
    public function test_cada_rol_se_sella_sobre_su_etiqueta_de_firma(): void
    {
        $pdf = (new PlainTextPdf)->write([
            'Revisión y aprobación',
            ...explode("\n", SignatureAnchor::text('docente')),
            ...explode("\n", SignatureAnchor::text('coordinacion')),
            ...explode("\n", SignatureAnchor::text('biblioteca')),
        ]);
        $locator = new SignatureStampLocator;

        $teacher = $locator->locate($pdf, 'docente');
        $coordination = $locator->locate($pdf, 'coordinacion');
        $library = $locator->locate($pdf, 'biblioteca');

        foreach ([$teacher, $coordination, $library] as $stamp) {
            $this->assertIsArray($stamp);
            $this->assertSame(150.0, $stamp['width']);
            $this->assertSame(0, $stamp['page']);
        }
        $this->assertGreaterThan($coordination['y'], $teacher['y']);
        $this->assertGreaterThan($library['y'], $coordination['y']);
    }

    public function test_sin_etiquetas_de_rol_usa_firma_o_el_pie_con_tres_posiciones(): void
    {
        $locator = new SignatureStampLocator;
        $labelled = (new PlainTextPdf)->write(['Firma:', 'Firma:', 'Firma:']);
        $this->assertSame(190.0, $locator->locate($labelled, 'biblioteca')['width']);

        $plain = (new PlainTextPdf)->write(['Sin espacio para firmas']);
        $positions = array_map(fn (string $role): float => $locator->locate($plain, $role)['x'], ['docente', 'coordinacion', 'biblioteca']);
        $this->assertSame($positions, array_values(array_unique($positions)));
        $this->assertSame($positions, [min($positions), $positions[1], max($positions)]);
    }
}
