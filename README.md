# MauriCare

Laravel 12 + React 19 + TypeScript, using Inertia 2, Vite and Tailwind 4. Built on the existing starter kit. Dates are stored in UTC; the product care time zone is Indian/Mauritius and currency is MUR.

## Run locally (WSL Ubuntu)

```sh
composer install
npm ci
# On a fresh checkout only:
cp .env.example .env
php artisan key:generate
# Configure your database in .env, then:
php artisan migrate
npm run build
composer run dev
```

Open http://localhost:8000. For an existing checkout, preserve .env and skip the copy/key generation steps. The default SQLite setup needs an existing `database/database.sqlite` file. Never run `migrate:fresh` against a real database.

Mail defaults to the log transport during development. Verification/reset links appear in `storage/logs/laravel.log`. Configure SMTP before testing actual email delivery. An administrator seeder is available; see below.

## Delivered foundation

- Branded public page and responsive login, registration, recovery, verification and account settings.
- Patient/records-only registration creates a distinct patient profile. Basic demographics are editable after email verification; server selects only the signed-in user's profile.
- Provider/partner registration creates an unapproved application, never a membership. Proxy registration creates no patient link or permission.
- Terms and notice version/timestamps recorded separately. Current notices are clearly marked development drafts; use synthetic data only.
- Password length 15–128 characters, hashed storage, generic login failures, login/signup/recovery throttling and email verification.
- Suspended/disabled/closed accounts denied on every request. Account closure retains records rather than deleting the chart. Database-backed sessions are deleted after password reset; remember tokens rotate.
- Organisation, membership, application, separate patient profile and basic account security audit tables/models. Memberships are scaffolding only and convey no clinical access.
- Dashboard shows real account/onboarding state. Future clinical modules are explicitly marked unavailable, with no mock metrics or data endpoints.

## Administrator seeder

```sh
php artisan db:seed --class=AdminUserSeeder
# Also included in the default DatabaseSeeder:
php artisan db:seed
```

Creates/updates `admin@mauricare.mu` with the requested password `P@ssw0rd`, verified email, an active account, all configured roles and a super-admin membership in MauriCare. Rerunning resets this account's password and reactivates its memberships. Passwords are hashed in the database. The account can sign in immediately at `/login`; MFA is not yet implemented.

The role catalogue and currently implemented abilities are in `config/permissions.php`. Super admin receives every registered ability in its organisation, subject to account, email verification, membership approval and validity checks. Unknown abilities and other organisations are denied. No clinical privileges or workflows are represented as implemented by this seeder. Future routes/policies must explicitly enforce their relevant registered abilities and clinical constraints.

## Owner provisioning

Register and verify a personal account first. An operator with server access may run:

```sh
php artisan mauricare:provision-owner owner@example.com "MauriCare"
```

This creates an organisation and **pending** owner membership only. Public signup and web endpoints cannot create an administrator; the explicit AdminUserSeeder is the server-side bootstrap exception. Workforce activation, MFA and approval are not implemented yet, so owner/provider/partner operational workspaces remain unavailable.

## Checks

```sh
php artisan test
vendor/bin/pint --test
npm run build
npx tsc --noEmit
npx eslint resources/js
```

## Next packages

See [docs/BUILD_PLAN.md](docs/BUILD_PLAN.md). This is an initial foundation, not a completed P0 pilot or production clinical system.
