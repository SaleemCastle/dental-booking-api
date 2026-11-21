<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class InsurerReceipt extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['command_key', 'request_hash'];

    protected $casts = ['received_on' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'amount' => 'decimal:2'];

    public function verification()
    {
        return $this->hasOne(InsurerReceiptVerification::class, 'receipt_id');
    }
}
