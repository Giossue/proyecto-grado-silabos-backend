<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RETIRED = ['si_no', 'repetible'];

    public function up(): void
    {
        $retiredInUse = "jsonb_path_exists(%s, '\$.** ? (@.kind == \"si_no\" || @.kind == \"repetible\")')";
        if (DB::table('definiciones_campo')->whereRaw(sprintf($retiredInUse, 'contenido_definicion_campo'))->exists()
            || DB::table('valores_campo')->whereRaw(sprintf($retiredInUse, 'valor_campo'))->exists()) {
            throw new RuntimeException('Un campo usa los bloques «si_no» o «repetible», que se retiran. Quítelos de la plantilla antes de desplegar.');
        }

        foreach (DB::table('plantilla_silabo')->get(['plantilla_id', 'definicion_plantilla']) as $template) {
            $definition = json_decode((string) $template->definicion_plantilla, true);
            if (! is_array($definition)) {
                continue;
            }
            foreach (self::RETIRED as $kind) {
                unset($definition['blocks'][$kind]);
            }
            DB::table('plantilla_silabo')->where('plantilla_id', $template->plantilla_id)
                ->update(['definicion_plantilla' => json_encode($this->clean($definition), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(): void {}

    private function clean(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }
        if (in_array($node['type'] ?? null, ['text', 'string'], true)) {
            unset($node['from']);
        }
        if (array_keys($node) === ['not'] && is_array($node['not'])) {
            foreach (['zero', 'empty'] as $test) {
                if (is_bool($node['not'][$test] ?? null)) {
                    return [...$node['not'], $test => ! $node['not'][$test]];
                }
            }
        }

        return array_map($this->clean(...), $node);
    }
};
