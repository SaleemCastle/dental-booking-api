<?php

namespace Database\Seeders;

use App\Models\Chair;
use App\Models\Room;
use Illuminate\Database\Seeder;

class AppointmentLocationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Room 1', 'Room 2'] as $name) {
            $room = Room::firstOrCreate(['name' => $name]);
            Chair::firstOrCreate(['room_id' => $room->id, 'name' => 'Chair 1']);
        }
    }
}
