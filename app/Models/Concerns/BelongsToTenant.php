<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', fn (Builder $query) => $query->where($query->qualifyColumn('tenant_id'), app(TenantContext::class)->id()));
        static::saving(function ($model): void {
            $tenantId = app(TenantContext::class)->id();
            if (($model->tenant_id !== null && (int) $model->tenant_id !== $tenantId)
                || ($model->exists && $model->isDirty('tenant_id'))) {
                throw ValidationException::withMessages(['tenant_id' => ['Clinic ownership cannot be changed.']]);
            }
            $model->tenant_id = $tenantId;
            foreach (self::tenantRelationships()[$model->getTable()] ?? [] as $column => $table) {
                $value = $model->getAttribute($column);
                if ($value !== null && ! DB::table($table)->where('id', $value)->where('tenant_id', $tenantId)->exists()) {
                    throw ValidationException::withMessages([$column => ['The related record must belong to the current clinic.']]);
                }
            }
            if ($model->getTable() === 'tasks' && ! DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $model->assignee_id)->exists()) {
                throw ValidationException::withMessages(['assignee_id' => ['The assignee must be a member of the current clinic.']]);
            }
        });
        static::deleting(function ($model): void {
            if ((int) $model->tenant_id !== app(TenantContext::class)->id()) {
                throw ValidationException::withMessages(['tenant_id' => ['The record belongs to another clinic.']]);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    private static function tenantRelationships(): array
    {
        return [
            'appointment' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'treatment_id' => 'treatments', 'room_id' => 'rooms', 'chair_id' => 'chairs'],
            'chairs' => ['room_id' => 'rooms'],
            'invoices' => ['patient_id' => 'patient', 'appointment_id' => 'appointment'],
            'payments' => ['invoice_id' => 'invoices'],
            'messages' => ['sender_id' => 'patient', 'receiver_id' => 'patient'],
            'referral' => ['patient_id' => 'patient', 'dentist_id' => 'dentist'],
            'clinical_notes' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'appointment_id' => 'appointment'],
            'tasks' => ['patient_id' => 'patient', 'appointment_id' => 'appointment'],
        ];
    }
}
