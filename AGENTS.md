# MauriCare development conventions

- Keep every feature responsive on phones, tablets and desktop; verify narrow screens and long form/dialog behaviour.
- Every new feature must include or update idempotent synthetic seed data in `DemoDataSeeder` and meaningful tests for its permissions and workflow. This is an explicit user requirement.
- Keep demo seed data opt-in and separate from the production administrator seeder. Label synthetic records clearly, use reserved example.test email addresses and never send external messages from a seeder.
- Preserve both selectable themes, shared glass effects and reduced-motion support.
- Do not restore mandatory workforce MFA unless the user requests it; it is intentionally disabled.
