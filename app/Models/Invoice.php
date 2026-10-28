<?php

namespace App\Models;

use App\Billing\Money;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToTenant, HasFactory;

    public const STATUSES = ['draft', 'issued', 'partially_paid', 'paid', 'void'];

    protected $attributes = ['status' => 'draft', 'amount_due' => '0.00', 'amount_paid' => '0.00'];

    protected $hidden = ['legacy_status'];

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'invoice_number',
        'amount_due',
        'amount_paid',
        'status',
        'issued_at',
        'due_date',
        'currency',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'issued_at' => 'datetime',
        'due_date' => 'date',
        'voided_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function paymentLinks()
    {
        return $this->hasMany(PaymentLink::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function getBalanceAttribute(): string
    {
        return $this->status === 'void' ? '0.00' : Money::decimal(Money::minor($this->amount_due) - Money::minor($this->amount_paid));
    }
}
