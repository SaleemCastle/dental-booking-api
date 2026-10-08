<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function index()
    {
        return ApiResponse::success([
            'messages' => Message::with(['sender', 'receiver'])->get(),
        ], 'Messages retrieved.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sender_id' => ['required', 'integer', 'exists:patient,id'],
            'receiver_id' => ['required', 'integer', 'exists:patient,id'],
            'message_text' => ['required', 'string'],
            'timestamp' => ['sometimes', 'date'],
        ]);

        $message = Message::create($validated);

        return ApiResponse::success([
            'messageItem' => $message->load(['sender', 'receiver']),
        ], 'Message successfully created.', 201);
    }

    public function show(Message $message)
    {
        return ApiResponse::success([
            'messageItem' => $message->load(['sender', 'receiver']),
        ], 'Message retrieved.');
    }

    public function update(Request $request, Message $message)
    {
        $validated = $request->validate([
            'sender_id' => ['sometimes', 'required', 'integer', 'exists:patient,id'],
            'receiver_id' => ['sometimes', 'required', 'integer', 'exists:patient,id'],
            'message_text' => ['sometimes', 'required', 'string'],
            'timestamp' => ['sometimes', 'date'],
        ]);

        $message->update($validated);

        return ApiResponse::success([
            'messageItem' => $message->refresh()->load(['sender', 'receiver']),
        ], 'Message successfully updated.');
    }

    public function destroy(Message $message)
    {
        $message->delete();

        return ApiResponse::success(message: 'Message successfully deleted.');
    }
}
