<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

class PostmanExport
{
    private array $variables = [
        'base_url' => 'http://localhost:8000',
        'frontend_url' => 'http://localhost:3000',
        'tenant_id' => '',
        'email' => '',
        'password' => '',
        'access_token' => '',
        'allow_writes' => 'false',
        'allow_auth_cleanup' => 'false',
        'use_session_auth' => 'false',
        'new_user_password' => '',
        'sample_email' => '',
        'appointment_start' => '',
        'due_date' => '',
        'role_slug' => '',
        'invoice_number' => '',
        'current_user_id' => '',
        'assignee_id' => '',
        'permission_id' => '',
    ];

    public function __construct(private readonly array $document) {}

    public function build(): array
    {
        $folders = [];

        foreach ($this->document['paths'] as $path => $definition) {
            foreach ($definition as $method => $operation) {
                if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                $folder = $path === '/api/auth/token' ? 'Authentication - Bearer' : ($operation['tags'][0] ?? 'Other');
                $folders[$folder][] = $this->request($path, $method, $definition, $operation);
            }
        }

        $items = [];
        foreach (['Authentication - Bearer', 'Auth', 'Health'] as $folder) {
            if (isset($folders[$folder])) {
                $items[] = ['name' => $folder, 'item' => $folders[$folder]];
                unset($folders[$folder]);
            }
        }
        foreach ($folders as $folder => $requests) {
            $items[] = ['name' => $folder, 'item' => $requests];
        }

        $collection = [
            'info' => [
                '_postman_id' => '587df005-e259-4a96-8f95-0364f57ca6ce',
                'name' => 'Dental Booking Backend API '.$this->document['info']['version'],
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                'description' => 'Generated from docs/openapi.yaml. Import the matching environment, set email/password, then create a bearer token. All documented operations are included. Domain writes are skipped unless allow_writes=true; auth cleanup requires allow_auth_cleanup=true. This is an interactive request catalog, not an ordered CRUD scenario. Use a disposable database. See docs/postman.md.',
            ],
            'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{access_token}}', 'type' => 'string']]],
            'event' => [
                $this->event('prerequest', $this->preRequestScript()),
                $this->event('test', $this->responseScript()),
            ],
            'item' => $items,
            'variable' => array_map(fn ($key, $value) => ['key' => $key, 'value' => $value, 'type' => 'string'], array_keys($this->variables), array_values($this->variables)),
        ];
        $environment = [
            'id' => '50e8833a-6211-443e-9351-91b0d439c590',
            'name' => 'Dental Booking - Local',
            'values' => array_map(fn ($key, $value) => [
                'key' => $key,
                'value' => $value,
                'type' => in_array($key, ['password', 'new_user_password', 'access_token'], true) ? 'secret' : 'default',
                'enabled' => true,
            ], array_keys($this->variables), array_values($this->variables)),
            '_postman_variable_scope' => 'environment',
        ];

        return ['collection' => $collection, 'environment' => $environment];
    }

    private function request(string $path, string $method, array $definition, array $operation): array
    {
        $urlPath = preg_replace_callback('/\{([^}]+)\}/', fn ($matches) => '{{'.$this->variable($matches[1].'_id').'}}', $path);
        $query = [];
        foreach (array_merge($definition['parameters'] ?? [], $operation['parameters'] ?? []) as $parameter) {
            $parameter = $this->expand($parameter);
            if (($parameter['in'] ?? '') !== 'query') {
                continue;
            }
            $name = $parameter['name'];
            $schema = $this->expand($parameter['schema'] ?? []);
            $value = $schema['default'] ?? $schema['example'] ?? $schema['enum'][0] ?? '';
            if (str_ends_with($name, '_id') && ! str_starts_with($path, '/api/audit-logs')) {
                $value = '{{'.$this->variable($name).'}}';
            }
            if (in_array($name, ['active', 'assigned_to_me', 'overdue', 'due_today'], true)) {
                $value = '1';
            }
            if (in_array($name, ['actor_id', 'action', 'resource_type', 'resource_id', 'request_id', 'from', 'to'], true) && str_starts_with($path, '/api/audit-logs')) {
                $value = '{{'.$this->variable('audit_'.$name).'}}';
            }
            $query[] = [
                'key' => $name, 'value' => (string) $value,
                'disabled' => ! ($parameter['required'] ?? false) && ! in_array($name, ['page', 'per_page'], true),
                'description' => $parameter['description'] ?? 'Optional filter; enable its checkbox to send it.',
            ];
        }
        $rawUrl = '{{base_url}}'.$urlPath;
        $enabledQuery = array_filter($query, fn ($parameter) => ! $parameter['disabled']);
        if ($enabledQuery !== []) {
            $rawUrl .= '?'.implode('&', array_map(fn ($parameter) => $parameter['key'].'='.$parameter['value'], $enabledQuery));
        }
        $request = [
            'method' => strtoupper($method),
            'header' => [['key' => 'Accept', 'value' => 'application/json']],
            'url' => ['raw' => $rawUrl, 'host' => ['{{base_url}}'], 'path' => explode('/', ltrim($urlPath, '/')), 'query' => $query],
            'description' => trim(($operation['description'] ?? '')."\n\n".strtoupper($method).' '.$path."\n".'Domain writes require allow_writes=true. Missing required variables skip requests rather than selecting real records automatically.'),
        ];
        if (($operation['security'] ?? null) === [] || $path === '/logout') {
            $request['auth'] = ['type' => 'noauth'];
        }
        $body = $this->body($path, $method, $operation);
        if ($body !== null) {
            $raw = self::json($body);
            foreach (['password', 'new_user_password'] as $secret) {
                $raw = str_replace('"{{'.$secret.'}}"', '{{'.$secret.'_json}}', $raw);
            }
            $request['header'][] = ['key' => 'Content-Type', 'value' => 'application/json'];
            $request['body'] = ['mode' => 'raw', 'raw' => $raw, 'options' => ['raw' => ['language' => 'json']]];
        }

        return ['name' => strtoupper($method).' '.($operation['summary'] ?? $path), 'request' => $request, 'response' => []];
    }

    private function body(string $path, string $method, array $operation): ?array
    {
        if (! in_array($method, ['post', 'put', 'patch'], true)) {
            return null;
        }
        if ($path === '/api/auth/token' || $path === '/login') {
            return ['email' => '{{email}}', 'password' => '{{password}}', ...($path === '/api/auth/token' ? ['token_name' => 'postman'] : [])];
        }
        if ($path === '/register') {
            return ['name' => 'Postman Test User', 'email' => '{{sample_email}}', 'password' => '{{new_user_password}}', 'password_confirmation' => '{{new_user_password}}'];
        }
        if (str_starts_with($path, '/api/users') && ! str_ends_with($path, '/roles')) {
            return $method === 'post'
                ? ['name' => 'Postman Test User', 'email' => '{{sample_email}}', 'password' => '{{new_user_password}}']
                : ['name' => 'Postman Updated User'];
        }
        if (str_starts_with($path, '/api/messages')) {
            return ['sender_id' => '{{'.$this->variable('patient_id').'}}', 'receiver_id' => '{{'.$this->variable('receiver_patient_id').'}}', 'message_text' => 'Postman test message'];
        }
        if (str_starts_with($path, '/api/referrals')) {
            return ['patient_id' => '{{'.$this->variable('patient_id').'}}', 'dentist_id' => '{{'.$this->variable('dentist_id').'}}', 'referred_to_email' => 'referral@example.test', 'referral_date' => '{{due_date}}', 'referral_notes' => 'Postman test referral'];
        }
        $requestBody = $this->expand($operation['requestBody'] ?? []);
        if (! isset($requestBody['content']['application/json']['schema'])) {
            return null;
        }
        $schema = $this->expand($requestBody['content']['application/json']['schema']);
        if (str_ends_with($path, '/status')) {
            return ['status' => 'in_progress'];
        }
        if (str_ends_with($path, '/cancel')) {
            return ['cancellation_reason' => 'Patient requested cancellation.'];
        }
        if ($method !== 'post' && (str_ends_with($path, '}') || str_ends_with($path, '/update')) && ! str_ends_with($path, '/roles')) {
            $field = match (explode('/', $path)[2] ?? '') {
                'patients' => 'notes', 'appointments', 'treatments', 'tasks', 'roles' => 'description',
                'clinical-notes' => 'note_text', 'dentists' => 'specialization',
                'invoices' => 'amount_due', 'payments' => 'amount',
                default => null,
            };
            if ($field !== null) {
                return [$field => in_array($field, ['amount_due', 'amount'], true) ? 100 : 'Postman updated test data'];
            }
        }

        return $this->sample($schema);
    }

    private function expand(array $schema): array
    {
        if (isset($schema['$ref'])) {
            $target = $this->document;
            foreach (explode('/', substr($schema['$ref'], 2)) as $segment) {
                $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
                $target = $target[$segment];
            }

            return $this->expand($target);
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $part = $this->expand($part);
            $schema['properties'] = array_merge($schema['properties'] ?? [], $part['properties'] ?? []);
            $schema['required'] = array_values(array_unique(array_merge($schema['required'] ?? [], $part['required'] ?? [])));
        }
        foreach ($schema['anyOf'] ?? [] as $part) {
            $schema['required'] = array_values(array_unique(array_merge($schema['required'] ?? [], $part['required'] ?? [])));
            break;
        }
        unset($schema['allOf']);

        return $schema;
    }

    private function sample(array $schema, ?string $name = null): mixed
    {
        $schema = $this->expand($schema);
        if ($name !== null) {
            if (str_ends_with($name, '_id')) {
                return '{{'.$this->variable($name).'}}';
            }
            if ($name === 'appointment_date_time') {
                return '{{appointment_start}}';
            }
            if (in_array($name, ['due_date', 'referral_date'], true)) {
                return '{{due_date}}';
            }
            if ($name === 'slug') {
                return '{{role_slug}}';
            }
            if ($name === 'invoice_number') {
                return '{{invoice_number}}';
            }
        }
        if (($schema['format'] ?? '') === 'email') {
            return '{{sample_email}}';
        }
        if (isset($schema['properties'])) {
            $result = [];
            $fields = $schema['required'] ?? [];
            if ($fields === []) {
                $fields = array_keys($schema['properties']);
            }
            foreach ($fields as $field) {
                $property = $this->expand($schema['properties'][$field] ?? []);
                if (($property['readOnly'] ?? false) || ($property['nullable'] ?? false)) {
                    continue;
                }
                $result[$field] = $this->sample($property, $field);
            }

            return $result;
        }
        if (($schema['type'] ?? '') === 'array') {
            $singular = $name !== null && str_ends_with($name, '_ids') ? substr($name, 0, -1) : null;

            return [$this->sample($schema['items'] ?? [], $singular)];
        }

        return $schema['example'] ?? $schema['default'] ?? $schema['enum'][0] ?? match ($schema['type'] ?? 'string') {
            'integer', 'number' => max(1, $schema['minimum'] ?? 1),
            'boolean' => false,
            default => 'Postman test data',
        };
    }

    private function variable(string $name): string
    {
        $this->variables[$name] ??= '';

        return $name;
    }

    private function event(string $listen, string $script): array
    {
        return ['listen' => $listen, 'script' => ['type' => 'text/javascript', 'exec' => explode("\n", $script)]];
    }

    private function preRequestScript(): string
    {
        return <<<'JS'
const path = pm.request.url.getPath();
const method = pm.request.method;
const sessionMode = pm.variables.get('use_session_auth') === 'true';
const tenantId = pm.variables.get('tenant_id');
if (tenantId && path.startsWith('/api/')) {
    pm.request.headers.upsert({key: 'X-Tenant-Id', value: String(tenantId)});
}
const browserPaths = ['/sanctum/csrf-cookie', '/login', '/register', '/logout'];
if (browserPaths.includes(path) && !sessionMode) {
    console.warn('Skipped browser-session request; use_session_auth is false.');
    pm.execution.skipRequest();
}
const cleanup = path === '/logout' || (path === '/api/auth/token' && method === 'DELETE');
if (cleanup && pm.variables.get('allow_auth_cleanup') !== 'true') {
    console.warn('Skipped auth cleanup; allow_auth_cleanup must be true.');
    pm.execution.skipRequest();
}
const authWrite = cleanup || path === '/login' || path === '/api/auth/token';
if (!['GET', 'HEAD', 'OPTIONS'].includes(method) && !authWrite && pm.variables.get('allow_writes') !== 'true') {
    console.warn('Skipped domain write; allow_writes must be true. Use a disposable database.');
    pm.execution.skipRequest();
}
const suffix = Date.now().toString(36) + Math.floor(Math.random() * 16777215).toString(36);
const defaults = {
    sample_email: 'postman.' + suffix + '@example.test',
    appointment_start: new Date(Date.now() + 86400000).toISOString(),
    due_date: new Date(Date.now() + 259200000).toISOString().slice(0, 10),
    role_slug: 'postman-' + suffix,
    invoice_number: 'PM-' + suffix
};
for (const [key, value] of Object.entries(defaults)) {
    if (!pm.variables.get(key)) pm.variables.set(key, value);
}
for (const key of ['password', 'new_user_password']) {
    pm.variables.set(key + '_json', JSON.stringify(pm.variables.get(key) || ''));
}
const texts = [pm.request.url.getPath()];
pm.request.url.query.each(parameter => { if (!parameter.disabled) texts.push(parameter.value || ''); });
if (pm.request.body && pm.request.body.raw) texts.push(pm.request.body.raw);
const required = [...new Set((texts.join('\n').match(/\{\{[a-zA-Z0-9_]+\}\}/g) || []).map(value => value.slice(2, -2)))];
for (const key of ['password', 'new_user_password']) {
    if (required.includes(key + '_json')) required.push(key);
}
const missing = required.filter(key => !String(pm.variables.get(key) || '').trim());
if (missing.length) {
    console.warn('Skipped request; set variables: ' + [...new Set(missing)].join(', '));
    pm.execution.skipRequest();
}
const publicRequest = browserPaths.includes(path) || path === '/api/health' || (path === '/api/auth/token' && method === 'POST');
if (!publicRequest && !sessionMode && !pm.variables.get('access_token')) {
    console.warn('Skipped protected request; create a bearer token first.');
    pm.execution.skipRequest();
}
if (sessionMode) {
    pm.request.headers.upsert({key: 'Origin', value: pm.variables.get('frontend_url')});
    const csrf = pm.cookies.get('XSRF-TOKEN');
    if (csrf) pm.request.headers.upsert({key: 'X-XSRF-TOKEN', value: decodeURIComponent(csrf)});
}
pm.request.headers.upsert({key: 'X-Request-Id', value: pm.variables.replaceIn('{{$guid}}')});
JS;
    }

    private function responseScript(): string
    {
        return <<<'JS'
pm.test('Request succeeded', () => pm.expect(pm.response.code).to.be.within(200, 299));
let body;
try { body = pm.response.json(); } catch (error) {
    pm.test('Response is JSON', () => { throw new Error('Expected an API JSON response.'); });
}
if (body) {
    pm.test('Standard API envelope', () => {
        pm.expect(body).to.have.all.keys('success', 'statusCode', 'message', 'code', 'data', 'errors', 'meta', 'requestId');
        pm.expect(body.statusCode).to.equal(pm.response.code);
        pm.expect(body.success).to.equal(pm.response.code >= 200 && pm.response.code < 300);
        pm.expect(body.requestId).to.be.a('string');
    });
    if (pm.response.code >= 200 && pm.response.code < 300 && body.data) {
        if (body.data.access_token) pm.environment.set('access_token', body.data.access_token);
        const path = pm.request.url.getPath();
        const entities = {
            patients: ['patient', 'patient_id'], dentists: ['dentist', 'dentist_id'],
            appointments: ['appointment', 'appointment_id'], treatments: ['treatment', 'treatment_id'],
            'clinical-notes': ['clinicalNote', 'clinical_note_id'], tasks: ['task', 'task_id'],
            roles: ['role', 'role_id'], users: ['user', 'user_id'], invoices: ['invoice', 'invoice_id'],
            payments: ['payment', 'payment_id'], messages: ['messageItem', 'message_id'], referrals: ['referral', 'referral_id']
        };
        const entity = entities[path.replace(/^\/api\//, '')];
        if (pm.request.method === 'POST' && entity && body.data[entity[0]]) {
            pm.environment.set(entity[1], String(body.data[entity[0]].id));
        }
        if (path === '/api/user' && body.data.id) {
            pm.environment.set('current_user_id', String(body.data.id));
            if (!pm.environment.get('assignee_id')) pm.environment.set('assignee_id', String(body.data.id));
        }
        if (path === '/api/audit-logs' && body.data.auditLogs && body.data.auditLogs.length) {
            const entry = body.data.auditLogs[0];
            pm.environment.set('audit_log_id', String(entry.id));
            if (entry.request_metadata && entry.request_metadata.request_id) {
                pm.environment.set('audit_request_id', entry.request_metadata.request_id);
            }
        }
    }
    if (pm.response.code >= 200 && pm.response.code < 300 && pm.request.method === 'DELETE' && pm.request.url.getPath() === '/api/auth/token') {
        pm.environment.unset('access_token');
    }
}
JS;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$export = (new PostmanExport(Yaml::parseFile(__DIR__.'/../docs/openapi.yaml')))->build();
$files = ['collection' => 'dental-booking.postman_collection.json', 'environment' => 'local.postman_environment.json'];
$mode = $argv[1] ?? '--stdout';
if ($mode === '--stdout') {
    echo PostmanExport::json($export);
    exit;
}
if (! in_array($mode, ['--write', '--check'], true)) {
    fwrite(STDERR, "Usage: php tools/postman.php [--stdout|--write|--check]\n");
    exit(1);
}
foreach ($files as $key => $name) {
    $path = __DIR__.'/../docs/postman/'.$name;
    $json = PostmanExport::json($export[$key]);
    if ($mode === '--write') {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), recursive: true);
        }
        file_put_contents($path, $json);
    } elseif (! is_file($path) || file_get_contents($path) !== $json) {
        fwrite(STDERR, "Postman export is stale: {$name}\n");
        exit(1);
    }
}
echo $mode === '--write' ? "Postman files generated.\n" : "Postman files match the OpenAPI contract.\n";
