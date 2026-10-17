<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Room;
use App\Support\ApiResponse;

class AppointmentLocationController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Appointment::class);

        return ApiResponse::success([
            'rooms' => Room::query()->with(['chairs' => fn ($query) => $query->orderBy('name')->orderBy('id')])
                ->orderBy('name')->orderBy('id')->get()->map(fn (Room $room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'chairs' => $room->chairs->map(fn ($chair) => [
                        'id' => $chair->id,
                        'room_id' => $chair->room_id,
                        'name' => $chair->name,
                    ]),
                ]),
        ], 'Appointment locations retrieved.');
    }
}
