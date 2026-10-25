<?php

namespace App\Http\Requests;

use App\Dental\DentalChartData;
use App\Models\DentalChartRevision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDentalChartRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', DentalChartRevision::class);
    }

    public function rules(): array
    {
        return DentalChartData::rules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(DentalChartData::check(...));
    }
}
