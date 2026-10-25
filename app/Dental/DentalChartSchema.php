<?php

namespace App\Dental;

class DentalChartSchema
{
    public const DEFAULT_NUMBERING = 'universal';

    public const DENTITIONS = ['adult', 'primary'];

    public const NUMBERING_SYSTEMS = ['universal', 'fdi'];

    public const TOOTH_STATUSES = ['present', 'missing', 'extracted', 'unerupted'];

    public const FINDING_STATUSES = ['active', 'resolved'];

    public const SURFACES = ['M', 'D', 'B', 'L', 'O', 'I'];

    public static function teeth(string $dentition): array
    {
        $quadrants = $dentition === 'adult' ? [1, 2, 3, 4] : [5, 6, 7, 8];
        $positions = $dentition === 'adult' ? 8 : 5;
        $teeth = [];
        foreach ($quadrants as $quadrant) {
            foreach (range(1, $positions) as $position) {
                $teeth[] = (string) ($quadrant * 10 + $position);
            }
        }

        return $teeth;
    }

    public static function universalMap(string $dentition): array
    {
        $fdi = $dentition === 'adult'
            ? [...range(18, 11), ...range(21, 28), ...range(38, 31), ...range(41, 48)]
            : [...range(55, 51), ...range(61, 65), ...range(75, 71), ...range(81, 85)];
        $labels = $dentition === 'adult' ? array_map('strval', range(1, 32)) : range('A', 'T');

        return array_combine($labels, array_map('strval', $fdi));
    }

    public static function toFdi(mixed $tooth, string $dentition, string $system): ?string
    {
        if (! is_string($tooth) && ! is_int($tooth)) {
            return null;
        }
        $tooth = strtoupper((string) $tooth);
        if ($system === 'universal') {
            return self::universalMap($dentition)[$tooth] ?? null;
        }

        return in_array($tooth, self::teeth($dentition), true) ? $tooth : null;
    }

    public static function fromFdi(string $tooth, string $dentition, string $system): string
    {
        return $system === 'fdi' ? $tooth : (string) array_search($tooth, self::universalMap($dentition), true);
    }

    public static function surface(string $surface): string
    {
        return match ($surface) {
            'F' => 'B', 'P' => 'L', default => $surface,
        };
    }

    public static function surfacesFor(string $fdi): array
    {
        return ['M', 'D', 'B', 'L', (int) $fdi[1] <= 3 ? 'I' : 'O'];
    }

    public static function render(array $teeth, string $dentition, string $system): array
    {
        return array_map(function (array $state) use ($dentition, $system): array {
            $state['tooth'] = self::fromFdi($state['tooth'], $dentition, $system);

            return $state;
        }, $teeth);
    }

    public static function metadata(string $system): array
    {
        $dentitions = [];
        foreach (self::DENTITIONS as $dentition) {
            $dentitions[$dentition] = array_map(fn ($fdi) => [
                'tooth' => self::fromFdi($fdi, $dentition, $system),
                'fdi' => $fdi, 'surfaces' => self::surfacesFor($fdi),
            ], self::teeth($dentition));
        }

        return [
            'numbering_system' => $system,
            'numbering_systems' => self::NUMBERING_SYSTEMS,
            'dentitions' => $dentitions,
            'tooth_statuses' => self::TOOTH_STATUSES,
            'finding_statuses' => self::FINDING_STATUSES,
            'surfaces' => ['M' => 'Mesial', 'D' => 'Distal', 'B' => 'Buccal/facial', 'L' => 'Lingual/palatal', 'O' => 'Occlusal', 'I' => 'Incisal'],
            'surface_aliases' => ['F' => 'B', 'P' => 'L'],
            'suggested_conditions' => ['caries', 'restored', 'fracture', 'sealant', 'crown', 'implant'],
            'custom_condition_codes' => true,
        ];
    }
}
