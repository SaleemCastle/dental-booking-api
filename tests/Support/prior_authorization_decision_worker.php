<?php

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $input = json_decode(base64_decode((string) getenv('PRIOR_AUTH_DECISION_WORKER'), true), true, flags: JSON_THROW_ON_ERROR);
    if (! preg_match('/^[a-z0-9_]{1,63}$/', $input['application'])) {
        throw new InvalidArgumentException('Invalid worker application name.');
    }
    Illuminate\Support\Facades\DB::statement("SET application_name TO '{$input['application']}'");
    Illuminate\Support\Facades\DB::statement("SET lock_timeout TO '60000ms'");
    $tenant = App\Models\Tenant::query()->findOrFail($input['tenant_id']);
    $result = app(App\Tenancy\TenantContext::class)->run($tenant, function () use ($input) {
        $patient = App\Models\Patient::query()->findOrFail($input['patient_id']);
        $authorization = App\Models\InsurancePriorAuthorization::query()->findOrFail($input['authorization_id']);
        $actor = App\Models\User::query()->findOrFail($input['actor_id']);
        $authorization = app(App\Services\InsurancePriorAuthorizationWorkflow::class)->decision($patient, $authorization, [
            'expected_version' => $input['expected_version'], 'decision_type' => $input['decision_type'], 'communication_channel' => 'phone',
            'decision_at' => '2026-11-19T10:00:00Z', 'source_provenance' => 'Synthetic concurrency observation',
        ], $actor);

        return ['status' => $authorization->status, 'version' => $authorization->lock_version];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo json_encode(['http_status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage().PHP_EOL);
    exit(1);
}
