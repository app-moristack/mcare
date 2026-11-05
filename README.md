# MauriCare

Laravel 12 + React 19 + TypeScript with Inertia, Vite and Tailwind. A working operational v1 increment with individual accounts, patient intake, referrals, appointments/attendance, owned tasks, credential review, manual invoice ledger and patient/provider views. The remaining clinical pilot scope is tracked in [docs/BUILD_PLAN.md](docs/BUILD_PLAN.md).

## Local setup (WSL Ubuntu)

```sh
composer install
npm ci
# Fresh checkout only; preserve .env and the existing key on an installed checkout:
cp .env.example .env
php artisan key:generate
# Configure database in .env; create database/database.sqlite if using SQLite.
php artisan migrate
php artisan db:seed --class=AdminUserSeeder
npm run build
composer run dev
```

Open http://localhost:8000/login. The explicitly requested administrator is `admin@mauricare.mu`, password `P@ssw0rd`. The default DatabaseSeeder invokes AdminUserSeeder too. Rerunning it intentionally resets that account's password and memberships. Passwords are hashed. No public signup can grant admin roles.

Development email uses the log transport: verification and reset links appear in `storage/logs/laravel.log`. Set SMTP for actual delivery. Only use synthetic information during development.

## Start a coordination workflow

1. Sign in as admin and open **Command centre**. MFA is disabled locally at the owner's request; authenticator setup is optional under **Two-factor security**.
2. Create a second individual staff account and verify its email. In **Team & credentials → Add operational staff**, approve it as coordinator with evidence. This provides a separate backup; active referrals/tasks require distinct owner and backup.
3. Create a patient with demographic details and the actual coordination authority/evidence, or have a patient complete their profile and submit a care request from **My care**. These are separate identities and are never merged automatically.
4. Create a referral with owner, backup and deadline. Move it to review; an authorised clinical-admin role records triage rationale before **awaiting match**. Do not use routine referral queues for emergencies.
5. Register a provider application account, verify email, submit discipline/credential reference/expiry to the organisation, then review it under **Team & credentials**. Verify the source outside the app and record evidence. This release does not upload credential files.
6. Schedule an appointment against the triaged referral with an approved provider and an explicit Mauritius-time interval. Overlapping bookings are rejected. Confirm after obtaining patient/provider agreement and record the evidence.
7. The assigned provider uses **My visits** for arrival/departure, with an event-only GPS capture or an exception reason. The coordinator records evidenced operational completion after both events. Signed clinical completion is a separate pending feature.
8. Use **Tasks** for accountable follow-up; transitions require the relevant waiting/completion evidence. Create invoice line items in MUR, issue the invoice, and reconcile verified payments with reference/evidence. Balances are server-derived.

The patient portal exposes only own explicitly linked appointments and issued bills. It never receives staff-only evidence or another patient's record.

## Workforce security configuration

Mandatory workforce MFA is temporarily disabled directly in `config/mauricare.php`, at the owner's request. Redeploying this version disables the requirement even if the server's existing environment still sets `WORKFORCE_MFA_REQUIRED=true`. The container entrypoint rebuilds Laravel's configuration cache during startup. No server environment edit is needed for this release.

Authenticator setup remains optional. To require MFA in a future release, restore the configuration flag's environment lookup and set it to true. MFA setup uses manual-key TOTP with password confirmation and a first successful code; secrets are encrypted and codes cannot be replayed. Recovery codes/controlled assisted recovery remain pending.

## Scheduled task escalation

Run the Laravel scheduler independently of the browser:

```sh
php artisan schedule:work
# Or production cron, once per minute:
# * * * * * cd /path/to/mcare && php artisan schedule:run >> /dev/null 2>&1
# Manual verification:
php artisan mauricare:escalate-tasks
```

Overdue tasks create deduplicated in-app alerts for the owner and backup. This command does not send SMS/email or replace a staffed critical/emergency response process. The existing Docker/deployment assets are preserved; configure scheduler ownership and monitoring before pilot.

## Verification

```sh
php artisan test
vendor/bin/pint --dirty --test
npx tsc --noEmit
npx eslint resources/js
npm run build
git diff --check
```

Do not use `migrate:fresh` on a real database. This is an operational increment, not the fully approved clinical P0 or a claim of production readiness.
