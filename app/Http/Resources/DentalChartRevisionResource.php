<?php

namespace App\Http\Resources;

use App\Dental\DentalChartSchema;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DentalChartRevisionResource extends JsonResource
{
    public function __construct($resource, private readonly string $numberingSystem = DentalChartSchema::DEFAULT_NUMBERING, private readonly bool $includeTeeth = true)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'patient_id' => $this->patient_id, 'dentition' => $this->dentition,
            'numbering_system' => $this->numberingSystem, 'version' => $this->version,
            'dentist_id' => $this->dentist_id, 'author_user_id' => $this->author_user_id,
            'clinical_note_id' => $this->clinical_note_id, 'recorded_at' => $this->recorded_at->utc()->toISOString(),
            'reason' => $this->reason,
            'teeth' => $this->when($this->includeTeeth, fn () => DentalChartSchema::render($this->teeth, $this->dentition, $this->numberingSystem)),
        ];
    }
}
