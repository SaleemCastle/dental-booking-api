# Vercel and Render authentication

This application uses Sanctum session cookies: `/login` is protected by CSRF middleware and returns a user, not a bearer token.

Sanctum SPA authentication requires a shared parent domain, as described in the [Laravel 10 documentation](https://laravel.com/docs/10.x/sanctum#spa-authentication). For example, configure `app.example.com` on Vercel and `api.example.com` on Render. Replace these examples with domains you control.

The default `your-app.vercel.app` and `your-api.onrender.com` addresses do not share a parent domain. Frontend JavaScript cannot read the API's `XSRF-TOKEN` cookie, and `SameSite=Lax` prevents cookies being sent on cross-site login requests. Setting `SESSION_SAME_SITE=none` with `SESSION_SECURE_COOKIE=true` alone does not solve cookie access or browser third-party-cookie restrictions.

## Render environment

For the shared-domain setup, set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com
FRONTEND_URLS=https://app.example.com
SANCTUM_STATEFUL_DOMAINS=app.example.com
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
```

`FRONTEND_URLS` is the comma-separated CORS origin allowlist; include schemes and any ports, with no trailing slash. `SANCTUM_STATEFUL_DOMAINS` contains frontend hosts and any ports, without schemes or paths. Configure both: they serve different purposes. Keep `APP_KEY` stable across deploys and instances.

Redeploy after changing the environment. The Docker startup script clears and rebuilds Laravel's configuration cache. For an existing running process, clear stale configuration with `php artisan config:clear` and restart the service. Clear old browser cookies after changing their domain or cookie settings.

## Frontend requests

With a current Axios version supporting `withXSRFToken`, use the API origin as the base URL (without `/api` for the authentication routes):

```js
import axios from 'axios';

const api = axios.create({
  baseURL: 'https://api.example.com',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
});

await api.get('/sanctum/csrf-cookie');
await api.post('/login', { email, password });
const response = await api.get('/api/user');
```

Await CSRF initialization before login. Axios reads the shared `XSRF-TOKEN` cookie and sends it as `X-XSRF-TOKEN`. With fetch, use `credentials: 'include'` on every request and explicitly set `X-XSRF-TOKEN` to the URL-decoded cookie value on modifying requests.

## Verify in browser developer tools

1. The CSRF initialization request succeeds and sets both `XSRF-TOKEN` and the session cookie for `.example.com`, without blocked-cookie warnings. This application's CSRF endpoint returns JSON with status 200.
2. `POST /login` includes both cookies and the `X-XSRF-TOKEN` header.
3. The login response succeeds and `/api/user` returns the authenticated user with credentials enabled.

If both cookies and the header are present but the request still returns 419, check that the session store survives between requests and is shared by all backend instances. The default file session driver uses local container storage; deployments replace that storage and multiple instances do not share it. Also check that all instances use the same `APP_KEY`.

Keep CSRF verification enabled for `/login` and the other session authentication routes.
