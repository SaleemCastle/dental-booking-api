const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const collection = JSON.parse(fs.readFileSync(path.join(__dirname, '../../docs/postman/dental-booking.postman_collection.json'), 'utf8'));
const environment = JSON.parse(fs.readFileSync(path.join(__dirname, '../../docs/postman/local.postman_environment.json'), 'utf8'));
const requests = collection.item.flatMap(folder => folder.item.map(item => item.request));
const scripts = Object.fromEntries(collection.event.map(event => [event.listen, event.script.exec.join('\n')]));
const skipped = Symbol('skipped');

function contextFor(method, route, overrides = {}) {
    const definition = requests.find(request => request.method === method && '/' + request.url.path.join('/') === route);
    assert.ok(definition, `${method} ${route} exists`);
    const request = structuredClone(definition);
    if (request.body && request.body.mode === 'formdata') {
        request.body.formdata.each = callback => request.body.formdata.forEach(callback);
    }
    const values = new Map(environment.values.map(variable => [variable.key, variable.value]));
    for (const [key, value] of Object.entries(overrides)) values.set(key, value);
    const local = new Map();
    const headers = new Map();
    const notices = [];
    const failures = [];
    const context = {
        console: { warn: message => notices.push(message) },
        pm: {
            request: {
                method,
                url: {
                    getPath: () => route,
                    query: { each: callback => request.url.query.forEach(callback) }
                },
                body: request.body,
                headers: { upsert: header => headers.set(header.key, header.value) }
            },
            variables: {
                get: key => local.has(key) ? local.get(key) : values.get(key),
                set: (key, value) => local.set(key, value),
                replaceIn: () => 'e9a92467-981a-46db-a6fe-d9bc0e9bc215'
            },
            environment: {
                get: key => values.get(key),
                set: (key, value) => values.set(key, value),
                unset: key => values.delete(key)
            },
            execution: { skipRequest: () => { throw skipped; } },
            cookies: { get: () => undefined },
            test: (name, callback) => {
                try { callback(); } catch (error) { failures.push(`${name}: ${error.message}`); }
            },
            expect: value => ({
                to: {
                    equal: expected => assert.equal(value, expected),
                    have: { all: { keys: (...keys) => assert.deepEqual(Object.keys(value).sort(), keys.sort()) } },
                    be: {
                        within: (minimum, maximum) => assert.ok(value >= minimum && value <= maximum),
                        a: type => assert.equal(typeof value, type)
                    }
                }
            })
        }
    };
    return { context, values, local, headers, notices, failures, request };
}

function runPreRequest(state) {
    try {
        vm.runInNewContext(scripts.prerequest, { ...state.context });
        return true;
    } catch (error) {
        if (error === skipped) return false;
        throw error;
    }
}

function respond(state, data, code = 200) {
    const body = {
        success: code >= 200 && code < 300,
        statusCode: code,
        message: 'Test response',
        code: null,
        data,
        errors: null,
        meta: null,
        requestId: 'response-request-id'
    };
    state.context.pm.response = { code, json: () => body };
    vm.runInNewContext(scripts.test, { ...state.context });
    assert.deepEqual(state.failures, []);
}

test('public health runs without a token, protected reads require one', () => {
    assert.equal(runPreRequest(contextFor('GET', '/api/health')), true);
    assert.equal(runPreRequest(contextFor('GET', '/api/audit-logs')), false);
    assert.equal(runPreRequest(contextFor('GET', '/api/audit-logs', { access_token: 'test-token' })), true);
});

test('document upload requires a selected file and checks optional form variables', () => {
    const state = contextFor('POST', '/api/patients/{{patient_id}}/documents', { access_token: 'token', allow_writes: 'true', patient_id: '42' });
    assert.equal(runPreRequest(state), false);
    state.request.body.formdata.find(field => field.type === 'file').src = '/local/test.pdf';
    assert.equal(runPreRequest(state), true);
    state.request.body.formdata.find(field => field.key === 'clinical_note_id').disabled = false;
    assert.equal(runPreRequest(state), false);
    state.values.set('clinical_note_id', '7');
    assert.equal(runPreRequest(state), true);
    respond(state, { document: { id: 8 } }, 201);
    assert.equal(state.values.get('document_id'), '8');
});

test('successful binary downloads skip JSON parsing while error responses retain envelope assertions', () => {
    const state = contextFor('GET', '/api/patients/{{patient_id}}/documents/{{document_id}}/download');
    state.context.pm.response = { code: 200, json: () => { throw new Error('Binary file'); } };
    vm.runInNewContext(scripts.test, { ...state.context });
    assert.deepEqual(state.failures, []);
    assert.throws(() => respond(state, null, 403));
});

test('clinic selection is opt-in and membership discovery remains authenticated', () => {
    const selected = contextFor('GET', '/api/user', { access_token: 'test-token', tenant_id: '2' });
    assert.equal(runPreRequest(selected), true);
    assert.equal(selected.headers.get('X-Tenant-Id'), '2');
    const defaultClinic = contextFor('GET', '/api/user', { access_token: 'test-token' });
    assert.equal(runPreRequest(defaultClinic), true);
    assert.equal(defaultClinic.headers.has('X-Tenant-Id'), false);
    assert.equal(runPreRequest(contextFor('GET', '/api/tenants')), false);
    assert.equal(runPreRequest(contextFor('GET', '/api/tenants', { access_token: 'test-token' })), true);
});

test('domain writes, session requests and auth cleanup are skipped by default', () => {
    for (const [method, route] of [
        ['POST', '/api/patients'],
        ['PATCH', '/api/patients/{{patient_id}}'],
        ['DELETE', '/api/patients/{{patient_id}}'],
        ['DELETE', '/api/auth/token'],
        ['GET', '/sanctum/csrf-cookie'],
        ['POST', '/logout']
    ]) {
        assert.equal(runPreRequest(contextFor(method, route, { access_token: 'test-token', patient_id: '42' })), false);
    }
});

test('token creation requires credentials and JSON-encodes special passwords', () => {
    assert.equal(runPreRequest(contextFor('POST', '/api/auth/token')), false);
    const password = 'Secret "quotes" \\slashes\nnewline';
    const state = contextFor('POST', '/api/auth/token', { email: 'tester@example.test', password });
    assert.equal(runPreRequest(state), true);
    const raw = state.request.body.raw.replace(/\{\{([a-zA-Z0-9_]+)\}\}/g, (match, key) => state.context.pm.variables.get(key));
    assert.equal(JSON.parse(raw).password, password);
    respond(state, { access_token: 'captured-token' }, 201);
    assert.equal(state.values.get('access_token'), 'captured-token');
});

test('literal credential bodies do not require unused environment credentials', () => {
    for (const route of ['/api/users', '/api/auth/token', '/login', '/register']) {
        const state = contextFor('POST', route, {
            allow_writes: 'true', access_token: 'test-token',
            use_session_auth: ['/login', '/register'].includes(route) ? 'true' : 'false'
        });
        state.request.body.raw = JSON.stringify({
            name: 'Postman Test User', email: 'literal@example.test',
            password: 'Test-password-123', password_confirmation: 'Test-password-123'
        });
        assert.equal(state.values.get('email'), '');
        assert.equal(state.values.get('password'), '');
        assert.equal(state.values.get('new_user_password'), '');
        assert.equal(runPreRequest(state), true, `${route} accepts literal credentials`);
    }
});

test('password templates still require their source variables', () => {
    for (const [route, secret] of [['/api/users', 'new_user_password'], ['/api/auth/token', 'password']]) {
        const state = contextFor('POST', route, {
            allow_writes: 'true', access_token: 'test-token', email: 'template@example.test'
        });
        assert.equal(runPreRequest(state), false);
        assert.ok(state.notices.some(message => message.includes(secret)));
        state.values.set(secret, 'Test-password-123');
        assert.equal(runPreRequest(state), true);
    }
});

test('literal create-user bodies retain write and authentication guards', () => {
    for (const [overrides, expected] of [
        [{ access_token: 'test-token' }, false],
        [{ allow_writes: 'true' }, false],
        [{ allow_writes: 'true', access_token: 'test-token' }, true]
    ]) {
        const state = contextFor('POST', '/api/users', overrides);
        state.request.body.raw = JSON.stringify({ name: 'Test User', email: 'literal@example.test', password: 'Test-password-123' });
        assert.equal(runPreRequest(state), expected);
    }
});

test('explicitly enabled creates generate defaults but skip missing relationship IDs', () => {
    const overrides = { allow_writes: 'true', access_token: 'test-token' };
    const missing = contextFor('POST', '/api/appointments', overrides);
    assert.equal(runPreRequest(missing), false);
    assert.ok(missing.notices.some(message => message.includes('patient_id') && message.includes('dentist_id')));
    const state = contextFor('POST', '/api/appointments', { ...overrides, patient_id: '42', dentist_id: '7' });
    assert.equal(runPreRequest(state), true);
    assert.ok(Date.parse(state.local.get('appointment_start')) > Date.now());
    assert.match(state.local.get('sample_email'), /^postman\..+@example\.test$/);
    assert.ok(state.headers.has('X-Request-Id'));
});

test('disabled filters do not require variables, enabled filters do', () => {
    const state = contextFor('GET', '/api/audit-logs', { access_token: 'test-token' });
    assert.equal(runPreRequest(state), true);
    state.request.url.query.find(parameter => parameter.key === 'request_id').disabled = false;
    assert.equal(runPreRequest(state), false);
    state.values.set('audit_request_id', 'originating-request');
    assert.equal(runPreRequest(state), true);
});

test('session mode sends origin and decoded CSRF cookie', () => {
    const state = contextFor('POST', '/login', {
        use_session_auth: 'true', email: 'tester@example.test', password: 'test-password'
    });
    state.context.pm.cookies.get = name => name === 'XSRF-TOKEN' ? 'csrf%3Dtoken' : undefined;
    assert.equal(runPreRequest(state), true);
    assert.equal(state.headers.get('Origin'), 'http://localhost:3000');
    assert.equal(state.headers.get('X-XSRF-TOKEN'), 'csrf=token');
});

test('only successful creates capture domain IDs, not list reads or failed writes', () => {
    const state = contextFor('POST', '/api/patients');
    respond(state, { patient: { id: 42 } }, 201);
    assert.equal(state.values.get('patient_id'), '42');
    const list = contextFor('GET', '/api/patients');
    respond(list, { patients: [{ id: 99 }] });
    assert.equal(list.values.get('patient_id'), '');
    const failure = contextFor('POST', '/api/patients');
    assert.throws(() => respond(failure, { patient: { id: 100 } }, 422));
    assert.equal(failure.values.get('patient_id'), '');
    const plan = contextFor('POST', '/api/patients/{{patient_id}}/treatment-plans');
    respond(plan, { treatmentPlan: { id: 43 } }, 201);
    assert.equal(plan.values.get('treatment_plan_id'), '43');
    const item = contextFor('POST', '/api/treatment-plans/{{treatment_plan_id}}/items');
    respond(item, { treatmentPlanItem: { id: 44 } }, 201);
    assert.equal(item.values.get('item_id'), '44');
    const planRead = contextFor('GET', '/api/treatment-plans/{{treatment_plan_id}}');
    respond(planRead, { treatmentPlan: { id: 99 } });
    assert.equal(planRead.values.get('treatment_plan_id'), '');
});

test('current user and audit list capture selector and correlation variables', () => {
    const user = contextFor('GET', '/api/user');
    respond(user, { id: 7 });
    assert.equal(user.values.get('current_user_id'), '7');
    assert.equal(user.values.get('assignee_id'), '7');
    const audit = contextFor('GET', '/api/audit-logs');
    respond(audit, { auditLogs: [{ id: 281, request_metadata: { request_id: 'originating-mutation' } }] });
    assert.equal(audit.values.get('audit_log_id'), '281');
    assert.equal(audit.values.get('audit_request_id'), 'originating-mutation');
});

test('intentional token revocation clears the stored bearer token', () => {
    const state = contextFor('DELETE', '/api/auth/token', { allow_auth_cleanup: 'true', access_token: 'test-token' });
    assert.equal(runPreRequest(state), true);
    respond(state, null);
    assert.equal(state.values.has('access_token'), false);
});
