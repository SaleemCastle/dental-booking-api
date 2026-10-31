# PostgreSQL deployment

Migrations and database integrity guards support PostgreSQL, MySQL and SQLite. CI runs the test suite against all three databases.

For Render, use the existing Dockerfile (it installs `pdo_pgsql`) and set `DB_CONNECTION=pgsql` and `DATABASE_URL` to the Render PostgreSQL connection URL. Keep the URL in Render's environment settings. Set `APP_KEY` to a persistent Laravel application key and `APP_DEBUG=false`.

Run `php artisan migrate --force` during deployment. Existing invoices require `BILLING_LEGACY_CURRENCY` to contain their verified three-letter currency before the billing migration runs. Migrations refuse inconsistent tenant links and financial history; reconcile the affected records before retrying.

To run PostgreSQL tests, point `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` at a dedicated, disposable test database, then run `vendor/bin/phpunit`. The tests rebuild its schema. With no database environment overrides, PHPUnit uses SQLite in memory.

The default suite requires `pdo_sqlite`. On Windows, if the SQLite DLL is installed but disabled in the CLI configuration, run `php -d extension=pdo_sqlite vendor/bin/phpunit`.
