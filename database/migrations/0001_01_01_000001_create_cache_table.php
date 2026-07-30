<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Clave del ítem en caché');
            $table->mediumText('value')->comment('Valor serializado del ítem en caché');
            $table->bigInteger('expiration')->index()->comment('Timestamp Unix de expiración');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('Clave del bloqueo de caché');
            $table->string('owner')->comment('Identificador del propietario del bloqueo');
            $table->bigInteger('expiration')->index()->comment('Timestamp Unix de expiración del bloqueo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
