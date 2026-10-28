<?php

namespace App\Modules\Documents\Application\Actions;

use App\Models\User;
use App\Modules\Documents\Application\CmsSignatureVerifier;
use App\Modules\Documents\Application\SignerHolderMatcher;
use App\Modules\Documents\Application\SyllabusSignatures;
use App\Modules\Documents\Domain\Data\DecisionSignature;
use App\Modules\Documents\Domain\SigningStatement;
use App\Modules\Documents\Infrastructure\Persistence\Models\SyllabusSignature;
use App\Modules\Identity\Application\ActiveRole;
use App\Modules\Operations\Application\Actions\RecordAuditEvent;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\SyllabusRevision;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SignSyllabusDecision
{
    public function __construct(
        private readonly SyllabusSignatures $signatures,
        private readonly CmsSignatureVerifier $verifier,
        private readonly SignerHolderMatcher $holders,
        private readonly ActiveRole $roles,
        private readonly RecordAuditEvent $audit,
    ) {}

    public function required(string $syllabusId, string $role): bool
    {
        return ! $this->signatures->signed($syllabusId, $role);
    }

    public function execute(
        SyllabusRevision $revision,
        string $role,
        ?DecisionSignature $signature,
        User $actor,
        Request $request,
    ): ?SyllabusSignature {
        if (! $this->required($revision->silabo_id, $role)) {
            return null;
        }
        if ($signature === null) {
            $this->fail('Firme con su firma electrónica.');
        }
        $expected = SigningStatement::for($revision->silabo_id, $role, $revision->huella_sha256);
        if (! hash_equals($expected, $signature->statement)) {
            $this->fail('La declaración firmada no corresponde. Vuelva a firmar.');
        }
        $certificate = $this->verifier->verify($expected, $signature->cms);
        if (config('signatures.require_holder_match') && ! $this->holders->matches($certificate->holder, $actor->nombre)) {
            $this->fail("El certificado es de «{$certificate->holder}», no de {$actor->nombre}.");
        }

        $record = SyllabusSignature::query()->create([
            'silabo_id' => $revision->silabo_id,
            'revision_silabo_id' => $revision->id,
            'firmado_como' => $role,
            'usuario_id' => $actor->id,
            'declaracion_firmada' => $expected,
            'firma_cms' => preg_replace('/\s+/', '', $signature->cms) ?? $signature->cms,
            'nombre_en_certificado' => $certificate->holder,
            'numero_certificado' => $certificate->serial,
            'entidad_emisora' => $certificate->issuer,
            'certificado_valido_hasta' => $certificate->validTo,
            'firmado_en' => now(),
        ]);
        $this->audit->execute(
            actorId: $actor->id,
            roleAssignmentId: $this->roles->resolve($request)?->id,
            action: 'silabo.firmado',
            resourceType: 'firma_silabo',
            resourceId: $record->id,
            result: 'exito',
            metadata: [
                'role' => $role,
                'revision_number' => $revision->numero_revision,
                'statement' => $expected,
                'certificate_holder' => $certificate->holder,
                'certificate_serial' => $certificate->serial,
            ],
        );

        return $record;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['signature' => $message]);
    }
}
