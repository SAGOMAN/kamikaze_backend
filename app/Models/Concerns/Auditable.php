<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::creating(function (Model $model): void {
            $userId = Auth::id();
            if ($userId === null) {
                return;
            }

            if (! $model->getAttribute('created_by')) {
                $model->setAttribute('created_by', $userId);
            }

            if (! $model->getAttribute('updated_by')) {
                $model->setAttribute('updated_by', $userId);
            }
        });

        static::updating(function (Model $model): void {
            $userId = Auth::id();
            if ($userId !== null) {
                $model->setAttribute('updated_by', $userId);
            }
        });

        static::deleting(function (Model $model): void {
            if (! in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                return;
            }

            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            $userId = Auth::id();
            if ($userId !== null) {
                $model->setAttribute('deleted_by', $userId);
                $model->saveQuietly();
            }
        });
    }
}
