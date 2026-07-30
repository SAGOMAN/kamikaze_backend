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
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id()->comment('Identificador del token de acceso personal');
            $table->string('tokenable_type')->comment('Tipo de modelo propietario del token (polimórfico)');
            $table->unsignedBigInteger('tokenable_id')->comment('ID del modelo propietario del token (polimórfico)');
            $table->text('name')->comment('Nombre descriptivo del token');
            $table->string('token', 64)->unique()->comment('Hash del token de acceso');
            $table->text('abilities')->nullable()->comment('Permisos/habilidades del token (JSON)');
            $table->timestamp('last_used_at')->nullable()->comment('Última vez que se usó el token');
            $table->timestamp('expires_at')->nullable()->index()->comment('Fecha de expiración del token');
            $table->timestamp('created_at')->nullable()->comment('Fecha de creación');
            $table->timestamp('updated_at')->nullable()->comment('Fecha de última actualización');

            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
