# Learners Circle

Learner app code and folder: `learners-circle`. Open `#/app/learners-circle/` in CMS or Dock.

Routes:

| Route | Purpose |
| --- | --- |
| `/` or `/dashboard` | Personal course progress, assessed marks, and packages not yet enrolled in |
| `/learn` | Existing Lesson Manager learning component, including reading, video, quizzes, assignments, and saved progress |
| `/my-enrolments` | Existing Lesson Marketplace enrolment history |
| `/catalog` | Existing Marketplace catalog filtered to packages not already active, awaiting payment, or pending approval |
| `/package?slug=…` | Existing Marketplace package details and enrolment actions |

This app composes the source apps through `davvag-tools/davvag-app-downloader`. It does not copy their learning or payment logic, own learning records, or introduce database tables. Learning and package links remain inside the circle when opened here; source app routes continue to work independently. Credit top-up continues through the existing wallet and its Marketplace return flow.

`lesson-manager/api/LearnerOverview` derives the profile from the authenticated session, includes only that profile's entitlements even for staff, and rejects profile/course overrides. Course progress uses the accessible published lessons, including later package access extensions. Marks use Course Manager's recorded marks, deduplicated per assessment or assignment. The portfolio percentage is total earned marks divided by total recorded maximum marks. Unmarked work displays “Not graded yet”; pending manual marking contributes no recorded quiz mark.

`lesson-market-place/marketplace-api/Catalog` accepts `exclude_enrolled: true` for an authenticated learner. It excludes current active, pending approval, and unpaid enrolments before counting and paging, so enrolled packages cannot fill a recommendation page. Rejected and cancelled applications can be explored again. Identity is never accepted from the caller.

Deployment:

1. Deploy `learners-circle` and the accompanying Lesson Manager and Marketplace changes.
2. Register `learners-circle` in `tenant.json` and the intended signed-in group JSON files. Keep `lesson-manager`, `lesson-market-place`, `davvag-tools`, and `userapp` visible to the same groups because the app reuses their components. Do not grant staff management methods to learner groups.
3. Run `php apps/learners-circle/bin/install.php <tenant-host> <framework-root>` to install the narrow learner permission manifest. Existing permission entries and business records are preserved. No schema migration is needed.
4. Reload existing browser tabs once after deployment. Login then uses the existing CMS session refresh.

Checks from the framework root:

```text
node --test apps/learners-circle/tests/workspace.test.cjs
php apps/learners-circle/tests/overview_test.php
```

The root paths in these examples refer to the deployed tenant. Integration checks additionally run against an isolated temporary tenant and database. No learner marks, progress, or enrolments are written during installation.
