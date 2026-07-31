<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // MySQL usa attendances_unique_day como índice de la FK student_id;
            // hay que crear un índice propio antes de soltar el unique.
            $table->index('student_id', 'attendances_student_id_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_unique_day');

            $table->foreignId('class_schedule_id')
                ->nullable()
                ->after('branch_id')
                ->comment('Horario de clase al que corresponde la asistencia')
                ->constrained('class_schedules')
                ->nullOnDelete();

            $table->unique(
                ['student_id', 'class_schedule_id', 'attendance_date'],
                'attendances_unique_schedule_day'
            );
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('attendances_unique_schedule_day');
            $table->dropConstrainedForeignId('class_schedule_id');
            $table->unique(
                ['student_id', 'branch_id', 'attendance_date'],
                'attendances_unique_day'
            );
            $table->dropIndex('attendances_student_id_index');
        });
    }
};
