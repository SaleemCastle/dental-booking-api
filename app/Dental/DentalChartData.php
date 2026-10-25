<?php

namespace App\Dental;

use App\Models\Treatment;
use App\Tenancy\TenantRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;

class DentalChartData
{
    public static function rules(): array
    {
        return [
            'dentition' => ['required', Rule::in(DentalChartSchema::DENTITIONS)],
            'numbering_system' => ['sometimes', 'required', Rule::in(DentalChartSchema::NUMBERING_SYSTEMS)],
            'expected_version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'dentist_id' => ['required', 'integer', TenantRules::exists('dentist')],
            'clinical_note_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('clinical_notes')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:1000'],
            'teeth' => ['present', 'array', 'max:32'],
            'teeth.*' => ['required', 'array:tooth,status,findings'],
            'teeth.*.tooth' => ['required'],
            'teeth.*.status' => ['required', Rule::in(DentalChartSchema::TOOTH_STATUSES)],
            'teeth.*.findings' => ['present', 'array', 'max:20'],
            'teeth.*.findings.*' => ['required', 'array:code,status,surfaces,treatment_id,note'],
            'teeth.*.findings.*.code' => ['required', 'string', 'max:50', 'regex:/\A[a-z][a-z0-9_]*\z/'],
            'teeth.*.findings.*.status' => ['required', Rule::in(DentalChartSchema::FINDING_STATUSES)],
            'teeth.*.findings.*.surfaces' => ['present', 'array', 'max:5'],
            'teeth.*.findings.*.surfaces.*' => ['required', 'string', Rule::in([...DentalChartSchema::SURFACES, 'F', 'P'])],
            'teeth.*.findings.*.treatment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'teeth.*.findings.*.note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'id' => ['missing'], 'patient_id' => ['missing'], 'version' => ['missing'],
            'author_user_id' => ['missing'], 'recorded_at' => ['missing'], 'created_at' => ['missing'], 'updated_at' => ['missing'],
            'tenant_id' => ['missing'], 'tenantId' => ['missing'],
        ];
    }

    public static function check(ValidationValidator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }
        $data = $validator->getData();
        if (! array_is_list($data['teeth'])) {
            $validator->errors()->add('teeth', 'Teeth must be a JSON list.');

            return;
        }
        $seen = [];
        $references = [];
        foreach ($data['teeth'] as $i => $state) {
            $tooth = DentalChartSchema::toFdi($state['tooth'], $data['dentition'], $data['numbering_system'] ?? DentalChartSchema::DEFAULT_NUMBERING);
            if ($tooth === null || isset($seen[$tooth])) {
                $validator->errors()->add("teeth.$i.tooth", 'Select a valid, distinct tooth for this dentition and numbering system.');

                continue;
            }
            $seen[$tooth] = true;
            if (! array_is_list($state['findings'])) {
                $validator->errors()->add("teeth.$i.findings", 'Findings must be a JSON list.');
            }
            $findings = [];
            foreach ($state['findings'] as $j => $finding) {
                $path = "teeth.$i.findings.$j";
                $surfaces = array_map(DentalChartSchema::surface(...), $finding['surfaces']);
                if (! array_is_list($finding['surfaces']) || count(array_unique($surfaces)) !== count($surfaces)
                    || array_diff($surfaces, DentalChartSchema::surfacesFor($tooth)) !== []) {
                    $validator->errors()->add($path.'.surfaces', 'Select distinct surfaces valid for this tooth.');
                }
                sort($surfaces);
                $key = $finding['code'].'|'.implode(',', $surfaces);
                if (isset($findings[$key])) {
                    $validator->errors()->add($path.'.code', 'This condition and surface set is already present on this tooth.');
                }
                $findings[$key] = true;
                if (isset($finding['treatment_id'])) {
                    $references[$path.'.treatment_id'] = (int) $finding['treatment_id'];
                }
            }
        }
        $valid = Treatment::whereIn('id', array_values(array_unique($references)))->pluck('id')->all();
        foreach ($references as $path => $id) {
            if (! in_array($id, $valid, true)) {
                $validator->errors()->add($path, 'The selected treatment is invalid.');
            }
        }
    }

    public static function validate(array $data): array
    {
        $validator = Validator::make($data, self::rules());
        $validator->after(self::check(...));

        return $validator->validate();
    }

    public static function normalize(array $data): array
    {
        $teeth = [];
        foreach ($data['teeth'] as $state) {
            $findings = [];
            foreach ($state['findings'] as $finding) {
                $surfaces = array_map(DentalChartSchema::surface(...), $finding['surfaces']);
                sort($surfaces);
                $findings[] = [
                    'code' => $finding['code'], 'status' => $finding['status'], 'surfaces' => $surfaces,
                    'treatment_id' => isset($finding['treatment_id']) ? (int) $finding['treatment_id'] : null,
                    'note' => $finding['note'] ?? null,
                ];
            }
            usort($findings, fn ($a, $b) => [$a['code'], $a['surfaces']] <=> [$b['code'], $b['surfaces']]);
            $teeth[] = [
                'tooth' => DentalChartSchema::toFdi($state['tooth'], $data['dentition'], $data['numbering_system'] ?? DentalChartSchema::DEFAULT_NUMBERING),
                'status' => $state['status'], 'findings' => $findings,
            ];
        }
        usort($teeth, fn ($a, $b) => $a['tooth'] <=> $b['tooth']);

        return $teeth;
    }
}
