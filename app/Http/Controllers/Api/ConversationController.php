<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationConsent;
use App\Models\MessagingUserPermission;
use App\Models\Patient;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CommunicationConsentService;
use App\Services\MessagingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ConversationController extends Controller
{
    public function index(Request $request, MessagingService $service)
    {
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:200'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = $service->visible($request->user());
        if (isset($data['search'])) {
            // Search subjects only: redacted bodies must not leak through search matches.
            $query->where('subject', 'like', '%'.$data['search'].'%');
        }
        $threads = $query->with('participants')->orderByDesc('id')->paginate($data['per_page'] ?? 25);

        return ApiResponse::success(['threads' => $threads->items()], 'Conversations retrieved.', meta: ['pagination' => ['total' => $threads->total(), 'currentPage' => $threads->currentPage(), 'perPage' => $threads->perPage(), 'lastPage' => $threads->lastPage()]]);
    }

    public function store(Request $request, MessagingService $service)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'], 'patient_id' => ['nullable', 'integer'],
            'use_case' => ['required_with:patient_id', Rule::in(['care', 'billing', 'appointments', 'general'])],
            'user_ids' => ['sometimes', 'array', 'max:20'], 'user_ids.*' => ['integer', 'distinct'],
            'attachments' => ['prohibited'], 'sender_id' => ['prohibited'],
        ]);

        return ApiResponse::success(['thread' => $service->create($request->user(), $data)], 'Conversation created.', 201);
    }

    public function show(Request $request, int $thread, MessagingService $service)
    {
        return ApiResponse::success(['thread' => $service->accessible($request->user(), $thread)->load('participants')], 'Conversation retrieved.');
    }

    public function messages(Request $request, int $thread, MessagingService $service)
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $messages = $service->accessible($request->user(), $thread)->messages()->orderBy('id')->paginate($data['per_page'] ?? 25);

        return ApiResponse::success(['messages' => $messages->getCollection()->map(fn ($message) => $service->render($message))], 'Messages retrieved.', meta: ['pagination' => ['total' => $messages->total(), 'currentPage' => $messages->currentPage(), 'perPage' => $messages->perPage(), 'lastPage' => $messages->lastPage()]]);
    }

    public function confirm(Request $request, int $thread, MessagingService $service)
    {
        return ApiResponse::success($service->confirmation($request->user(), $thread, $this->messageData($request)), 'Review the message and explicitly confirm sending.', 201);
    }

    public function send(Request $request, int $thread, MessagingService $service)
    {
        [$message, $created] = $service->send($request->user(), $thread, $this->messageData($request));

        return ApiResponse::success(['message' => $service->render($message)], $created ? 'Message recorded.' : 'Message already recorded.', $created ? 201 : 200);
    }

    public function permissions(Request $request, User $user, MessagingService $service)
    {
        abort_unless($request->user()->hasRole(Role::ADMIN), 403);
        $data = $request->validate([
            'internal_send' => ['present', 'nullable', 'boolean'],
            'internal_receive' => ['present', 'nullable', 'boolean'],
            'patient_send' => ['present', 'nullable', 'boolean'],
        ]);
        abort_if($data['patient_send'] === true && $user->hasRole(Role::ADMIN), 403);
        $permission = DB::transaction(function () use ($service, $request, $user, $data) {
            $service->lockTenant();
            $permission = MessagingUserPermission::updateOrCreate(['user_id' => $user->id], $data);
            app(AuditLogService::class)->record('permissions_changed', 'messaging_user_permissions', $permission->id, $request->user()->id);

            return $permission;
        });

        return ApiResponse::success(['messaging_permissions' => $permission], 'Messaging permissions saved.');
    }

    public function preferences(Request $request, Patient $patient)
    {
        $this->authorizePreferences($request);

        return ApiResponse::success(['preferences' => CommunicationConsent::where('patient_id', $patient->id)->where('channel', 'in_app')
            ->whereIn('id', CommunicationConsent::selectRaw('MAX(id)')->where('patient_id', $patient->id)->where('channel', 'in_app')->groupBy('purpose', 'scope'))
            ->get()->map(fn ($consent) => $this->preferenceRepresentation($consent))], 'Current in-app consent retrieved.');
    }

    public function savePreference(Request $request, Patient $patient, CommunicationConsentService $service)
    {
        $this->authorizePreferences($request);
        $data = $request->validate([
            'channel' => ['required', Rule::in(['in_app'])], 'use_case' => ['required', Rule::in(['care', 'billing'])],
            'allowed' => ['required', 'boolean'], 'evidence' => ['required', 'string', 'max:2000'],
            'source' => ['required', 'string'], 'text_version' => ['required', 'string'], 'acknowledged_text' => ['required', 'string'],
        ]);
        $preference = $service->record($patient->id, [
            'channel' => 'in_app', 'purpose' => $data['use_case'] === 'care' ? 'clinical' : 'transactional',
            'scope' => $data['use_case'] === 'care' ? 'follow_up' : 'billing',
            'status' => $data['allowed'] ? 'granted' : 'withdrawn',
            'source' => $data['source'], 'text_version' => $data['text_version'],
            'acknowledged_text' => $data['acknowledged_text'], 'evidence' => $data['evidence'],
        ], $request->user());

        return ApiResponse::success(['preference' => $this->preferenceRepresentation($preference)], 'Messaging preference saved.');
    }

    private function preferenceRepresentation(CommunicationConsent $consent): array
    {
        return [...$consent->toArray(), 'allowed' => $consent->status === 'granted',
            'use_case' => match ($consent->scope) {
                'follow_up' => 'care', 'non_essential' => 'general', default => $consent->scope,
            }];
    }

    private function authorizePreferences(Request $request): void
    {
        app(CommunicationConsentService::class)->authorize($request->user());
    }

    private function messageData(Request $request): array
    {
        $request->mergeIfMissing(['kind' => 'message']);

        return $request->validate([
            'kind' => ['sometimes', Rule::in(['message', 'correction', 'redaction'])],
            'body' => ['required_unless:kind,redaction', 'prohibited_if:kind,redaction', 'nullable', 'string', 'max:10000'],
            'supersedes_id' => ['required_if:kind,correction,redaction', 'prohibited_if:kind,message', 'nullable', 'integer'],
            'reason' => ['required_if:kind,correction,redaction', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'confirmation_token' => ['sometimes', 'string', 'size:64'],
            'send_confirmed' => ['sometimes', 'boolean'], 'consent_acknowledged' => ['sometimes', 'boolean'],
            'attachments' => ['prohibited'], 'attachment_ids' => ['prohibited'], 'channel' => ['sometimes', Rule::in(['in_app'])],
            'sender_id' => ['prohibited'], 'recipients' => ['prohibited'], 'recipient_ids' => ['prohibited'], 'patient_id' => ['prohibited'],
        ]);
    }
}
