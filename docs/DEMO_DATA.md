# Synthetic demo data

Run after migrations:

```sh
php artisan db:seed --class=DemoDataSeeder
```

This opt-in seeder creates a separate **MauriCare Demo** organisation. It does not clear or modify real patient records, reset existing demo passwords, send email/SMS, transfer money or collect GPS. Re-running preserves record counts, locked assessments and summary revisions. The normal `DatabaseSeeder` remains the administrator-only bootstrap.

Select **MauriCare Demo** in the working-organisation selector when using the existing administrator. Clinical content requires a demo clinician's explicit assignment, category grants and current demo credential; the administrator has operational completion access only.

## Demo accounts

All addresses use the reserved `example.test` domain. Password for newly created demo accounts: `MauriCareDemo!2026`.

| Email | Role / useful journey |
| --- | --- |
| demo.patient@example.test | My care, own clinical summary, basic assessment submission/amendment, access controls |
| demo.nurse@example.test | Assigned visits, medication/result receipt workflows, private nursing drafts and signing |
| demo.lead@example.test | Clinical oversight, co-signature and reviewed patient publications |
| demo.doctor@example.test | External medication verification, investigation requests and result ownership |
| demo.physio@example.test | Assigned summary read only; no nursing/basic assessment access |
| demo.carer@example.test | Workforce account; no general clinical record browsing |
| demo.coordinator@example.test | Patients/referrals/tasks/appointments and assessment completion only |
| demo.finance@example.test | Invoice/payment operations; no clinical narratives |
| demo.applicant@example.test | Pending provider review |
| demo.partner@example.test | Pending partner application |

## Coverage

Six synthetic patients, one linked patient account/profile, approved and pending applications, memberships, assignments and category grants, clinical summaries with encrypted retained revisions, referrals, future/confirmed and completed visits, immutable arrival/departure examples without real GPS, overdue/waiting tasks, draft/issued/part-paid/paid invoices with line items and synthetic reconciled payments, a coordination notification, audit seed event, submitted basic assessment and amendment draft, signed nursing example, pending co-signature and nursing draft, encrypted structured observation sets including an append-only correction and effective trend values, an encrypted medication reconciliation, fake verified external instruction and explicitly omitted administration outcome, plus a fake external investigation request, encrypted source result, named-owner follow-up task and reviewed portal publication.

All evidence and clinical text explicitly identify themselves as synthetic. Demo credentials and assessment signatures are examples only and do not represent actual clinicians, patient consent or clinical verification. No fabricated data should be used for care decisions. Uploaded files, prescribing, result integrations and other unimplemented modules are not represented as working.

## Future changes

For each new feature, extend `DemoDataSeeder` and its idempotency/coverage tests. Keep examples clearly synthetic, use stable keys/request IDs, preserve locked content and exercise the feature's permission boundaries. This requirement is also recorded in the root `AGENTS.md`.

## Signed notes and care plans

`DemoDataSeeder` also calls `ClinicalRecordDemoSeeder`, which can be rerun independently after the base demo exists. It adds category-specific grants, signed nursing notes, a retained signed addendum, draft notes, a physiotherapy visit, a demo care episode, superseded/active/review/draft plan versions, routine review tasks, lifecycle events and separately published summaries/shared goals. Existing locked content and current-episode selections are preserved on reruns.

The base demo also creates category-scoped document-vault grants, one clearly labelled metadata-only quarantine record with no blob, and one rejected export example with no archive. The seeder never creates passport content, uploads a real file, contacts an external scanner or sends a document outside the application.

Roster demo data includes approved synthetic availability for the care professionals, one pending synthetic travel review, explicit synthetic acceptance on seeded visits, and one open missed-visit welfare/replacement case with an accountable task. No real availability, travel, appointment, welfare contact or patient action is represented or performed.

Attendance demo data includes an independently confirmed synthetic location exception and a separate synthetic exception with an approved append-only time correction. The original attendance events remain retained, and no real location or visit evidence is represented.

Use the nurse account to open **My visits → Notes and care plans**, autosave a draft or submit a replacement proposal. From the synthetic patient's record, the nurse can also open **Observations and vital chart** to review the retained synthetic trend or sign another visit-bound set. Use the clinical lead to approve and publish notes and plans; the coordinator is a suitable routine-review backup. The patient account sees only the published plain-language snapshots, not private observation charts. The administrator sees operational status, not private clinical contents through its admin role alone.

- For discharge testing, sign in as the demo clinical lead and open Notes and care plans → Care plans for **Demo Discharge Patient**. The episode has a completed plan and is ready to sign. Use only synthetic text in the review and patient-facing summary.
- For clinical handover testing, open **Demo Aline Patient** → Notes and care plans → Care plans. The clinical lead and nurse can see the synthetic handover. Sign in as the demo nurse to acknowledge it.
- For task transfer testing, sign in as the demo nurse and send a new clinical handover for Demo Aline Patient to the demo clinical lead. Select the synthetic routine transfer task. The lead signs the acknowledgement and explicitly accepts ownership.
- For investigation follow-up transfer testing, sign in as the demo doctor and open Demo Aline Patient's clinical handovers. Offer the synthetic routine investigation result to the demo clinical lead, who signs and accepts it. In Investigations, verify that the current follow-up owner and the original signed owner are shown separately.
- For checklist catalogue testing, sign in as the demo coordinator and open Operational checklists. Review the synthetic published version and draft, then publish a draft using the demo account password.
