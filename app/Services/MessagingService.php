<?php

namespace App\Services;

use App\Models\ConversationMessage;
use App\Models\ConversationThread;
use App\Models\MessageConfirmation;
use App\Models\MessagingUserPermission;
use App\Models\Patient;
use App\Models\PatientMessagingPreference;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MessagingService
{
    public function permitted(User $user, string $permission): bool
    {
        if (! $user->isMemberOfCurrentTenant() || $user->hasRole('patient') || ! $user->roles()->exists()) {
            return false;
        }
        if ($permission === 'patient_send' && $user->hasRole(Role::ADMIN)) {
            return false;
        }
        $override = MessagingUserPermission::where('user_id', $user->id)->first()?->getAttribute($permission);

        return $override ?? $user->hasPermission('messaging.'.str_replace('_', '.', $permission));
    }

    public function visible(User $user)
    {
        $query = ConversationThread::whereHas('participants', fn ($q) => $q->where('user_id', $user->id));
        if (! $this->permitted($user, 'internal_receive') && ! $this->permitted($user, 'internal_send')) {
            $query->whereNotNull('patient_id');
        }
        if (! $this->permitted($user, 'patient_send')) {
            $query->whereNull('patient_id');
        }

        return $query;
    }

    public function accessible(User $actor, int $threadId): ConversationThread
    {
        return $this->visible($actor)->findOrFail($threadId);
    }

    public function create(User $actor, array $data): ConversationThread
    {
        return DB::transaction(function () use ($actor, $data) {
            $this->lockTenant();
            $patient = isset($data['patient_id']) ? Patient::active()->findOrFail($data['patient_id']) : null;
            abort_unless($this->permitted($actor, $patient ? 'patient_send' : 'internal_send'), 403);
            $ids = array_values(array_unique([$actor->id, ...($data['user_ids'] ?? [])]));
            $users = User::forCurrentTenant()->whereIn('id', $ids)->get();
            if ($users->count() !== count($ids) || (! $patient && count($ids) < 2)) {
                throw ValidationException::withMessages(['user_ids' => 'Select eligible clinic participants.']);
            }
            foreach ($users as $user) {
                abort_unless($this->permitted($user, $patient ? 'patient_send' : 'internal_receive')
                    || ($user->id === $actor->id && ! $patient && $this->permitted($user, 'internal_send')), 403);
                // Admin-to-staff broadcasts have no admin recipients; staff cannot target admins.
                abort_if($user->id !== $actor->id && $user->hasRole(Role::ADMIN), 403);
            }
            if ($patient) {
                $this->consent($patient->id, $data['use_case']);
            }
            abort_if(ConversationThread::where('created_by', $actor->id)->where('created_at', '>=', now()->subMinute())->count() >= 10, 429);
            $thread = ConversationThread::create([
                'patient_id' => $patient?->id, 'created_by' => $actor->id,
                'subject' => $data['subject'], 'use_case' => $patient ? $data['use_case'] : 'internal',
            ]);
            foreach ($ids as $id) {
                $thread->participants()->create(['user_id' => $id]);
            }
            if ($patient) {
                $thread->participants()->create(['patient_id' => $patient->id]);
            }
            app(AuditLogService::class)->record('created', 'conversation_threads', $thread->id, $actor->id);

            return $thread->load('participants');
        }, 3);
    }

    public function confirmation(User $actor, int $threadId, array $data): array
    {
        return DB::transaction(function () use ($actor, $threadId, $data) {
            $this->lockTenant();
            $thread = $this->accessible($actor, $threadId);
            abort_unless($thread->patient_id !== null, 422, 'Only patient messages require confirmation.');
            $preference = $this->authorizeSend($actor, $thread);
            $this->target($actor, $thread, $data);
            $token = Str::random(64);
            $confirmation = MessageConfirmation::create([
                'thread_id' => $thread->id, 'user_id' => $actor->id,
                'token_hash' => hash('sha256', $token), 'payload_hash' => $this->payloadHash($thread->id, $data),
                'expires_at' => now()->addMinutes(5),
            ]);
            app(AuditLogService::class)->record('prepared', 'message_confirmations', $confirmation->id, $actor->id);

            return ['confirmation_token' => $token, 'expires_at' => $confirmation->expires_at,
                'channel' => 'in_app', 'use_case' => $thread->use_case, 'patient_id' => $thread->patient_id,
                'preference_id' => $preference->id, 'body' => $data['body'] ?? null,
                'kind' => $data['kind'] ?? 'message', 'supersedes_id' => $data['supersedes_id'] ?? null,
                'reason' => $data['reason'] ?? null,
                'acknowledgement_required' => true];
        });
    }

    public function send(User $actor, int $threadId, array $data): array
    {
        return DB::transaction(function () use ($actor, $threadId, $data) {
            // Serializes durable rate limits, consent changes and duplicate sends across workers.
            $this->lockTenant();
            $thread = $this->accessible($actor, $threadId);
            $preference = $this->authorizeSend($actor, $thread);
            $hash = $this->payloadHash($thread->id, $data);
            $existing = ConversationMessage::where('actor_id', $actor->id)->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'Idempotency key already used for another message.');

                return [$existing, false];
            }
            $this->target($actor, $thread, $data);
            $metadata = ['channel' => 'in_app'];
            if ($thread->patient_id !== null) {
                if (! ($data['consent_acknowledged'] ?? false) || ! ($data['send_confirmed'] ?? false)) {
                    throw ValidationException::withMessages(['send_confirmed' => 'Explicit send confirmation and consent acknowledgement are required.']);
                }
                $confirmation = MessageConfirmation::where('thread_id', $thread->id)->where('user_id', $actor->id)
                    ->where('token_hash', hash('sha256', $data['confirmation_token'] ?? ''))->lockForUpdate()->first();
                if (! $confirmation || $confirmation->consumed_at !== null || $confirmation->expires_at->isPast()
                    || ! hash_equals($confirmation->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['confirmation_token' => 'Prepare a new confirmation for this exact message.']);
                }
                $confirmation->update(['consumed_at' => now()]);
                $metadata += ['confirmation_id' => $confirmation->id, 'confirmed_at' => now()->toISOString(),
                    'consent_acknowledged' => true, 'preference_id' => $preference->id,
                    'preference_updated_at' => $preference->updated_at->toISOString(), 'use_case' => $thread->use_case];
            }
            abort_if(ConversationMessage::where('actor_id', $actor->id)->where('created_at', '>=', now()->subMinute())->count() >= 20, 429);
            if ($thread->patient_id !== null) {
                abort_if(ConversationMessage::whereIn('thread_id', ConversationThread::where('patient_id', $thread->patient_id)->select('id'))
                    ->where('created_at', '>=', now()->subHour())->count() >= 50, 429);
            }
            $participants = $thread->participants()->get();
            $sender = $participants->firstWhere('user_id', $actor->id);
            $message = ConversationMessage::create([
                'thread_id' => $thread->id, 'sender_id' => $sender->id, 'actor_id' => $actor->id,
                'recipient_ids' => $participants->where('id', '!=', $sender->id)->pluck('id')->all(),
                'body' => $data['body'] ?? null, 'kind' => $data['kind'] ?? 'message',
                'supersedes_id' => $data['supersedes_id'] ?? null, 'reason' => $data['reason'] ?? null,
                'channel' => 'in_app', 'delivery_state' => $thread->patient_id === null ? 'available' : 'awaiting_portal',
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                'audit_metadata' => $metadata, 'created_at' => now(),
            ]);
            app(AuditLogService::class)->record($message->kind === 'message' ? 'sent' : $message->kind, 'conversation_messages', $message->id, $actor->id);

            return [$message, true];
        }, 3);
    }

    public function render(ConversationMessage $message): array
    {
        $redacted = ConversationMessage::where('thread_id', $message->thread_id)->where('supersedes_id', $message->id)->where('kind', 'redaction')->exists();
        $result = $message->toArray();
        if ($redacted) {
            $result['body'] = null;
            $result['reason'] = null;
        }
        $result['redacted'] = $redacted;

        return $result;
    }

    public function lockTenant(): void
    {
        Tenant::where('active', true)->lockForUpdate()->findOrFail(app(TenantContext::class)->requireId());
    }

    private function authorizeSend(User $actor, ConversationThread $thread): ?PatientMessagingPreference
    {
        $patient = $thread->patient_id !== null;
        abort_unless($this->permitted($actor, $patient ? 'patient_send' : 'internal_send'), 403);
        foreach ($thread->participants()->whereNotNull('user_id')->get() as $participant) {
            $user = User::forCurrentTenant()->find($participant->user_id);
            abort_unless($user, 403);
            if ($patient) {
                // Revalidate every participant after role/permission changes.
                abort_unless($this->permitted($user, 'patient_send'), 403);
            } elseif ($user->id !== $actor->id) {
                abort_if($user->hasRole(Role::ADMIN), 403);
                abort_unless($this->permitted($user, 'internal_receive'), 403);
            }
        }

        return $patient ? $this->consent($thread->patient_id, $thread->use_case) : null;
    }

    private function consent(int $patientId, string $useCase): PatientMessagingPreference
    {
        abort_unless(Patient::active()->whereKey($patientId)->exists(), 403);
        $preference = PatientMessagingPreference::where('patient_id', $patientId)->where('channel', 'in_app')->where('use_case', $useCase)->first();
        abort_unless($preference?->allowed, 403, 'Patient consent does not permit this channel and use case.');

        return $preference;
    }

    private function target(User $actor, ConversationThread $thread, array $data): void
    {
        if (($data['kind'] ?? 'message') === 'message') {
            return;
        }
        $target = $thread->messages()->findOrFail($data['supersedes_id']);
        abort_unless((int) $target->actor_id === (int) $actor->id, 403);
        abort_if($target->kind === 'redaction' || $thread->messages()->where('supersedes_id', $target->id)->where('kind', 'redaction')->exists(), 409);
    }

    private function payloadHash(int $threadId, array $data): string
    {
        return hash('sha256', json_encode([$threadId, $data['idempotency_key'], $data['body'] ?? null,
            $data['kind'] ?? 'message', $data['supersedes_id'] ?? null, $data['reason'] ?? null], JSON_THROW_ON_ERROR));
    }
}
