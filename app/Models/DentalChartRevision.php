<?php

namespace App\Models;

use App\Models\Builders\DentalChartRevisionBuilder;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class DentalChartRevision extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id', 'tenant_id'];

    protected $casts = [
        'patient_id' => 'integer', 'dentist_id' => 'integer', 'author_user_id' => 'integer',
        'clinical_note_id' => 'integer', 'version' => 'integer', 'teeth' => 'array', 'recorded_at' => 'immutable_datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function dentist()
    {
        return $this->belongsTo(Dentist::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function clinicalNote()
    {
        return $this->belongsTo(ClinicalNote::class)->withTrashed();
    }

    public function newEloquentBuilder($query): DentalChartRevisionBuilder
    {
        return new DentalChartRevisionBuilder($query);
    }

    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new LogicException('Dental chart history is append-only.');
        }

        return parent::save($options);
    }

    public function delete()
    {
        throw new LogicException('Dental chart history is append-only.');
    }
}
