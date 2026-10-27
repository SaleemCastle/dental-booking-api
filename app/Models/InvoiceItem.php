<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = ['unit_price' => 'decimal:2', 'line_total' => 'decimal:2', 'quantity' => 'integer'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function treatmentPlanItem()
    {
        return $this->belongsTo(TreatmentPlanItem::class);
    }
}
