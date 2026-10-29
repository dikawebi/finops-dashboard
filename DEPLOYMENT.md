# FinOps Dashboard — P4.5 Deployment Checklist

## Production prerequisites

- PHP 8.3+, Composer, MySQL 8, and Node.js/npm for asset builds.
- Copy `laravel/.env.example` to `laravel/.env`; set `APP_ENV=production`, `APP_DEBUG=false`, a unique `APP_KEY`, and production database credentials.
- Keep `data_snapshot/` and `baseline/` outside public web root.
- Configure HTTPS, a restricted host, and a least-privilege database user.

## Release commands

```bash
cd laravel
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan test
```

Health check: `GET /up`.

## Data and rollback

1. Back up MySQL before migrations or snapshot loading.
2. Load frozen data only with an explicit operator approval: `php artisan dashboard:load-data`.
3. Verify counts with `php artisan dashboard:verify`.
4. Run `php artisan dashboard:regression` before release.
5. Roll back application code through the previous Git commit and restore the database backup if a migration/data change is incompatible.

## Security gate

- Read-only dashboard APIs are public by design for the current single-user deployment.
- Any future import, config, accrual, remark, or sync endpoint must use `auth`, CSRF protection, `EnsurePeriodOpen`, authorization, and `AuditLog` before release.
- Do not enable CORS `*`, expose debug output, or publish snapshot/baseline files through the web server.
