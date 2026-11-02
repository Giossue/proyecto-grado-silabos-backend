<?php

namespace App\Modules\Documents\Application;

use App\Modules\Configuration\Application\TemplateContext;
use App\Modules\Configuration\Application\TemplateVariables;
use App\Modules\Documents\Domain\SignatureAnchor;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Approval;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\CoordinationReview;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;

/**
 * Variables de la tabla «Revisión y aprobación»: sellos de firma y fechas. Las usan el PDF
 * y la pantalla; la pantalla muestra solo lo que ya ocurrió (sin aprobación, sin sello de biblioteca).
 */
class SignedDocumentVariables
{
    public function __construct(private readonly SyllabusSignatures $signatures) {}

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function of(array $snapshot, SyllabusRevision $revision, ?Approval $approval): array
    {
        $variables = is_array($snapshot['template_variables'] ?? null) ? $snapshot['template_variables'] : [];
        $review = CoordinationReview::query()
            ->with('reviewer:id,nombre')
            ->whereHas('revision', fn ($query) => $query->where('silabo_id', $revision->silabo_id)->where('numero_revision', '<=', $revision->numero_revision))
            ->latest('revisado_en')
            ->first();
        $stamps = [];
        foreach ($this->signatures->of($revision->silabo_id) as $signature) {
            $stamps[$signature->firmado_como] = $this->stamp($signature);
        }
        $dates = ['submitted_on' => $revision->enviado_en->setTimezone('America/Guayaquil')->format('d-m-Y')];
        $coordinator = $review?->reviewer->nombre ?? $approval?->approver->nombre;
        $approvedOn = $review !== null ? $review->revisado_en : $approval?->aprobado_en;
        if ($coordinator !== null && $approvedOn !== null) {
            $dates['coordinator'] = $coordinator;
            $dates['approved_on'] = $approvedOn->setTimezone('America/Guayaquil')->format('d-m-Y');
        }
        if ($approval !== null) {
            $dates['librarian'] = $approval->approver->nombre;
            $dates['verified_on'] = $approval->aprobado_en->setTimezone('America/Guayaquil')->format('d-m-Y');
        }

        return app(TemplateContext::class)->forSnapshot($snapshot, fn (): array => [
            ...$variables,
            ...array_filter(TemplateVariables::approval($dates), fn (string $value): bool => $value !== ''),
            ...TemplateVariables::signatures($stamps),
        ]);
    }

    private function stamp(SyllabusSignature $signature): string
    {
        $issuer = preg_match('/(?:^|,\s*)CN=([^,]+)/', $signature->entidad_emisora, $match) === 1 ? trim($match[1]) : $signature->entidad_emisora;

        return SignatureAnchor::stamp(
            $signature->firmado_como,
            $signature->nombre_en_certificado,
            $signature->firmado_en->setTimezone('America/Guayaquil')->format('d-m-Y H:i'),
            $signature->numero_certificado,
            $issuer,
        );
    }
}
