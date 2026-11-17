<?php

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $input = json_decode(base64_decode((string) getenv('CLAIM_SUBMISSION_WORKER'), true), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/^[a-z0-9_]{1,63}$/', $input['application'])) {
        throw new InvalidArgumentException('Invalid worker application name.');
    }
    Illuminate\Support\Facades\DB::statement("SET application_name TO '{$input['application']}'");
    Illuminate\Support\Facades\DB::statement("SET lock_timeout TO '10000ms'");

    $tenant = App\Models\Tenant::query()->findOrFail($input['tenant_id']);
    $context = app(App\Tenancy\TenantContext::class);
    $result = $context->run($tenant, function () use ($input) {
        $patient = App\Models\Patient::query()->findOrFail($input['patient_id']);
        $claim = App\Models\InsuranceClaim::query()->findOrFail($input['claim_id']);
        $actor = App\Models\User::query()->findOrFail($input['actor_id']);
        $result = app(App\Services\InsuranceClaimWorkflow::class)->submit($patient, $claim, [
            'expected_version' => $input['expected_version'], 'channel' => $input['channel'],
        ], $input['key'], $actor);

        return ['http_status' => $result['replayed'] ? 200 : 201, 'submission_id' => $result['submission']->id,
            'sequence' => $result['submission']->sequence, 'replayed' => $result['replayed'], 'claim_status' => $result['claim']->status];
    });

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
    echo json_encode(['http_status' => $exception->getStatusCode(), 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
