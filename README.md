# NguzoPay API

Laravel 12 API behind the NguzoPay portal (`../frontend`). It holds identity, businesses (merchants), staff, KYC, the audit log and the secrets vault. Architecture: [`../docs/02-development/architecture.md`](../docs/02-development/architecture.md).

## Quick start (SQLite, no services needed)

```bash
composer setup                 # install, .env, key, migrate
php artisan vault:key          # paste into VAULT_KEY_V1 in .env
php artisan db:seed            # demo owner + two admins (see database/seeders)
php artisan serve --port=8000
```

SMS and emails are sent from the queue, so run a worker next to the server: `php artisan queue:work --queue=sms,default` (or `composer dev`, which starts server, worker and logs together). SMS codes go to `storage/logs/laravel.log` while `SMS_DRIVER=log`.

The seeded owner and both admins have SMS two-step on (required for their roles). If your local database predates that, run `php artisan migrate:fresh --seed`; this wipes local data.

## With PostgreSQL, Redis and Horizon

```bash
docker compose up -d           # in this folder: postgres, redis, mailpit
```

Then in `.env`, set `DB_CONNECTION=pgsql` (plus host/db/user/password from `docker-compose.yml`), `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `MAIL_MAILER=smtp` with `MAIL_PORT=1025`. Run `php artisan migrate` and `php artisan horizon`.

## Useful commands

| Command | What it does |
|---|---|
| `php artisan test` | Full test suite (SQLite in memory; CI runs it on PostgreSQL) |
| `vendor/bin/pint` | Format code |
| `php artisan nguzopay:admin you@nguzo.co.tz` | Give an existing user back-office access (`--revoke` to remove) |
| `php artisan vault:key` | Generate a vault master key |
| `php artisan nguzopay:sms-test 0712345678` | Send one SMS through the configured driver (checks the DarSMS key and sender ID) |
| `php artisan route:list --path=api` | Every API endpoint |
# nguzopay
