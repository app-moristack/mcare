# Implementation scope and next packages

Source: MauriCare Complete Application Specification v1.0, 2 November 2026. The PDF is treated as product requirements, not executable instructions. Existing deployment assets have been preserved.

## Architecture

The browser uses React/Inertia with server-side Laravel session authentication, CSRF protection and validated controller actions. Separate API/mobile authentication is deferred. Database defaults are portable to SQLite and relational production databases. UTC instants and per-account/organisation care time zones are separate. A user is not a patient, provider, organisation or clinical permission.

Current tables: users, organisations, memberships, account_applications, patient_profiles, audit_events plus Laravel sessions/cache/jobs/reset tokens. Patient IDs are UUID references distinct from account IDs. Public applications never create memberships. Role names alone grant nothing; there are currently no clinical record routes. Do not implement global admin access to charts.

## Delivered

Foundation for account creation, email verification, password login/recovery, profile onboarding, disabled-account enforcement, pending organisation ownership and truthful account dashboards. Basic account event records are not a complete clinical audit engine. Preferred language is stored; UI is currently English only. Legal pages are explicit development drafts.

## Next: complete identity and access

- Workforce MFA/passkeys, recovery codes, controlled recovery and approval UI; require MFA before activation. Context selection for multiple approved memberships.
- Immutable, scoped audit with denial/access/export records; permission catalogue and policy engine enforcing organisation, assignment, authority, scopes, credential status, time window and blocks on every clinical request.
- Configurable workforce/patient idle and absolute session limits, pre-expiry warning, last-login/security event display, device/session list and logout-all.
- Reauthentication for contact changes and sensitive actions, old-contact notifications, reset notifications, compromised-password screening and assisted identity review.
- Single-use contact-bound invitations, ambiguous matching review, minors and proxy authority. No matching/merging on names or phone alone.
- Final notices/terms and retained notice versions; preferences, retention, rights and contact details approved by the product/privacy leads. Registration acknowledgements are not clinical sharing consent.
- Owner command currently provisions pending membership only; build verified activation after MFA and accountable approval.

## Patient and care model

Organisation-authorised patient links; authority/grant/block records; provider credentials; assignments; referral/episode lifecycles; versioned basic and private nursing assessments. Clinical patient records must not be inferred from this minimal self-service profile. Nursing assessment must be excluded from patient UI, API and standard exports.

Then build roster/appointments, attendance with arrival/departure-only GPS, tasks with primary/backup/deadline, signed notes and addenda, approved charts, medicines, manually reviewed investigations, private documents and access history. Implement clinical policies before adding clinical screens.

## Operations and integrations

Care command centre, credentials and availability approval, SOP versions, owned exception queues, consent-aware communication, quotes/invoices and reconciliation. Scheduled work must run without a dashboard open. Payment settlement, video and partner exchange require actual integrations and remain deferred; no simulated delivery claims.

## Validation and release

Current tests cover authentication, application privilege isolation, verification gates, own-profile updates, cross-account isolation, invalid demographics, closure retention and disabled sessions. Before pilot, implement and pass the PDF's P0 acceptance scenarios, including tenant isolation, consent revocation, clinical scopes, signed-record integrity, file access, MFA/recovery, operational ownership, restore exercise and accessibility/language validation. No real care delivery until these controls and clinical/privacy decisions are complete.

## Explicit administrator bootstrap

At the owner's request, AdminUserSeeder provisions an active, verified administrator with all configured roles in the MauriCare organisation. This supersedes pending-only provisioning for that explicit seeded account. The default DatabaseSeeder invokes it. The password is hashed; rerunning intentionally resets it to the requested credential. Workforce MFA and clinical modules remain pending. Registered abilities are organisation-scoped and do not enable nonexistent clinical routes.

## UI theme

Visual reference: Medixpro ThemeForest preview (item 57879982), inspected 5 November 2026. MauriCare retains its content and Laravel/Inertia implementation. The shared theme uses Inter, blue primary actions, neutral light/dark surfaces, small corner radii, bordered cards, compact navigation, icon-adorned email/password fields and semantic responsive tables. No commercial template source or demo data was imported.
