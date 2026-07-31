<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('color', 7)
                ->default('#64748B')
                ->after('is_active')
                ->comment('Color HEX (#RRGGBB) para UI');
        });

        Schema::table('instructors', function (Blueprint $table) {
            $table->string('color', 7)
                ->default('#64748B')
                ->after('is_active')
                ->comment('Color HEX (#RRGGBB) para UI');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('color');
        });

        Schema::table('instructors', function (Blueprint $table) {
            $table->dropColumn('color');
        });
    }
};
