<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['plantilla_silabo', 'bloques_plantilla', 'definiciones_campo'];

    public function up(): void
    {
        $this->replace('#DBE5F1', '#C3D4EA');
    }

    public function down(): void
    {
        $this->replace('#C3D4EA', '#DBE5F1');
    }

    private function replace(string $from, string $to): void
    {
        foreach (self::TABLES as $table) {
            $columns = DB::table('information_schema.columns')
                ->where('table_name', $table)
                ->where('data_type', 'jsonb')
                ->pluck('column_name');
            foreach ($columns as $column) {
                DB::statement(sprintf(
                    'UPDATE "%1$s" SET "%2$s" = replace("%2$s"::text, \'"%3$s"\', \'"%4$s"\')::jsonb WHERE "%2$s"::text LIKE \'%%"%3$s"%%\'',
                    $table,
                    $column,
                    $from,
                    $to,
                ));
            }
        }
    }
};
