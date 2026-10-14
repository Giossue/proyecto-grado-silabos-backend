<?php

it('lee el calendario de la convocatoria institucional sin copiarlo por carrera', function (): void {
    $root = dirname(__DIR__, 2);
    $schedule = (string) file_get_contents($root.'/app/Modules/Syllabus/Application/ConvocationSchedule.php');
    $create = (string) file_get_contents($root.'/app/Modules/Syllabus/Application/Actions/CreateConvocation.php');

    expect($schedule)
        ->toContain('process->inicia_en')
        ->toContain('process->entrega_en');
    expect($create)->not->toContain('fechas_limite_convocatoria');
});

it('bloquea el envio por plazo en la accion y no en el controlador', function (): void {
    $root = dirname(__DIR__, 2);
    $submit = (string) file_get_contents($root.'/app/Modules/Syllabus/Application/Actions/SubmitSyllabus.php');
    $controller = (string) file_get_contents(
        $root.'/app/Modules/Syllabus/Presentation/Http/Controllers/SyllabusController.php',
    );

    expect($submit)->toContain('assertOpenForSubmission');
    expect($controller)->not->toContain('assertOpenForSubmission');
});

it('conserva la fecha anterior al prorrogar', function (): void {
    $extend = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Modules/Syllabus/Application/Actions/ExtendProcessDeadline.php',
    );

    expect($extend)
        ->toContain("'previous_due_at'")
        ->toContain('proceso_silabos.plazo_extendido')
        ->toContain('lessThanOrEqualTo');
});

it('marca la aprobacion hecha por quien redacto', function (): void {
    $approve = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Modules/Library/Application/Actions/VerifyBibliography.php',
    );

    expect($approve)->toContain("'self_approved'");
});
