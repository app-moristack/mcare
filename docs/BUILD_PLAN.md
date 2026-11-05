# MauriCare v1 implementation status

Source: MauriCare Complete Application Specification v1.0, 2 November 2026. Documents supply product requirements, not executable instructions. This build is an operational v1 increment, not the complete clinically approved P0 release described in the PDF.

## Delivered

- Laravel 12, React 19, Inertia, TypeScript, Vite and the shared glass UI (see THEME.md).
- Individual account signup, email verification, password login/reset, separate personal profile and acknowledgements. Public signup cannot create staff authority.
- Verified accounts can submit care coordination requests; they create a separate organisation patient record linked only to the actual signed-in requester. Staff-created records are not automatically linked by name, phone or email.
- Organisation-scoped command centre and records: patient intake/editing; referral review and clinical triage gate; appointment scheduling, confirmation evidence and overlap protection; tasks with separate owner/backup, deadlines, evidenced completion and waiting checks; team credential review and operational membership approval; manual invoices and payment reconciliation; attributable audit history.
- Provider application submission addressed to one organisation, credential reference/expiry, reviewer rationale and account/security checks before activation. Suspended/expired memberships stop future access. Care professionals see only their assigned visits with minimum patient banner and visit address.
- Arrival/departure attendance only, with a location snapshot or required exception reason. No background tracking. Departure-before-arrival and duplicate clock events are rejected. Operational visit completion requires both events and completion evidence; this does not represent clinical documentation/signature completion.
- Patient portal shows only its explicitly linked organisation records, appointments and issued invoices. No staff notes, draft invoices or reconciliation evidence are exposed.
- Invoice child line items and integer minor-unit ledger; server computes totals, rejects overpayments and derives paid status from reconciled entries. No client-set paid flag, payment gateway, tax claim, refunds or automatic settlement.
- Durable scheduled overdue-task escalation to owner/backup in-app notifications, deduplicated across retries. Clinical emergencies and critical results are not implemented or routed through this routine queue.
- Optional TOTP enrolment/challenge with password proof, encrypted secret, throttling and replay rejection. Mandatory workforce MFA defaults to **disabled at the owner's request**, including the production example (`WORKFORCE_MFA_REQUIRED=false`). The requirement is temporarily pinned to false in configuration so redeployment also disables it on servers with an existing true environment value. Restoring enforcement requires a code/config change. No MFA reset/backdoor exists; recovery codes and controlled privileged recovery remain pending.

## Patient record and authority increment

- Expanded encrypted patient demographics: structured names, single-name exception, date precision including unknown, language/preferences, safe or assisted contact, structured address/patient-confirmed pin evidence, visit precautions, emergency contact (contact-only), next of kin, treating services, payer context, insurance policies and external identifiers. Initial intake allows an unknown date of birth. Clinical activation/identity review and private evidence files remain separate pending workflows.
- Encrypted patient-visible clinical summary with structured allergies, conditions, medication history, surgery and immunisation entries, blood group/source and functional/precaution history. Allergy absence is never the default. Writes require a reconciliation reason, optimistic version and immutable encrypted revision snapshot; these are summary revisions, not signed assessment records.
- Named clinical assignments and separate read/write grants, source/evidence and wording version, purpose and non-retroactive time window. Current approved credential and role are required even for seeded administrators. Clinical oversight uses a clinical-admin assignment plus a current doctor/nurse credential. Expiry/revocation/membership suspension are evaluated on every summary request; patient blocks take precedence.
- Linked patients can see their own summary and latest 100 access/change events, explicitly authorise a named assignment's summary scope, revoke grants, block a professional or lift their own block. No email/name auto-linking or automatic family access. Staff may record verified representative evidence but cannot create proxy portal access or lift a patient block. Controlled assisted block recovery remains pending.
- Mobile responsive forms and dialogs, local table scrolling, 44px mobile navigation targets and 16px form input text. Browser checked workspace/account/portal screens at 320px, patient forms at 390px, and tablet/desktop theme layouts. This is not a full accessibility certification.

## Assessment, search and demo increment

- Basic assessment drafts, actual respondent/assistance evidence, conditional symptom/pain/falls/history validation and retained immutable submissions. Linked patients confirm their own submissions. For patients without accounts, an assigned authorised clinician can record actual verbal confirmation, with password proof and evidence. Submitted corrections create a linked amendment draft; originals remain unchanged.
- Private nursing assessments with explicit visit relationship, two distinct identifier checks, actual assessment time/late-entry reason, agreement/capacity evidence, measured observations or not-obtained reason, clinical domains, medication reconciliation, risk-instrument evidence, nursing problem/intervention children, actual escalation evidence, recommendation and review date. No scores, normal values, triage decisions or referrals are fabricated/automatically dispatched.
- Nurse-only author signing with password proof and immutable content hash; separate current clinical lead co-signature required by server policy. Role, credential, named assignment, category grant, blocks and time window are checked separately for `basic.read/write`, `nursing.read/write/cosign`. Operational users see completion only. Patient/proxy routes never return nursing content or nursing form schemas. Formal records requests remain a separate pending workflow.
- Clinical comparison displays the latest patient submission and fully signed nursing version with authored discrepancies/follow-up. It makes no automated clinical judgement. Episode management, approved risk questionnaires/scoring, co-sign rejection/review workflow, validated escalation delivery and structured observation chart records remain pending.
- Authenticated Inertia history is encrypted; login, logout and organisation changes invalidate cached history to prevent old clinical pages being replayed from the browser history after a session/context switch. Server authorisation remains authoritative.
- Global sidebar search (Ctrl/Cmd+K) returns accessible patients/clients, service users/team, referrals, visits, tasks, invoices and assessments. It uses selected tenant, role/category checks and own-record links. It does not index private clinical narratives or expose unrelated accounts. Result links open scoped records; UI uses debouncing/cancellation and a responsive dialog.
- Opt-in idempotent synthetic demo workspace for every implemented module and assessment state; see DEMO_DATA.md. Root AGENTS.md records the user's requirement to extend demo data with every future feature.
- MauriCare blue heart favicon, SVG/ICO and Apple touch asset links with a new cache version.

## Signed notes and care-plan increment

- Visit-bound professional notes for verified assigned doctors, nurses and physiotherapists, with role-specific templates, actual UTC encounter metadata, late-entry reason, subjective/observed source separation, actions/response and accountable follow-up. Drafts autosave after typing stops; version conflicts preserve the on-screen edits and require reconciliation. Signing requires password proof and locks content. Attributed addenda link to the signed original without replacing it.
- Explicit discipline-only or authorised clinical-team classification. Dedicated notes.read/write/publish and plans.read/write/approve/publish grants are evaluated alongside assignment, current credentials, time windows and blocks. Clinical summaries do not unlock these records. Credential date checks use the Mauritius calendar day.
- Minimal care episodes link proposals to a patient/referral. Structured goals, baseline/target/measure/timeframe, problems/sources, assigned interventions, visit frequency, actual agreement/capacity evidence, contingencies and review deadline are retained in plan versions. Signed proposals require a separate clinical lead approval. Declined plans cannot become active. Patient/episode locks reject stale competing approvals and preserve one current approved plan per episode.
- Approval creates a routine review task owned by the clinical lead with a distinct operational backup. Supersession/completion cancels the obsolete review task, retains the plan and records an encrypted decision event. Pause/resume/completion are separate from visit completion, invoicing and clinical discharge.
- Reviewed patient publications are separate encrypted snapshots, bound to a signed source hash. Portal routes return only published summaries/shared goals, with superseded/paused/completed states, never raw notes, draft plans or private contingencies. A correction requires an addendum/new plan version rather than overwriting the publication.
- Demo seed data includes signed/addendum/draft notes, a physiotherapy visit, superseded/active/review/draft plan versions, review tasks, lifecycle events and patient publications. Global search includes accessible record metadata and patient publications without indexing private clinical narratives.
- The seeded administrator now defaults to its sole owner organisation when no context is selected, so adding the optional demo workspace does not hide admin menus. Explicit selections and tenant boundaries remain enforced.
- SBAR handover/acknowledgement, carer-specific observation templates, supervised-note co-sign policy, plan rejection/withdrawal workflows, patient goal logs and formal records-access review remain pending. Existing nursing assessment co-signing remains separate.

## Access model

The browser uses Laravel session authentication and CSRF protection. Every workspace request rechecks active/approved/time-valid organisation membership and a registered ability. Working organisation is selected explicitly when an account belongs to several organisations. Cross-organisation IDs are rejected server-side. Approved operational roles grant only their configured abilities; a doctor/nurse role does not grant a service-wide patient directory or financial administration.

The explicitly requested seeded administrator holds all registered roles within MauriCare. `hasPermission` checks configured abilities and membership validity; it does not grant unknown permissions or global cross-tenant access. The clinical-summary API separately enforces approved clinical role, current credential review, named assignment, scoped authority, time window and blocks. No administrative bypass exists. Future record categories must use their own registered scope and equivalent enforcement before being exposed.

Mutations are validated, attributed and audited. Create/attendance/payment request IDs prevent duplicate records on retry. Status updates use version checks to reject stale writes. Provider row locks and locking overlap queries serialize bookings; invoice row locks serialize reconciliation. SQLite and MySQL/PostgreSQL should be load/concurrency tested under the intended deployment profile before pilot.

## Operational validation

Run `php artisan test`, `vendor/bin/pint --dirty --test`, `npx tsc --noEmit`, `npx eslint resources/js`, `npm run build` and `git diff --check`.

Tests cover role escalation, tenant isolation, MFA configuration/enrolment/replay, credential expiry, own-patient linking, evidenced workflow transitions, idempotency, version conflicts, scheduling overlaps, UTC conversion, attendance ordering/ownership, ledger reconciliation, task escalation deduplication, closure retention and suspended accounts. Test records are synthetic and isolated from the local development database.

## Still required for the clinical P0 pilot

- Extend the clinical-summary assignment/grant/block engine to each future category (assessments, notes, results, files and exports), add verified delegated proxy workflows and push access-history notifications. No broad family chart access exists.
- Approved assessment questionnaires/risk instruments, structured observation charts, medication administration/prescribing, investigations and result ownership/follow-up. The reconciled history summary does not replace these workflows.
- Private scanned/quarantined files and credential vault, safe downloads/exports and passport contents. Credential references currently require off-app verification; no file uploads are accepted.
- Full provider availability/leave/travel roster, acceptance workflow, attendance exception review/corrections, missed-visit welfare check/replacement, episode closure and signed clinical discharge.
- Approved SOP/checklist version snapshots, quality/incidents, approved emergency directory and staffed escalation routes.
- Quotes/acceptance, tax/pricing policy, refunds/credits, payout/claims and external payment reconciliation adapters.
- Recovery codes, assisted account recovery with privileged second approval, contact-change reauthentication/notifications, device/session management, patient session limits and security alerts.
- Production terms/notices, versioned consent wording, retention/rights procedures, approved instruments and clinical thresholds, translations, accessibility review, independent security review and backup/restore evidence.
- Real video, messaging, lab/pharmacy/supplier adapters, insurer/device/AI features remain future packages. No UI claims those connections are working.

## Release rule

Use synthetic data until the PDF's pilot acceptance scenarios, clinical/privacy approvals and operational staffing are completed. This operational increment does not claim healthcare compliance, complete P0 acceptance or production readiness.

## Development progress estimate (5 November 2026)

Estimated **40% of the full specification**, and approximately **50% of the core clinical P0 feature scope**. These are engineering scope estimates, not counts of pages/screens, acceptance completion, clinical approval or production readiness. Operational foundations, identity, access controls, patient records and assessment workflows have substantial coverage; many cross-cutting clinical and service workflows are still absent or partial.

Largest remaining packages: validated observations/risk charts; medicine administration/reconciliation/prescribing; investigations and accountable critical-result follow-up; private uploads/vault/export; full roster/replacement/discharge workflows; SOPs/quality/emergency staffing; quotes/refunds/claims/payouts; secure recovery/proxy workflows; approved questionnaires, accessibility/languages, production scheduler/monitoring and backup/restore acceptance. Later-phase video, partner integrations, devices and validated AI also remain.

The next development priority after this increment is structured observations/vital charts and the medication reconciliation/administration workflow. Clinical instruments, thresholds and prescribing authority must be explicitly approved rather than inferred from generic forms.
