<?php

namespace App\Services;

use App\Models\CommunicationGovernancePolicy;
use App\Models\ConversationAccessState;
use App\Models\ConversationGovernanceEvent;
use App\Models\ConversationParticipant;
use App\Models\ConversationThread;
use App\Models\ConversationThreadState;
use App\Models\MessagingUserPermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CommunicationGovernanceService
{
    public function __construct(private readonly MessagingService $messaging) {}

    public function policy(): ?CommunicationGovernancePolicy
    {
        return CommunicationGovernancePolicy::orderByDesc('version')->first();
    }

    public function requirePermission(User $actor, string $permission): void
    {
        abort_unless($this->messaging->permitted($actor, $permission), 403);
    }

    public function savePolicy(User $actor, array $data): CommunicationGovernancePolicy
    {
        $this->requirePermission($actor, 'settings_manage');

        return DB::transaction(function () use ($actor, $data) {
            $this->messaging->lockTenant();
            $policy = CommunicationGovernancePolicy::create([
                ...$data, 'version' => ($this->policy()?->version ?? 0) + 1,
                'recorded_by' => $actor->id, 'recorded_at' => now('UTC'),
            ]);
            app(AuditLogService::class)->record('policy_recorded', 'communication_governance_policies', $policy->id, $actor->id);

            return $policy;
        }, 3);
    }

    public function state(ConversationThread $thread): ConversationThreadState
    {
        return ConversationThreadState::firstOrCreate(['thread_id' => $thread->id], [
            'assigned_to' => $thread->patient_id === null ? null : $thread->created_by,
            'status' => 'open', 'legal_hold' => false, 'last_activity_at' => $thread->created_at,
        ]);
    }

    public function retention(ConversationThread $thread): array
    {
        $policy = $this->policy();
        $state = $this->state($thread);
        $indefinite = $state->legal_hold || $policy?->retention_days === null;

        return ['policy_version' => $policy?->version, 'minimum_days' => $policy?->retention_days,
            'minimum_retain_until' => $indefinite ? null : $state->last_activity_at->addDays($policy->retention_days)->toISOString(),
            'indefinite' => $indefinite, 'legal_hold' => $state->legal_hold, 'originals_retained' => true, 'deletion_authorized' => false];
    }

    public function handoff(User $actor, int $threadId, int $targetId, string $reason): ConversationGovernanceEvent
    {
        return DB::transaction(function () use ($actor, $threadId, $targetId, $reason) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'assign');
            abort_unless($thread->patient_id !== null, 422);
            $state = $this->state($thread);
            abort_if($state->status === 'archived', 409);
            $target = User::forCurrentTenant()->findOrFail($targetId);
            abort_unless($this->messaging->permitted($target, 'patient_view') && $this->messaging->permitted($target, 'patient_send'), 403);
            abort_if((int) $state->assigned_to === $targetId, 409);
            $previous = $state->assigned_to;
            $this->grant($thread, $target);
            if ($previous !== null && (int) $previous !== (int) $state->escalated_to) {
                $this->revoke($thread, $previous);
            }
            $state->update(['assigned_to' => $targetId, 'last_activity_at' => now('UTC')]);

            return $this->event($thread, $actor, 'assigned', $reason, $targetId, metadata: ['previous_assignee_id' => $previous]);
        }, 3);
    }

    public function escalate(User $actor, int $threadId, int $targetId, string $reason): ConversationGovernanceEvent
    {
        return DB::transaction(function () use ($actor, $threadId, $targetId, $reason) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'escalate');
            abort_unless($thread->patient_id !== null && $this->policy()?->escalation_enabled, 403);
            $state = $this->state($thread);
            abort_if(in_array($state->status, ['archived', 'escalated'], true), 409);
            $target = User::forCurrentTenant()->findOrFail($targetId);
            abort_unless($this->messaging->permitted($target, 'patient_view') && $this->messaging->permitted($target, 'supervise'), 403);
            abort_if($targetId === (int) $state->assigned_to || $targetId === $actor->id, 422);
            $existing = $thread->participants()->active()->where('user_id', $targetId)->exists();
            $this->grant($thread, $target);
            $state->update(['status' => 'escalated', 'escalated_to' => $targetId, 'last_activity_at' => now('UTC')]);

            return $this->event($thread, $actor, 'escalated', $reason, $targetId, metadata: ['temporary_access' => ! $existing]);
        }, 3);
    }

    public function resolve(User $actor, int $threadId, string $reason): ConversationGovernanceEvent
    {
        return DB::transaction(function () use ($actor, $threadId, $reason) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'supervise');
            $state = $this->state($thread);
            abort_unless($state->status === 'escalated' && (int) $state->escalated_to === $actor->id, 403);
            $escalation = ConversationGovernanceEvent::where('thread_id', $threadId)->where('action', 'escalated')->orderByDesc('id')->firstOrFail();
            if (($escalation->metadata['temporary_access'] ?? false) && (int) $state->assigned_to !== $actor->id) {
                $this->revoke($thread, $actor->id);
            }
            $state->update(['status' => 'resolved', 'escalated_to' => null, 'last_activity_at' => now('UTC')]);

            return $this->event($thread, $actor, 'resolved', $reason);
        }, 3);
    }

    public function archive(User $actor, int $threadId, string $reason, bool $archived): ConversationGovernanceEvent
    {
        return DB::transaction(function () use ($actor, $threadId, $reason, $archived) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'archive');
            $state = $this->state($thread);
            abort_if($state->status === 'escalated' || ($archived === ($state->status === 'archived')), 409);
            $state->update(['status' => $archived ? 'archived' : 'open', 'archived_at' => $archived ? now('UTC') : null, 'last_activity_at' => now('UTC')]);

            return $this->event($thread, $actor, $archived ? 'archived' : 'reopened', $reason, metadata: ['policy_version' => $this->policy()?->version]);
        }, 3);
    }

    public function hold(User $actor, int $threadId, string $reason, bool $enabled): ConversationGovernanceEvent
    {
        return DB::transaction(function () use ($actor, $threadId, $reason, $enabled) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'settings_manage');
            $this->state($thread)->update(['legal_hold' => $enabled]);

            return $this->event($thread, $actor, $enabled ? 'hold_applied' : 'hold_released', $reason);
        }, 3);
    }

    public function redact(User $actor, int $threadId, int $messageId, string $reason): ConversationGovernanceEvent
    {
        return DB::transaction(function () use ($actor, $threadId, $messageId, $reason) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'redact');
            $thread->messages()->findOrFail($messageId);
            abort_if(ConversationGovernanceEvent::where('message_id', $messageId)->where('action', 'redacted')->exists(), 409);

            return $this->event($thread, $actor, 'redacted', $reason, messageId: $messageId, metadata: [
                'original_retained' => true, 'policy_version' => $this->policy()?->version,
                'retention_days' => $this->policy()?->retention_days, 'legal_hold' => $this->state($thread)->legal_hold,
            ]);
        }, 3);
    }

    public function export(User $actor, int $threadId, string $reason, int $page, int $perPage): array
    {
        return DB::transaction(function () use ($actor, $threadId, $reason, $page, $perPage) {
            $this->messaging->lockTenant();
            $thread = $this->messaging->accessible($actor, $threadId);
            $this->requirePermission($actor, 'export');
            $messages = $thread->messages()->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
            $event = $this->event($thread, $actor, 'exported', $reason, metadata: [
                'page' => $page, 'per_page' => $perPage, 'message_ids' => $messages->getCollection()->pluck('id')->all(),
            ]);

            return ['thread' => $thread->load('state'), 'messages' => $messages->getCollection()->map(fn ($message) => $this->messaging->render($message)),
                'export_event_id' => $event->id, 'policy_version' => $this->policy()?->version, 'retention' => $this->retention($thread),
                'pagination' => ['currentPage' => $page, 'perPage' => $perPage, 'total' => $messages->total(), 'lastPage' => $messages->lastPage()]];
        }, 3);
    }

    public function deactivate(User $target, User $actor): void
    {
        DB::transaction(function () use ($target, $actor) {
            $this->messaging->lockTenant();
            ConversationParticipant::where('user_id', $target->id)->get()->each(function ($participant) use ($actor) {
                ConversationAccessState::updateOrCreate(['participant_id' => $participant->id], ['active' => false]);
                $thread = ConversationThread::findOrFail($participant->thread_id);
                $this->invalidateConfirmations($thread);
                $state = $this->state($thread);
                $changes = [];
                if ((int) $state->assigned_to === $participant->user_id) {
                    $changes['assigned_to'] = null;
                }
                if ((int) $state->escalated_to === $participant->user_id) {
                    $changes += ['escalated_to' => null, 'status' => 'open'];
                }
                if ($changes !== []) {
                    $state->update($changes);
                }
                $this->event($thread, $actor, 'access_revoked', 'Clinic membership or communication access deactivated.', $participant->user_id);
            });
            MessagingUserPermission::updateOrCreate(['user_id' => $target->id], array_fill_keys(MessagingService::PERMISSION_FIELDS, false));
        }, 3);
    }

    public function archiveDue(): int
    {
        return DB::transaction(function () {
            $this->messaging->lockTenant();
            $policy = $this->policy();
            if ($policy?->archive_after_days === null) {
                return 0;
            }
            $states = ConversationThreadState::whereIn('status', ['open', 'resolved'])->where('legal_hold', false)
                ->where('last_activity_at', '<=', now('UTC')->subDays($policy->archive_after_days))->orderBy('id')->limit(1000)->lockForUpdate()->get();
            foreach ($states as $state) {
                $state->update(['status' => 'archived', 'archived_at' => now('UTC')]);
                $this->event(ConversationThread::findOrFail($state->thread_id), null, 'archived', 'Configured inactivity archive rule.', metadata: ['policy_version' => $policy->version]);
            }

            return $states->count();
        }, 3);
    }

    private function grant(ConversationThread $thread, User $user): void
    {
        $participant = $thread->participants()->firstOrCreate(['user_id' => $user->id]);
        ConversationAccessState::updateOrCreate(['participant_id' => $participant->id], ['active' => true]);
        $this->invalidateConfirmations($thread);
    }

    private function revoke(ConversationThread $thread, int $userId): void
    {
        $participant = $thread->participants()->where('user_id', $userId)->first();
        if ($participant) {
            ConversationAccessState::updateOrCreate(['participant_id' => $participant->id], ['active' => false]);
            $this->invalidateConfirmations($thread);
        }
    }

    private function invalidateConfirmations(ConversationThread $thread): void
    {
        \App\Models\MessageConfirmation::where('thread_id', $thread->id)->whereNull('consumed_at')->update(['expires_at' => now('UTC')->subSecond()]);
    }

    public function event(ConversationThread $thread, ?User $actor, string $action, string $reason, ?int $subjectId = null, ?int $messageId = null, array $metadata = []): ConversationGovernanceEvent
    {
        $event = ConversationGovernanceEvent::create([
            'thread_id' => $thread->id, 'actor_id' => $actor?->id, 'subject_user_id' => $subjectId,
            'message_id' => $messageId, 'action' => $action, 'reason' => $reason, 'metadata' => $metadata, 'occurred_at' => now('UTC'),
        ]);
        app(AuditLogService::class)->record($action, 'conversation_governance_events', $event->id, $actor?->id);

        return $event;
    }
}
