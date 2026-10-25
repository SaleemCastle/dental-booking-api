<?php

namespace Tests\Unit;

use App\Dental\DentalChartSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DentalChartNumberingTest extends TestCase
{
    #[DataProvider('boundaryTeeth')]
    public function test_universal_and_fdi_landmarks_match(string $dentition, string $fdi, string $universal): void
    {
        $this->assertSame($fdi, DentalChartSchema::toFdi($universal, $dentition, 'universal'));
        $this->assertSame($universal, DentalChartSchema::fromFdi($fdi, $dentition, 'universal'));
    }

    public static function boundaryTeeth(): array
    {
        return [
            ['adult', '18', '1'], ['adult', '11', '8'], ['adult', '21', '9'], ['adult', '28', '16'],
            ['adult', '38', '17'], ['adult', '31', '24'], ['adult', '41', '25'], ['adult', '48', '32'],
            ['primary', '55', 'A'], ['primary', '51', 'E'], ['primary', '61', 'F'], ['primary', '65', 'J'],
            ['primary', '75', 'K'], ['primary', '71', 'O'], ['primary', '81', 'P'], ['primary', '85', 'T'],
        ];
    }

    public function test_all_52_positions_are_distinct_round_trip_and_have_five_valid_surfaces(): void
    {
        foreach (['adult' => 32, 'primary' => 20] as $dentition => $count) {
            $map = DentalChartSchema::universalMap($dentition);
            $this->assertCount($count, $map);
            $this->assertCount($count, array_unique(array_values($map)));
            foreach (DentalChartSchema::teeth($dentition) as $fdi) {
                $universal = DentalChartSchema::fromFdi($fdi, $dentition, 'universal');
                $this->assertSame($fdi, DentalChartSchema::toFdi($universal, $dentition, 'universal'));
                $this->assertCount(5, DentalChartSchema::surfacesFor($fdi));
            }
        }
    }
}
