<?php

namespace App\Services;

use App\Insurance\InsurerReceiptAccessPolicy;
use App\Insurance\InsurerReceiptRules;
use App\Models\InsurerReceipt;
use App\Models\InsurerReceiptVerification;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InsurerReceiptWorkflow
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogService $audit) {}

    public function listing(array $input, User $actor)
    {
        $this->authorize($actor, 'view');
        $data = Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'insurer_id' => ['sometimes', 'integer', 'min:1']])->validate();
        $page = InsurerReceipt::query()->with('verification')->when(isset($data['insurer_id']), fn ($q) => $q->where('insurer_id', $data['insurer_id']))->orderByDesc('received_on')->orderByDesc('id')->paginate($data['per_page'] ?? 15);
        $this->audit->record('insurer_receipts.listed', 'insurer_receipts', 'list', $actor->id);

        return $page;
    }

    public function show(int $id, User $actor): InsurerReceipt
    {
        $this->authorize($actor, 'view');
        $receipt = InsurerReceipt::query()->with('verification')->findOrFail($id);
        $this->audit->record('insurer_receipts.viewed', 'insurer_receipts', $id, $actor->id);

        return $receipt;
    }

    public function record(array $input, string $key, User $actor): array
    {
        $this->authorize($actor, 'record');
        $data = Validator::make($input, [
            'insurer_id' => ['required', 'integer', 'min:1'], 'remittance_advice_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:/\S/u'], 'received_on' => ['required', 'date_format:Y-m-d'],
            'channel' => ['required', 'in:portal,email,fax,paper,phone,bank_statement,other'], 'source_provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
            'amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/'],
        ])->validate();
        $this->rejectUnknown($input, ['insurer_id', 'remittance_advice_id', 'external_reference', 'received_on', 'channel', 'source_provenance', 'amount', 'currency']);
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['A UUID idempotency key is required.']]);
        }
        if (($amount = InsurerReceiptRules::amount($data['amount'])) === null || $amount === '0.00') {
            throw ValidationException::withMessages(['amount' => ['A positive exact decimal amount is required.']]);
        }
        $tenantId = $this->context->requireId();
        abort_unless(DB::table('insurers')->where('tenant_id', $tenantId)->where('id', $data['insurer_id'])->exists(), 404);
        if (isset($data['remittance_advice_id'])) {
            abort_unless(DB::table('insurance_remittance_advices')->where('tenant_id', $tenantId)->where('id', $data['remittance_advice_id'])->where('insurer_id', $data['insurer_id'])->where('currency', $data['currency'])->exists(), 422, 'Advice must match receipt insurer and currency.');
        }
        $data['amount'] = $amount;
        $data['external_reference'] = isset($data['external_reference']) ? trim($data['external_reference']) : null;
        $data['source_provenance'] = trim($data['source_provenance']);
        ksort($data);
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenantId, $key, $actor, $data, $hash) {
            DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenantId.':insurer-receipt:'.$key]);
            $existing = InsurerReceipt::query()->where('command_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key was used with different receipt data.');
                $this->audit->record('insurer_receipts.replayed', 'insurer_receipts', $existing->id, $actor->id);

                return ['receipt' => $existing->load('verification'), 'replayed' => true];
            }
            if ($data['external_reference'] !== null) {
                DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [$tenantId.':insurer-receipt:reference:'.$data['insurer_id'].':'.$data['external_reference']]);
            }
            if ($data['external_reference'] !== null && InsurerReceipt::query()->where('insurer_id', $data['insurer_id'])->where('external_reference', $data['external_reference'])->exists()) {
                abort(409, 'This insurer receipt reference is already recorded.');
            }
            $receipt = new InsurerReceipt;
            $receipt->forceFill($data + ['recorded_at' => now('UTC'), 'recorded_by_user_id' => $actor->id, 'command_key' => $key, 'request_hash' => $hash])->save();
            $this->audit->record('insurer_receipts.recorded', 'insurer_receipts', $receipt->id, $actor->id);

            return ['receipt' => $receipt, 'replayed' => false];
        }, 3);
    }

    public function verify(int $id, array $input, User $actor): InsurerReceipt
    {
        $this->authorize($actor, 'verify');
        $data = Validator::make($input, ['verification_method' => ['required', 'in:bank_statement,deposit_record,insurer_confirmation,other'], 'provenance' => ['required', 'string', 'max:2000', 'regex:/\S/u'], 'observed_amount' => ['required', 'string', 'regex:/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/'], 'observed_currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/']])->validate();
        $this->rejectUnknown($input, ['verification_method', 'provenance', 'observed_amount', 'observed_currency']);
        $amount = InsurerReceiptRules::amount($data['observed_amount']);
        abort_if($amount === null || $amount === '0.00', 422, 'Verification amount must be positive and exact.');

        return DB::transaction(function () use ($id, $actor, $data, $amount) {
            $receipt = InsurerReceipt::query()->lockForUpdate()->findOrFail($id);
            $allowed = InsurerReceiptRules::canVerify($receipt->verification()->exists(), trim($data['provenance']) !== '', true, $receipt->currency === $data['observed_currency'], $receipt->amount === $amount);
            abort_unless($allowed, 409, 'Receipt cannot be verified twice, or observed amount/currency does not match.');
            $verification = new InsurerReceiptVerification;
            $verification->forceFill(['receipt_id' => $receipt->id, 'verified_at' => now('UTC'), 'verified_by_user_id' => $actor->id, 'verification_method' => $data['verification_method'], 'provenance' => trim($data['provenance']), 'observed_amount' => $amount, 'observed_currency' => $data['observed_currency']])->save();
            $this->audit->record('insurer_receipts.verified', 'insurer_receipts', $receipt->id, $actor->id);

            return $receipt->load('verification');
        }, 3);
    }

    private function authorize(User $actor, string $action): void
    {
        abort_unless(InsurerReceiptAccessPolicy::allows($actor, $action), 403);
    }

    private function rejectUnknown(array $input, array $allowed): void
    {
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([reset($unknown) => ['Unsupported receipt field.']]);
        }
    }
}
