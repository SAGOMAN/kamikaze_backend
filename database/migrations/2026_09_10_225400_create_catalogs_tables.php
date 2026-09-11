<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table) {
            $table->id()->comment('Identificador del catálogo');
            $table->string('code')->comment('Código único del catálogo (ej. expense_categories)');
            $table->string('name')->comment('Nombre visible del catálogo');
            $table->text('description')->nullable()->comment('Descripción del uso del catálogo');
            $table->boolean('is_active')->default(true)->comment('Indica si el catálogo está activo');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->unique('code');
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id()->comment('Identificador del valor de catálogo');
            $table->foreignId('catalog_id')->comment('Catálogo al que pertenece')->constrained()->cascadeOnDelete();
            $table->string('name')->comment('Nombre visible del valor');
            $table->string('code')->nullable()->comment('Código interno opcional del valor');
            $table->unsignedInteger('sort_order')->default(0)->comment('Orden de aparición');
            $table->boolean('is_active')->default(true)->comment('Indica si el valor está activo');
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->index(['catalog_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalogs');
    }
};
