<?php

namespace App\Services;

use App\Documents\DocumentProtection;
use App\Insurance\InsurerObligationAccessPolicy;
use App\Insurance\InsurerObligationRules;
use App\Models\DocumentVersion;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimResponse;
use App\Models\InsuranceClaimSubmission;
use App\Models\InsurerObligation;
use App\Models\InsurerObligationApproval;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\PatientInsurerAssociation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InsurerObligationWorkflow
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogService $audit,
        private readonly DocumentProtection $protection,
    ) {}

    public function listing(array $input, User $actor)
    {
        $this->authorize($actor, 'view');
        $data = Validator::make($input, [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'invoice_id' => ['sometimes', 'integer', 'min:1'],
            'insurer_id' => ['sometimes', 'integer', 'min:1'],
            'approval_status' => ['sometimes', 'in:proposed,approved'],
        ])->validate();
        $this->rejectUnknown($input, ['page', 'per_page', 'invoice_id', 'insurer_id', 'approval_status']);

        $page = InsurerObligation::query()->with('approval')
            ->when(isset($data['invoice_id']), fn ($query) => $query->where('invoice_id', $data['invoice_id']))
            ->when(isset($data['insurer_id']), fn ($query) => $query->where('insurer_id', $data['insurer_id']))
            ->when(($data['approval_status'] ?? null) === 'approved', fn ($query) => $query->whereHas('approval'))
            ->when(($data['approval_status'] ?? null) === 'proposed', fn ($query) => $query->whereDoesntHave('approval'))
            ->orderByDesc('proposed_at')->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        foreach ($page->getCollection() as $obligation) {
            $this->authorizeInvoice($obligation->invoice_id, $obligation->patient_id, $actor);
        }
        $this->audit->record('insurer_obligation_listed', 'insurer_obligations', 'list', $actor->id);

        return $page;
    }

    public function show(int $id, User $actor): InsurerObligation
    {
        $this->authorize($actor, 'view');
        $obligation = InsurerObligation::query()->with('approval')->findOrFail($id);
        $this->authorizeInvoice($obligation->invoice_id, $obligation->patient_id, $actor);
        $this->audit->record('insurer_obligation_viewed', 'insurer_obligations', $id, $actor->id);

        return $obligation;
    }

    public function propose(array $input, string $commandKey, User $actor): array
    {
        $this->authorize($actor, 'propose');
        $data = $this->validatedProposal($input);
        $this->validateSources($data, $actor);
        if (! Str::isUuid($commandKey)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID idempotency key is required.']]);
        }

        $data['source_provenance'] = trim($data['source_provenance']);
        $data['proposed_amount'] = InsurerObligationRules::normalizeAmount($data['proposed_amount']);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $tenantId = $this->context->requireId();

        return DB::transaction(function () use ($tenantId, $commandKey, $actor, $data, $hash) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenantId.':insurer-obligation:proposal:'.$commandKey]);
            $existing = InsurerObligation::query()->with('approval')->where('command_key', $commandKey)->first();
            if ($existing !== null) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key was used with different obligation data.');
                $this->audit->record('insurer_obligation_replayed', 'insurer_obligations', $existing->id, $actor->id);

                return ['obligation' => $existing, 'replayed' => true];
            }

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($data['invoice_id']);
            $patient = Patient::query()->findOrFail($invoice->patient_id);
            $line = $invoice->items()->lockForUpdate()->findOrFail($data['invoice_item_id']);
            abort_unless($invoice->status === 'issued' || $invoice->status === 'partially_paid', 422, 'Obligations require an issued invoice line.');
            abort_unless($invoice->currency === $data['currency'], 422, 'Obligation currency must match the issued invoice.');
            abort_unless(InsurerObligationRules::amountWithinInvoiceLine($data['proposed_amount'], $line->line_total), 422, 'Proposed obligation cannot exceed the invoice-line charge.');

            $obligation = new InsurerObligation;
            $obligation->forceFill($data + [
                'patient_id' => $patient->id,
                'proposed_by_user_id' => $actor->id,
                'proposed_at' => now('UTC'),
                'command_key' => $commandKey,
                'request_hash' => $hash,
            ])->save();
            $this->audit->record('insurer_obligation_proposed', 'insurer_obligations', $obligation->id, $actor->id);

            return ['obligation' => $obligation, 'replayed' => false];
        }, 3);
    }

    public function approve(int $id, array $input, string $commandKey, User $actor): array
    {
        $this->authorize($actor, 'approve');
        $data = Validator::make($input, ['approval_reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();
        $this->rejectUnknown($input, ['approval_reason']);
        if (! Str::isUuid($commandKey)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID idempotency key is required.']]);
        }
        $data['approval_reason'] = trim($data['approval_reason']);
        $hash = hash('sha256', json_encode(['obligation_id' => $id, 'approval_reason' => $data['approval_reason']], JSON_THROW_ON_ERROR));
        $tenantId = $this->context->requireId();

        return DB::transaction(function () use ($tenantId, $id, $actor, $data, $commandKey, $hash) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenantId.':insurer-obligation:approval:'.$commandKey]);
            $replay = InsurerObligationApproval::query()->where('command_key', $commandKey)->first();
            if ($replay !== null) {
                abort_unless(hash_equals($replay->request_hash, $hash), 409, 'Idempotency key was used with different approval data.');
                $obligation = InsurerObligation::query()->with('approval')->findOrFail($replay->obligation_id);
                $this->audit->record('insurer_obligation_approval_replayed', 'insurer_obligations', $obligation->id, $actor->id);

                return ['obligation' => $obligation, 'replayed' => true];
            }

            $obligation = InsurerObligation::query()->findOrFail($id);
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($obligation->invoice_id);
            $this->authorizeInvoice($invoice->id, $invoice->patient_id, $actor);
            $line = $invoice->items()->lockForUpdate()->findOrFail($obligation->invoice_item_id);
            abort_unless($invoice->status === 'issued' || $invoice->status === 'partially_paid', 409, 'The invoice is no longer eligible for obligation approval.');
            abort_unless($invoice->currency === $obligation->currency, 409, 'Invoice currency no longer matches the proposal.');
            abort_unless(InsurerObligationRules::mayApprove(
                $obligation->approval()->exists(),
                (int) $obligation->tenant_id === $tenantId,
                (int) $line->invoice_id === (int) $obligation->invoice_id,
                $line->invoice->currency === $obligation->currency,
                InsurerObligationRules::amountWithinInvoiceLine($obligation->proposed_amount, $line->line_total),
                in_array($invoice->status, ['issued', 'partially_paid'], true),
            ), 409, 'Obligation is already approved or no longer matches its issued invoice line.');

            $approval = new InsurerObligationApproval;
            $approval->forceFill([
                'obligation_id' => $obligation->id,
                'invoice_item_id' => $line->id,
                'approved_amount' => $obligation->proposed_amount,
                'currency' => $obligation->currency,
                'approval_reason' => $data['approval_reason'],
                'approved_by_user_id' => $actor->id,
                'approved_at' => now('UTC'),
                'command_key' => $commandKey,
                'request_hash' => $hash,
            ])->save();
            $obligation->setRelation('approval', $approval);
            $this->audit->record('insurer_obligation_approved', 'insurer_obligations', $obligation->id, $actor->id);

            return ['obligation' => $obligation, 'replayed' => false];
        }, 3);
    }

    private function validatedProposal(array $input): array
    {
        $data = Validator::make($input, [
            'insurer_id' => ['required', 'integer', 'min:1'],
            'association_id' => ['required', 'integer', 'min:1'],
            'invoice_id' => ['required', 'integer', 'min:1'],
            'invoice_item_id' => ['required', 'integer', 'min:1'],
            'claim_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'submission_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'claim_response_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'supporting_evidence_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'proposed_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/'],
            'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
            'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ])->validate();
        $this->rejectUnknown($input, ['insurer_id', 'association_id', 'invoice_id', 'invoice_item_id', 'claim_id', 'submission_id', 'claim_response_id', 'supporting_evidence_id', 'proposed_amount', 'currency', 'source_provenance']);
        if (! InsurerObligationRules::claimLineageConsistent($data['claim_id'] ?? null, $data['submission_id'] ?? null, $data['claim_response_id'] ?? null)) {
            throw ValidationException::withMessages(['claim_id' => ['Claim and submission must be supplied together; a claim response requires both.']]);
        }
        if (InsurerObligationRules::normalizeAmount($data['proposed_amount']) === null) {
            throw ValidationException::withMessages(['proposed_amount' => ['Obligation amount must be a positive exact decimal.']]);
        }

        return $data;
    }

    private function validateSources(array $data, User $actor): void
    {
        $invoice = Invoice::query()->findOrFail($data['invoice_id']);
        $patient = Patient::query()->findOrFail($invoice->patient_id);
        $this->authorizeInvoice($invoice->id, $patient->id, $actor);
        abort_unless($invoice->items()->whereKey($data['invoice_item_id'])->exists(), 404);
        abort_unless(DB::table('insurers')->where('tenant_id', $this->context->requireId())->where('id', $data['insurer_id'])->exists(), 404);
        PatientInsurerAssociation::query()->where('patient_id', $patient->id)->where('insurer_id', $data['insurer_id'])->findOrFail($data['association_id']);

        if (($data['claim_id'] ?? null) !== null) {
            $claim = InsuranceClaim::query()->where('patient_id', $patient->id)->findOrFail($data['claim_id']);
            abort_unless((int) $claim->association_id === (int) $data['association_id'], 422, 'Claim must use the selected patient-insurer association.');
            InsuranceClaimSubmission::query()->where('claim_id', $claim->id)->findOrFail($data['submission_id']);
            if (($data['claim_response_id'] ?? null) !== null) {
                $response = InsuranceClaimResponse::query()->where('claim_id', $claim->id)->where('submission_id', $data['submission_id'])->findOrFail($data['claim_response_id']);
                abort_unless(in_array($response->response_type, ['approved', 'partially_approved'], true), 422, 'Only an approval response can be referenced as supporting context. The obligation amount remains a separate finance decision.');
            }
        }

        if (($data['supporting_evidence_id'] ?? null) !== null) {
            abort_unless($actor->hasPermission('insurance_documents.view') && ($data['claim_id'] ?? null) !== null, 403);
            $evidence = DB::table('insurance_prior_authorization_evidence')->where('tenant_id', $this->context->requireId())
                ->where('id', $data['supporting_evidence_id'])->where('patient_id', $patient->id)
                ->where(function ($query) use ($data) {
                    $query->where(fn ($q) => $q->where('target_type', 'claim')->where('claim_id', $data['claim_id']))
                        ->orWhere(fn ($q) => $q->where('target_type', 'submission')->where('submission_id', $data['submission_id'] ?? null));
                })->first();
            abort_if($evidence === null, 404);
            $version = DocumentVersion::query()->findOrFail($evidence->version_id);
            $document = PatientDocument::withTrashed()->findOrFail($version->document_id);
            Gate::forUser($actor)->authorize('view', $document);
            $this->protection->assertReferenceable($document);
            $this->protection->assertReadable($document);
        }
    }

    private function authorizeInvoice(int $invoiceId, int $patientId, User $actor): void
    {
        $invoice = Invoice::query()->findOrFail($invoiceId);
        abort_unless((int) $invoice->patient_id === $patientId, 404);
        $patient = Patient::query()->findOrFail($patientId);
        Gate::forUser($actor)->authorize('view', $patient);
        Gate::forUser($actor)->authorize('view', $invoice);
    }

    private function authorize(User $actor, string $action): void
    {
        abort_unless(InsurerObligationAccessPolicy::allows($actor, $action), 403);
    }

    private function rejectUnknown(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported insurer obligation field.']]);
        }
    }
}
