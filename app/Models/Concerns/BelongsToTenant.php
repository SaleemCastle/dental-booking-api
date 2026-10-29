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
            $attributionColumns = match ($model->getTable()) {
                'clinical_notes' => ['author_user_id', 'updated_by_user_id', 'deleted_by_user_id'],
                'dental_chart_revisions' => ['author_user_id'],
                'patient' => ['archived_by_user_id'],
                'patient_documents' => ['uploaded_by_user_id', 'archived_by_user_id', 'deleted_by_user_id'],
                default => [],
            };
            foreach ($attributionColumns as $column) {
                $value = $model->getAttribute($column);
                // Preserve historical attribution after a member leaves the clinic.
                if ($value !== null && (! $model->exists || $model->isDirty($column))
                    && ! DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $value)->exists()) {
                    throw ValidationException::withMessages([$column => ['The attributed user must be a member of the current clinic.']]);
                }
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
            'patient_contacts' => ['patient_id' => 'patient'],
            'clinic_notifications' => ['invoice_id' => 'invoices', 'payment_id' => 'payments', 'payment_link_id' => 'payment_links', 'configuration_id' => 'communication_configurations'],
            'notification_deliveries' => ['notification_id' => 'clinic_notifications', 'patient_contact_id' => 'patient_contacts'],
            'provider_availabilities' => ['dentist_id' => 'dentist'],
            'appointment' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'treatment_id' => 'treatments', 'room_id' => 'rooms', 'chair_id' => 'chairs'],
            'chairs' => ['room_id' => 'rooms'],
            'invoices' => ['patient_id' => 'patient', 'appointment_id' => 'appointment'],
            'payments' => ['invoice_id' => 'invoices', 'payment_link_id' => 'payment_links', 'payment_provider_configuration_id' => 'payment_provider_configurations'],
            'payment_provider_events' => ['payment_link_id' => 'payment_links', 'payment_provider_configuration_id' => 'payment_provider_configurations'],
            'payment_links' => ['invoice_id' => 'invoices', 'payment_provider_configuration_id' => 'payment_provider_configurations'],
            'invoice_items' => ['invoice_id' => 'invoices', 'treatment_id' => 'treatments', 'treatment_plan_item_id' => 'treatment_plan_items'],
            'messages' => ['sender_id' => 'patient', 'receiver_id' => 'patient'],
            'referral' => ['patient_id' => 'patient', 'dentist_id' => 'dentist'],
            'clinical_notes' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'appointment_id' => 'appointment'],
            'patient_documents' => ['patient_id' => 'patient', 'appointment_id' => 'appointment', 'clinical_note_id' => 'clinical_notes'],
            'dental_chart_revisions' => ['patient_id' => 'patient', 'dentist_id' => 'dentist', 'clinical_note_id' => 'clinical_notes'],
            'tasks' => ['patient_id' => 'patient', 'appointment_id' => 'appointment'],
            'treatment_plans' => ['patient_id' => 'patient'],
            'treatment_plan_items' => ['treatment_plan_id' => 'treatment_plans', 'treatment_id' => 'treatments', 'dentist_id' => 'dentist'],
        ];
    }
}
