<?php

namespace Tests\Unit;

use App\Http\Resources\InsurancePriorAuthorizationEventResource;
use App\Http\Resources\InsurancePriorAuthorizationResource;
use App\Models\InsurancePriorAuthorization;
use App\Models\InsurancePriorAuthorizationDecision;
use App\Models\InsurancePriorAuthorizationEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use LogicException;
use PHPUnit\Framework\TestCase;

class InsurancePriorAuthorizationModelTest extends TestCase
{
    public function test_authorization_model_casts_exact_amount_and_hides_idempotency_fields(): void
    {
        $authorization = (new InsurancePriorAuthorization)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 12,
            'request_date' => '2026-11-20',
            'requested_amount' => '125.30',
            'procedure_details' => ['tooth' => '14'],
            'command_key' => 'private-command-key',
            'request_hash' => str_repeat('a', 64),
        ]);

        $attributes = $authorization->toArray();

        $this->assertSame(['draft', 'requested', 'pending', 'approved', 'partially_approved', 'denied', 'cancelled', 'expired'], InsurancePriorAuthorization::STATUSES);
        $this->assertSame('125.30', $attributes['requested_amount']);
        $this->assertSame('2026-11-20T00:00:00.000000Z', $attributes['request_date']);
        $this->assertSame(['tooth' => '14'], $attributes['procedure_details']);
        $this->assertArrayNotHasKey('command_key', $attributes);
        $this->assertArrayNotHasKey('request_hash', $attributes);
    }

    public function test_decision_model_preserves_unknown_amount_and_hides_encrypted_reference(): void
    {
        $decision = (new InsurancePriorAuthorizationDecision)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'decision_type' => 'approved',
            'authorized_amount' => null,
            'valid_from' => '2026-11-20',
            'insurer_reference_ciphertext' => 'encrypted-secret',
        ]);

        $attributes = $decision->toArray();

        $this->assertNull($attributes['authorized_amount']);
        $this->assertSame('2026-11-20T00:00:00.000000Z', $attributes['valid_from']);
        $this->assertArrayNotHasKey('insurer_reference_ciphertext', $attributes);
    }

    public function test_authorization_history_models_are_append_only(): void
    {
        $persistedEvent = new InsurancePriorAuthorizationEvent;
        $persistedEvent->exists = true;

        try {
            $persistedEvent->save();
            $this->fail('Saving an existing lifecycle event must be rejected.');
        } catch (LogicException $exception) {
            $this->assertSame('Authorization history is append-only.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Authorization history is append-only.');
        (new InsurancePriorAuthorizationEvent)->delete();
    }

    public function test_authorization_resources_expose_manual_decisions_without_sensitive_reference(): void
    {
        $event = (new InsurancePriorAuthorizationEvent)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 4,
            'version' => 2,
            'from_status' => 'requested',
            'to_status' => 'pending',
            'event_type' => 'insurer_acknowledged',
            'actor_id' => 7,
            'occurred_at' => '2026-11-20 12:00:00',
        ]);
        $decision = (new InsurancePriorAuthorizationDecision)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 8,
            'decision_type' => 'partially_approved',
            'authorized_amount' => '80.25',
            'currency' => 'USD',
            'decision_at' => '2026-11-20 12:00:00',
            'recorded_by_user_id' => 7,
            'insurer_reference_ciphertext' => 'encrypted-secret',
        ]);
        $authorization = (new InsurancePriorAuthorization)->setDateFormat('Y-m-d H:i:s')->forceFill([
            'id' => 12,
            'patient_id' => 3,
            'request_identifier' => 'request-id',
            'request_date' => '2026-11-20',
            'requested_amount' => '125.30',
            'currency' => 'USD',
            'status' => 'pending',
        ]);
        $authorization->setRelation('events', new Collection([$event]));
        $authorization->setRelation('decisions', new Collection([$decision]));

        $data = (new InsurancePriorAuthorizationResource($authorization))->resolve(Request::create('/'));

        $this->assertSame('125.30', $data['requested_amount']);
        $this->assertSame('80.25', $data['decisions'][0]['authorized_amount']);
        $this->assertSame(true, $data['decisions'][0]['staff_entered']);
        $this->assertSame('insurer_acknowledged', $data['events'][0]['event_type']);
        $this->assertArrayNotHasKey('insurer_reference_ciphertext', $data['decisions'][0]);

        $eventData = (new InsurancePriorAuthorizationEventResource($event))->resolve(Request::create('/'));
        $this->assertSame('2026-11-20T12:00:00+00:00', $eventData['occurred_at']);
    }
}
