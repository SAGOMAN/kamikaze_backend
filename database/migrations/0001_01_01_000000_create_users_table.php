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
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('Identificador del usuario');
            $table->string('name')->comment('Nombre del usuario');
            $table->string('email')->unique()->comment('Correo electrónico (único)');
            $table->timestamp('email_verified_at')->nullable()->comment('Fecha de verificación del correo');
            $table->string('password')->comment('Contraseña hasheada');
            $table->rememberToken()->comment('Token de sesión persistente (remember me)');
            $table->timestamp('created_at')->nullable()->comment('Fecha de creación');
            $table->timestamp('updated_at')->nullable()->comment('Fecha de última actualización');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('Correo del usuario que solicita el reset');
            $table->string('token')->comment('Token de restablecimiento de contraseña');
            $table->timestamp('created_at')->nullable()->comment('Fecha de creación del token');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary()->comment('Identificador de la sesión');
            $table->foreignId('user_id')->nullable()->index()->comment('Usuario asociado a la sesión');
            $table->string('ip_address', 45)->nullable()->comment('Dirección IP del cliente');
            $table->text('user_agent')->nullable()->comment('User-Agent del navegador o cliente');
            $table->longText('payload')->comment('Datos serializados de la sesión');
            $table->integer('last_activity')->index()->comment('Timestamp Unix de la última actividad');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
