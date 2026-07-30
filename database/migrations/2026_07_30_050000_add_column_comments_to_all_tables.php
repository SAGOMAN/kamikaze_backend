<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Añade COMMENT a columnas existentes sin recrear tablas ni perder datos.
 * Fuente de verdad de textos: alineada con las migraciones de creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($this->commentsByTable() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column => $comment) {
                $this->applyColumnComment($table, $column, $comment);
            }
        }
    }

    public function down(): void
    {
        // Los comentarios son metadatos documentales; no se revierten.
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function commentsByTable(): array
    {
        $audit = [
            'created_by' => 'Usuario que creó el registro',
            'updated_by' => 'Usuario que actualizó el registro por última vez',
            'deleted_by' => 'Usuario que eliminó el registro (soft delete)',
            'deleted_at' => 'Fecha de eliminación lógica',
            'created_at' => 'Fecha de creación',
            'updated_at' => 'Fecha de última actualización',
        ];

        return [
            'migrations' => [
                'id' => 'Identificador interno del registro de migración',
                'migration' => 'Nombre del archivo de migración ejecutado',
                'batch' => 'Número de lote en que se ejecutó la migración',
            ],
            'users' => [
                'id' => 'Identificador del usuario',
                'name' => 'Nombre del usuario',
                'email' => 'Correo electrónico (único)',
                'email_verified_at' => 'Fecha de verificación del correo',
                'password' => 'Contraseña hasheada',
                'remember_token' => 'Token de sesión persistente (remember me)',
                'created_at' => 'Fecha de creación',
                'updated_at' => 'Fecha de última actualización',
            ],
            'password_reset_tokens' => [
                'email' => 'Correo del usuario que solicita el reset',
                'token' => 'Token de restablecimiento de contraseña',
                'created_at' => 'Fecha de creación del token',
            ],
            'sessions' => [
                'id' => 'Identificador de la sesión',
                'user_id' => 'Usuario asociado a la sesión',
                'ip_address' => 'Dirección IP del cliente',
                'user_agent' => 'User-Agent del navegador o cliente',
                'payload' => 'Datos serializados de la sesión',
                'last_activity' => 'Timestamp Unix de la última actividad',
            ],
            'cache' => [
                'key' => 'Clave del ítem en caché',
                'value' => 'Valor serializado del ítem en caché',
                'expiration' => 'Timestamp Unix de expiración',
            ],
            'cache_locks' => [
                'key' => 'Clave del bloqueo de caché',
                'owner' => 'Identificador del propietario del bloqueo',
                'expiration' => 'Timestamp Unix de expiración del bloqueo',
            ],
            'jobs' => [
                'id' => 'Identificador del job en cola',
                'queue' => 'Nombre de la cola',
                'payload' => 'Payload serializado del job',
                'attempts' => 'Número de intentos realizados',
                'reserved_at' => 'Timestamp Unix en que el job fue reservado',
                'available_at' => 'Timestamp Unix en que el job estará disponible',
                'created_at' => 'Timestamp Unix de creación del job',
            ],
            'job_batches' => [
                'id' => 'Identificador del lote de jobs',
                'name' => 'Nombre del lote',
                'total_jobs' => 'Total de jobs en el lote',
                'pending_jobs' => 'Jobs pendientes en el lote',
                'failed_jobs' => 'Jobs fallidos en el lote',
                'failed_job_ids' => 'IDs de jobs fallidos (serializados)',
                'options' => 'Opciones del lote (serializadas)',
                'cancelled_at' => 'Timestamp Unix de cancelación',
                'created_at' => 'Timestamp Unix de creación del lote',
                'finished_at' => 'Timestamp Unix de finalización del lote',
            ],
            'failed_jobs' => [
                'id' => 'Identificador del job fallido',
                'uuid' => 'UUID único del job fallido',
                'connection' => 'Conexión de cola utilizada',
                'queue' => 'Nombre de la cola',
                'payload' => 'Payload serializado del job',
                'exception' => 'Excepción capturada al fallar',
                'failed_at' => 'Fecha y hora del fallo',
            ],
            'personal_access_tokens' => [
                'id' => 'Identificador del token de acceso personal',
                'tokenable_type' => 'Tipo de modelo propietario del token (polimórfico)',
                'tokenable_id' => 'ID del modelo propietario del token (polimórfico)',
                'name' => 'Nombre descriptivo del token',
                'token' => 'Hash del token de acceso',
                'abilities' => 'Permisos/habilidades del token (JSON)',
                'last_used_at' => 'Última vez que se usó el token',
                'expires_at' => 'Fecha de expiración del token',
                'created_at' => 'Fecha de creación',
                'updated_at' => 'Fecha de última actualización',
            ],
            'branches' => [
                'id' => 'Identificador de la sucursal',
                'name' => 'Nombre de la sucursal',
                'address' => 'Dirección física de la sucursal',
                'is_active' => 'Indica si la sucursal está activa',
            ] + $audit,
            'students' => [
                'id' => 'Identificador del alumno',
                'first_name' => 'Nombre(s) del alumno',
                'last_name' => 'Apellido(s) del alumno',
                'nickname' => 'Apodo o nombre de pila',
                'email' => 'Correo electrónico de contacto',
                'phone' => 'Teléfono de contacto',
                'birth_date' => 'Fecha de nacimiento',
                'enrolled_at' => 'Fecha de inscripción',
                'is_active' => 'Indica si el alumno está activo',
                'notes' => 'Notas adicionales del alumno',
            ] + $audit,
            'membership_payments' => [
                'id' => 'Identificador del pago de mensualidad',
                'student_id' => 'Alumno que realiza el pago',
                'amount' => 'Monto pagado',
                'payment_date' => 'Fecha en que se registró el pago',
                'period_month' => 'Periodo cubierto en formato YYYY-MM',
                'payment_method' => 'Método de pago (efectivo, transferencia, etc.)',
                'notes' => 'Notas adicionales del pago',
            ] + $audit,
            'attendances' => [
                'id' => 'Identificador de la asistencia',
                'student_id' => 'Alumno que asistió',
                'branch_id' => 'Sucursal donde se registró la asistencia',
                'attendance_date' => 'Fecha de la asistencia',
                'notes' => 'Notas adicionales de la asistencia',
            ] + $audit,
            'products' => [
                'id' => 'Identificador del producto',
                'name' => 'Nombre del producto',
                'sku' => 'Código SKU del producto',
                'description' => 'Descripción del producto',
                'unit_price' => 'Precio unitario de venta',
                'is_active' => 'Indica si el producto está activo',
            ] + $audit,
            'product_stocks' => [
                'id' => 'Identificador del stock por sucursal',
                'product_id' => 'Producto asociado',
                'branch_id' => 'Sucursal donde se almacena el stock',
                'quantity' => 'Cantidad disponible en stock',
            ] + $audit,
            'sales' => [
                'id' => 'Identificador de la venta',
                'branch_id' => 'Sucursal donde se realizó la venta',
                'sale_date' => 'Fecha de la venta',
                'total' => 'Total de la venta',
                'notes' => 'Notas adicionales de la venta',
            ] + $audit,
            'sale_items' => [
                'id' => 'Identificador del renglón de venta',
                'sale_id' => 'Venta a la que pertenece el renglón',
                'product_id' => 'Producto vendido',
                'quantity' => 'Cantidad vendida',
                'unit_price' => 'Precio unitario al momento de la venta',
                'subtotal' => 'Subtotal del renglón (cantidad × precio)',
                'created_at' => 'Fecha de creación',
                'updated_at' => 'Fecha de última actualización',
            ],
            'stock_movements' => [
                'id' => 'Identificador del movimiento de inventario',
                'product_id' => 'Producto afectado',
                'branch_id' => 'Sucursal del movimiento',
                'quantity' => 'Cantidad del movimiento (negativo = salida)',
                'type' => 'Tipo de movimiento: sale, adjustment, restock',
                'reference_type' => 'Tipo de modelo de referencia (polimórfico)',
                'reference_id' => 'ID del modelo de referencia (polimórfico)',
                'notes' => 'Notas adicionales del movimiento',
            ] + $audit,
            'expenses' => [
                'id' => 'Identificador del gasto',
                'category' => 'Categoría del gasto',
                'description' => 'Descripción breve del gasto',
                'amount' => 'Monto del gasto',
                'expense_date' => 'Fecha del gasto',
                'branch_id' => 'Sucursal asociada al gasto (opcional)',
                'notes' => 'Notas adicionales del gasto',
            ] + $audit,
            'instructors' => [
                'id' => 'Identificador del instructor',
                'name' => 'Nombre del instructor',
                'phone' => 'Teléfono de contacto',
                'email' => 'Correo electrónico de contacto',
                'is_active' => 'Indica si el instructor está activo',
                'notes' => 'Notas adicionales del instructor',
            ] + $audit,
            'class_schedules' => [
                'id' => 'Identificador del horario de clase',
                'instructor_id' => 'Instructor asignado',
                'branch_id' => 'Sucursal donde se imparte la clase',
                'day_of_week' => 'Día de la semana (0=domingo … 6=sábado)',
                'start_time' => 'Hora de inicio de la clase',
                'end_time' => 'Hora de fin de la clase',
                'is_active' => 'Indica si el horario está activo',
                'notes' => 'Notas adicionales del horario',
            ] + $audit,
        ];
    }

    private function applyColumnComment(string $table, string $column, string $comment): void
    {
        if (! Schema::hasColumn($table, $column)) {
            return;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $meta = DB::selectOne(
            'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$database, $table, $column]
        );

        if ($meta === null) {
            return;
        }

        $nullable = $meta->IS_NULLABLE === 'YES' ? 'NULL' : 'NOT NULL';
        $defaultSql = $this->defaultSql($meta->COLUMN_DEFAULT, $meta->COLUMN_TYPE, $meta->IS_NULLABLE === 'YES', (string) $meta->EXTRA);
        $extraSql = $this->extraSql((string) $meta->EXTRA);
        $escapedComment = str_replace("'", "''", $comment);

        $sql = sprintf(
            'ALTER TABLE `%s` MODIFY COLUMN `%s` %s %s%s%s COMMENT \'%s\'',
            $table,
            $column,
            $meta->COLUMN_TYPE,
            $nullable,
            $defaultSql,
            $extraSql !== '' ? ' '.$extraSql : '',
            $escapedComment
        );

        DB::statement($sql);
    }

    private function defaultSql(mixed $default, string $columnType, bool $nullable, string $extra): string
    {
        if ($default === null) {
            return $nullable ? ' DEFAULT NULL' : '';
        }

        $default = (string) $default;
        $extraUpper = strtoupper($extra);

        // MySQL 8: COLUMN_DEFAULT puede ser CURRENT_TIMESTAMP y EXTRA incluye DEFAULT_GENERATED
        if (
            str_contains($extraUpper, 'DEFAULT_GENERATED')
            || in_array(strtoupper($default), ['CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP()', 'NOW()'], true)
        ) {
            $expression = preg_match('/CURRENT_TIMESTAMP(?:\(\d*\))?/i', $default)
                ? $default
                : 'CURRENT_TIMESTAMP';

            return ' DEFAULT '.$expression;
        }

        if (str_starts_with($columnType, 'bit') || str_starts_with($columnType, 'tinyint(1)')) {
            return ' DEFAULT '.$default;
        }

        if (is_numeric($default) && ! preg_match('/^(char|varchar|text|enum|set|date|time|year)/i', $columnType)) {
            return ' DEFAULT '.$default;
        }

        return " DEFAULT '".str_replace("'", "''", $default)."'";
    }

    private function extraSql(string $extra): string
    {
        $parts = preg_split('/\s+/', trim($extra)) ?: [];
        $allowed = [];

        foreach ($parts as $part) {
            $upper = strtoupper($part);

            if ($upper === 'AUTO_INCREMENT') {
                $allowed[] = 'AUTO_INCREMENT';
                continue;
            }

            // ON UPDATE CURRENT_TIMESTAMP[(n)]
            if ($upper === 'ON') {
                $allowed[] = $part;
                continue;
            }

            if ($upper === 'UPDATE' || str_starts_with($upper, 'CURRENT_TIMESTAMP')) {
                $allowed[] = $part;
            }
        }

        return implode(' ', $allowed);
    }
};
