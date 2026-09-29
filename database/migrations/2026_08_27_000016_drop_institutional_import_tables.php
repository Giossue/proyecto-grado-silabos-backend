<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS proteger_conflicto_importacion() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS validar_conflicto_importacion() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS validar_item_importacion() CASCADE');
        DB::unprepared('DROP FUNCTION IF EXISTS validar_ejecucion_importacion() CASCADE');

        Schema::dropIfExists('conflictos_importacion');
        Schema::dropIfExists('items_importacion');
        Schema::dropIfExists('ejecuciones_importacion');

        Schema::dropIfExists('alias_institucionales');
    }

    public function down(): void {}
};
