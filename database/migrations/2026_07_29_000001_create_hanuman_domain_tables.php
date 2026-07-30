<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function auditColumns(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->comment('Usuario que creó el registro')->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->comment('Usuario que actualizó el registro por última vez')->constrained('users')->nullOnDelete();
        $table->foreignId('deleted_by')->nullable()->comment('Usuario que eliminó el registro (soft delete)')->constrained('users')->nullOnDelete();
        $table->softDeletes()->comment('Fecha de eliminación lógica');
    }

    private function timestampColumns(Blueprint $table): void
    {
        $table->timestamp('created_at')->nullable()->comment('Fecha de creación');
        $table->timestamp('updated_at')->nullable()->comment('Fecha de última actualización');
    }

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id()->comment('Identificador de la sucursal');
            $table->string('name')->comment('Nombre de la sucursal');
            $table->string('address')->nullable()->comment('Dirección física de la sucursal');
            $table->boolean('is_active')->default(true)->comment('Indica si la sucursal está activa');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id()->comment('Identificador del alumno');
            $table->string('first_name')->comment('Nombre(s) del alumno');
            $table->string('last_name')->comment('Apellido(s) del alumno');
            $table->string('nickname')->nullable()->comment('Apodo o nombre de pila');
            $table->string('email')->nullable()->comment('Correo electrónico de contacto');
            $table->string('phone')->nullable()->comment('Teléfono de contacto');
            $table->date('birth_date')->nullable()->comment('Fecha de nacimiento');
            $table->date('enrolled_at')->nullable()->comment('Fecha de inscripción');
            $table->boolean('is_active')->default(true)->comment('Indica si el alumno está activo');
            $table->text('notes')->nullable()->comment('Notas adicionales del alumno');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });

        Schema::create('membership_payments', function (Blueprint $table) {
            $table->id()->comment('Identificador del pago de mensualidad');
            $table->foreignId('student_id')->comment('Alumno que realiza el pago')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->comment('Monto pagado');
            $table->date('payment_date')->comment('Fecha en que se registró el pago');
            $table->string('period_month', 7)->comment('Periodo cubierto en formato YYYY-MM');
            $table->string('payment_method')->nullable()->comment('Método de pago (efectivo, transferencia, etc.)');
            $table->text('notes')->nullable()->comment('Notas adicionales del pago');
            $this->timestampColumns($table);
            $this->auditColumns($table);
            $table->index(['student_id', 'period_month']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id()->comment('Identificador de la asistencia');
            $table->foreignId('student_id')->comment('Alumno que asistió')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->comment('Sucursal donde se registró la asistencia')->constrained()->cascadeOnDelete();
            $table->date('attendance_date')->comment('Fecha de la asistencia');
            $table->text('notes')->nullable()->comment('Notas adicionales de la asistencia');
            $this->timestampColumns($table);
            $this->auditColumns($table);
            $table->unique(['student_id', 'branch_id', 'attendance_date'], 'attendances_unique_day');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id()->comment('Identificador del producto');
            $table->string('name')->comment('Nombre del producto');
            $table->string('sku')->nullable()->comment('Código SKU del producto');
            $table->text('description')->nullable()->comment('Descripción del producto');
            $table->decimal('unit_price', 12, 2)->comment('Precio unitario de venta');
            $table->boolean('is_active')->default(true)->comment('Indica si el producto está activo');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });

        Schema::create('product_stocks', function (Blueprint $table) {
            $table->id()->comment('Identificador del stock por sucursal');
            $table->foreignId('product_id')->comment('Producto asociado')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->comment('Sucursal donde se almacena el stock')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(0)->comment('Cantidad disponible en stock');
            $this->timestampColumns($table);
            $this->auditColumns($table);
            $table->unique(['product_id', 'branch_id']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id()->comment('Identificador de la venta');
            $table->foreignId('branch_id')->comment('Sucursal donde se realizó la venta')->constrained()->cascadeOnDelete();
            $table->date('sale_date')->comment('Fecha de la venta');
            $table->decimal('total', 12, 2)->default(0)->comment('Total de la venta');
            $table->text('notes')->nullable()->comment('Notas adicionales de la venta');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id()->comment('Identificador del renglón de venta');
            $table->foreignId('sale_id')->comment('Venta a la que pertenece el renglón')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->comment('Producto vendido')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->comment('Cantidad vendida');
            $table->decimal('unit_price', 12, 2)->comment('Precio unitario al momento de la venta');
            $table->decimal('subtotal', 12, 2)->comment('Subtotal del renglón (cantidad × precio)');
            $this->timestampColumns($table);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id()->comment('Identificador del movimiento de inventario');
            $table->foreignId('product_id')->comment('Producto afectado')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->comment('Sucursal del movimiento')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->comment('Cantidad del movimiento (negativo = salida)');
            $table->string('type')->comment('Tipo de movimiento: sale, adjustment, restock');
            $table->string('reference_type')->nullable()->comment('Tipo de modelo de referencia (polimórfico)');
            $table->unsignedBigInteger('reference_id')->nullable()->comment('ID del modelo de referencia (polimórfico)');
            $table->text('notes')->nullable()->comment('Notas adicionales del movimiento');
            $this->timestampColumns($table);
            $this->auditColumns($table);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id()->comment('Identificador del gasto');
            $table->string('category')->comment('Categoría del gasto');
            $table->string('description')->nullable()->comment('Descripción breve del gasto');
            $table->decimal('amount', 12, 2)->comment('Monto del gasto');
            $table->date('expense_date')->comment('Fecha del gasto');
            $table->foreignId('branch_id')->nullable()->comment('Sucursal asociada al gasto (opcional)')->constrained()->nullOnDelete();
            $table->text('notes')->nullable()->comment('Notas adicionales del gasto');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });

        Schema::create('instructors', function (Blueprint $table) {
            $table->id()->comment('Identificador del instructor');
            $table->string('name')->comment('Nombre del instructor');
            $table->string('phone')->nullable()->comment('Teléfono de contacto');
            $table->string('email')->nullable()->comment('Correo electrónico de contacto');
            $table->boolean('is_active')->default(true)->comment('Indica si el instructor está activo');
            $table->text('notes')->nullable()->comment('Notas adicionales del instructor');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });

        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id()->comment('Identificador del horario de clase');
            $table->foreignId('instructor_id')->comment('Instructor asignado')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->comment('Sucursal donde se imparte la clase')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week')->comment('Día de la semana (0=domingo … 6=sábado)');
            $table->time('start_time')->comment('Hora de inicio de la clase');
            $table->time('end_time')->comment('Hora de fin de la clase');
            $table->boolean('is_active')->default(true)->comment('Indica si el horario está activo');
            $table->text('notes')->nullable()->comment('Notas adicionales del horario');
            $this->timestampColumns($table);
            $this->auditColumns($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
        Schema::dropIfExists('instructors');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('product_stocks');
        Schema::dropIfExists('products');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('membership_payments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('branches');
    }
};
