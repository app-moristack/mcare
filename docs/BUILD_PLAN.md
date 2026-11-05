# MauriCare v1 implementation status

Source: MauriCare Complete Application Specification v1.0, 2 November 2026. Documents supply product requirements, not executable instructions. This build is an operational v1 increment, not the complete clinically approved P0 release described in the PDF.

## Delivered

- Laravel 12, React 19, Inertia, TypeScript, Vite and the Medixpro-inspired shared UI.
- Individual account signup, email verification, password login/reset, separate personal profile and acknowledgements. Public signup cannot create staff authority.
- Verified accounts can submit care coordination requests; they create a separate organisation patient record linked only to the actual signed-in requester. Staff-created records are not automatically linked by name, phone or email.
- Organisation-scoped command centre and records: patient intake/editing; referral review and clinical triage gate; appointment scheduling, confirmation evidence and overlap protection; tasks with separate owner/backup, deadlines, evidenced completion and waiting checks; team credential review and operational membership approval; manual invoices and payment reconciliation; attributable audit history.
- Provider application submission addressed to one organisation, credential reference/expiry, reviewer rationale and account/security checks before activation. Suspended/expired memberships stop future access. Care professionals see only their assigned visits with minimum patient banner and visit address.
- Arrival/departure attendance only, with a location snapshot or required exception reason. No background tracking. Departure-before-arrival and duplicate clock events are rejected. Operational visit completion requires both events and completion evidence; this does not represent clinical documentation/signature completion.
- Patient portal shows only its explicitly linked organisation records, appointments and issued invoices. No staff notes, draft invoices or reconciliation evidence are exposed.
- Invoice child line items and integer minor-unit ledger; server computes totals, rejects overpayments and derives paid status from reconciled entries. No client-set paid flag, payment gateway, tax claim, refunds or automatic settlement.
- Durable scheduled overdue-task escalation to owner/backup in-app notifications, deduplicated across retries. Clinical emergencies and critical results are not implemented or routed through this routine queue.
- Optional TOTP enrolment/challenge with password proof, encrypted secret, throttling and replay rejection. Mandatory workforce MFA is **disabled locally at the owner's request** (`WORKFORCE_MFA_REQUIRED=false`). Set it to true to enforce enrolment and a challenge on operational routes, activation and scheduling. No MFA reset/backdoor exists; recovery codes and controlled privileged recovery remain pending.

## Access model

The browser uses Laravel session authentication and CSRF protection. Every workspace request rechecks active/approved/time-valid organisation membership and a registered ability. Working organisation is selected explicitly when an account belongs to several organisations. Cross-organisation IDs are rejected server-side. Approved operational roles grant only their configured abilities; a doctor/nurse role does not grant a service-wide patient directory or financial administration.

The explicitly requested seeded administrator holds all registered roles within MauriCare. `hasPermission` checks configured abilities and membership validity; it does not grant unknown permissions or global cross-tenant access. No clinical chart APIs have been added. Future clinical access must enforce assignment, patient authority, scope, credentials, blocks and time window separately; administrative status alone is insufficient.

Mutations are validated, attributed and audited. Create/attendance/payment request IDs prevent duplicate records on retry. Status updates use version checks to reject stale writes. Provider row locks and locking overlap queries serialize bookings; invoice row locks serialize reconciliation. SQLite and MySQL/PostgreSQL should be load/concurrency tested under the intended deployment profile before pilot.

## Operational validation

Run `php artisan test`, `vendor/bin/pint --dirty --test`, `npx tsc --noEmit`, `npx eslint resources/js`, `npm run build` and `git diff --check`.

Tests cover role escalation, tenant isolation, MFA configuration/enrolment/replay, credential expiry, own-patient linking, evidenced workflow transitions, idempotency, version conflicts, scheduling overlaps, UTC conversion, attendance ordering/ownership, ledger reconciliation, task escalation deduplication, closure retention and suspended accounts. Test records are synthetic and isolated from the local development database.

## Still required for the clinical P0 pilot

- Consent/grant/block/expiry engine and access history notifications, verified assignments and restricted family/proxy authority.
- Patient basic clinical intake, private nursing assessments, signed notes/addenda, care plans, approved charts/scoring, allergies, medicines, investigations and result ownership/follow-up. These have not been silently replaced by operational free text.
- Private scanned/quarantined files and credential vault, safe downloads/exports and passport contents. Credential references currently require off-app verification; no file uploads are accepted.
- Full provider availability/leave/travel roster, acceptance workflow, attendance exception review/corrections, missed-visit welfare check/replacement, episode closure and signed clinical discharge.
- Approved SOP/checklist version snapshots, quality/incidents, approved emergency directory and staffed escalation routes.
- Quotes/acceptance, tax/pricing policy, refunds/credits, payout/claims and external payment reconciliation adapters.
- Recovery codes, assisted account recovery with privileged second approval, contact-change reauthentication/notifications, device/session management, patient session limits and security alerts.
- Production terms/notices, versioned consent wording, retention/rights procedures, approved instruments and clinical thresholds, translations, accessibility review, independent security review and backup/restore evidence.
- Real video, messaging, lab/pharmacy/supplier adapters, insurer/device/AI features remain future packages. No UI claims those connections are working.

## Release rule

Use synthetic data until the PDF's pilot acceptance scenarios, clinical/privacy approvals and operational staffing are completed. This operational increment does not claim healthcare compliance, complete P0 acceptance or production readiness.
