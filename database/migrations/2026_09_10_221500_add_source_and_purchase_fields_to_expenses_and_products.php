<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('last_cost', 12, 2)
                ->nullable()
                ->after('unit_price')
                ->comment('Último costo de compra unitario');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('source')
                ->default('operational')
                ->after('notes')
                ->comment('Origen del gasto: operational o merchandise');
            $table->foreignId('product_id')
                ->nullable()
                ->after('source')
                ->comment('Producto comprado (solo mercancía)')
                ->constrained()
                ->nullOnDelete();
            $table->unsignedInteger('quantity')
                ->nullable()
                ->after('product_id')
                ->comment('Unidades compradas (solo mercancía)');
            $table->decimal('unit_cost', 12, 2)
                ->nullable()
                ->after('quantity')
                ->comment('Costo unitario de la compra (solo mercancía)');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'quantity', 'unit_cost']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('last_cost');
        });
    }
};
