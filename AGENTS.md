# MauriCare development conventions

- Keep every feature responsive on phones, tablets and desktop; verify narrow screens and long form/dialog behaviour.
- Every new feature must include or update idempotent synthetic seed data in `DemoDataSeeder` and meaningful tests for its permissions and workflow. This is an explicit user requirement.
- Keep demo seed data opt-in and separate from the production administrator seeder. Label synthetic records clearly, use reserved example.test email addresses and never send external messages from a seeder.
- Preserve both selectable themes, shared glass effects and reduced-motion support.
- Do not restore mandatory workforce MFA unless the user requests it; it is intentionally disabled.

## Feature delivery

- For full-stack features, run a Laravel backend agent and a React frontend agent in parallel. Agree on the API and Inertia props first, give each agent separate file ownership, then review and test their work together.
- The coordinating agent owns the API contract, cross-stack review, local integration checks, and the feature commit and push. Keep each agent focused on its assigned files and avoid repeating checks delegated to another agent.
- Run a separate Prod Tester agent in parallel to track GitHub Actions for exact release commits and run read-only production smoke checks. Keep it off development files and use existing synthetic data in isolated test environments for tests that write records.
- During overnight work in Mauritius time, manual production smoke checks may be batched after two feature releases. Keep per-feature local validation and monitor each deployment result.
- Resume interrupted work from the current branch, working tree, and most recent deployment status. Check these before repeating a commit or push.
- Notify the project owner in the active chat for each feature after verifying production. Include the feature, deployed commit, checks performed, and anything still awaiting verification.
