<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    use BelongsToTenant;

    public function series()
    {
        return $this->belongsTo(DocumentSeries::class, 'series_id');
    }

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = ['series_id' => 'integer', 'document_id' => 'integer', 'version' => 'integer', 'predecessor_id' => 'integer'];
}
