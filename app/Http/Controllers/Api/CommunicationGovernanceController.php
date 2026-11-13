<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationGovernanceEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CommunicationGovernanceService;
use App\Services\MessagingService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CommunicationGovernanceController extends Controller
{
    public function policy(Request $request, CommunicationGovernanceService $service)
    {
        $service->requirePermission($request->user(), 'settings_manage');

        return ApiResponse::success(['policy' => $service->policy(), 'originals_retained' => true], 'Communication governance policy retrieved.');
    }

    public function savePolicy(Request $request, CommunicationGovernanceService $service)
    {
        $service->requirePermission($request->user(), 'settings_manage');
        $data = $request->validate([
            'retention_days' => 'present|nullable|integer|min:1|max:36500', 'archive_after_days' => 'present|nullable|integer|min:1|max:36500',
            'escalation_enabled' => 'required|boolean', 'policy_reference' => 'required|string|max:2000',
        ]);

        return ApiResponse::success(['policy' => $service->savePolicy($request->user(), $data), 'originals_retained' => true], 'Governance policy version recorded.', 201);
    }

    public function handoff(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        $data = $request->validate(['user_id' => 'required|integer', 'reason' => 'required|string|max:2000']);

        return $this->eventResponse($service->handoff($request->user(), $thread, $data['user_id'], $data['reason']));
    }

    public function escalate(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        $data = $request->validate(['user_id' => 'required|integer', 'reason' => 'required|string|max:2000']);

        return $this->eventResponse($service->escalate($request->user(), $thread, $data['user_id'], $data['reason']));
    }

    public function resolve(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        return $this->eventResponse($service->resolve($request->user(), $thread, $this->reason($request)));
    }

    public function archive(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        $data = $request->validate(['archived' => 'required|boolean', 'reason' => 'required|string|max:2000']);

        return $this->eventResponse($service->archive($request->user(), $thread, $data['reason'], (bool) $data['archived']));
    }

    public function hold(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'reason' => 'required|string|max:2000']);

        return $this->eventResponse($service->hold($request->user(), $thread, $data['reason'], (bool) $data['enabled']));
    }

    public function redact(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        $data = $request->validate(['message_id' => 'required|integer', 'reason' => 'required|string|max:2000']);

        return $this->eventResponse($service->redact($request->user(), $thread, $data['message_id'], $data['reason']));
    }

    public function history(Request $request, int $thread, CommunicationGovernanceService $service, MessagingService $messaging)
    {
        $messaging->accessible($request->user(), $thread);
        $service->requirePermission($request->user(), 'history_view');
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        app(AuditLogService::class)->record('governance_history_accessed', 'conversation_threads', $thread, $request->user()->id);

        return ApiResponse::success(ConversationGovernanceEvent::where('thread_id', $thread)->orderByDesc('id')->paginate($data['per_page'] ?? 25), 'Governance history retrieved.')
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, int $thread, CommunicationGovernanceService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:2000', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return ApiResponse::success($service->export($request->user(), $thread, $data['reason'], $data['page'] ?? 1, $data['per_page'] ?? 100), 'Authorized message export page.')
            ->header('Cache-Control', 'private, no-store')->header('Content-Disposition', 'attachment; filename="conversation-'.$thread.'.json"');
    }

    public function deactivate(Request $request, User $user, CommunicationGovernanceService $service)
    {
        abort_unless($request->user()->hasRole(Role::ADMIN), 403);
        $service->requirePermission($request->user(), 'settings_manage');
        $service->deactivate($user, $request->user());

        return ApiResponse::success(message: 'Communication access deactivated; historical authorship retained.');
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => 'required|string|max:2000'])['reason'];
    }

    private function eventResponse(ConversationGovernanceEvent $event)
    {
        return ApiResponse::success(['event' => $event], 'Governance action recorded.', 201)->header('Cache-Control', 'private, no-store');
    }
}
