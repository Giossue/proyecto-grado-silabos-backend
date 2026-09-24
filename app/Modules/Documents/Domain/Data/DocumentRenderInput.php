<?php

namespace App\Modules\Documents\Domain\Data;

final readonly class DocumentRenderInput
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @param  list<SignatureParty>  $signatures  Docente que entrega y coordinación que aprueba.
     */
    public function __construct(
        public string $subject,
        public string $subjectCode,
        public string $academicPeriod,
        public int $revisionNumber,
        public string $revisionFingerprint,
        public string $generatedAt,
        public string $locale,
        public array $snapshot,
        public array $signatures = [],
    ) {}
}
