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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id()->comment('Identificador del job en cola');
            $table->string('queue')->index()->comment('Nombre de la cola');
            $table->longText('payload')->comment('Payload serializado del job');
            $table->unsignedSmallInteger('attempts')->comment('Número de intentos realizados');
            $table->unsignedInteger('reserved_at')->nullable()->comment('Timestamp Unix en que el job fue reservado');
            $table->unsignedInteger('available_at')->comment('Timestamp Unix en que el job estará disponible');
            $table->unsignedInteger('created_at')->comment('Timestamp Unix de creación del job');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary()->comment('Identificador del lote de jobs');
            $table->string('name')->comment('Nombre del lote');
            $table->integer('total_jobs')->comment('Total de jobs en el lote');
            $table->integer('pending_jobs')->comment('Jobs pendientes en el lote');
            $table->integer('failed_jobs')->comment('Jobs fallidos en el lote');
            $table->longText('failed_job_ids')->comment('IDs de jobs fallidos (serializados)');
            $table->mediumText('options')->nullable()->comment('Opciones del lote (serializadas)');
            $table->integer('cancelled_at')->nullable()->comment('Timestamp Unix de cancelación');
            $table->integer('created_at')->comment('Timestamp Unix de creación del lote');
            $table->integer('finished_at')->nullable()->comment('Timestamp Unix de finalización del lote');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id()->comment('Identificador del job fallido');
            $table->string('uuid')->unique()->comment('UUID único del job fallido');
            $table->string('connection')->comment('Conexión de cola utilizada');
            $table->string('queue')->comment('Nombre de la cola');
            $table->longText('payload')->comment('Payload serializado del job');
            $table->longText('exception')->comment('Excepción capturada al fallar');
            $table->timestamp('failed_at')->useCurrent()->comment('Fecha y hora del fallo');

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
