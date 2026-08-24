# Task Tracker Agent Guide

This document is for AI agents and developers extending the DAVVAG Task Manager app in this folder.

## App Identity

Runtime app code:

```text
task-tracker
```

Display name:

```text
Task Manager
```

Primary folder:

```text
davvag-core/localhost/apps/task-tracker
```

Database namespaces use the `task_manager_` prefix.

## Folder Map

```text
apps/task-tracker/
  app.json
  app.php
  AGENTS.md
  components/
    projects/
      component.json
      partial.html
      script.js
      projects.css
    tasks/
      component.json
      partial.html
      script.js
      tasks.css
    task-view/
      component.json
      partial.html
      script.js
      task-view.css
    task-dashboard/
      component.json
      partial.html
      script.js
      task-dashboard.css
    task-work-log-summery/
      component.json
      partial.html
      script.js
      task-work-log-summery.css
    task-work-log-detailed/
      component.json
      partial.html
      script.js
      task-work-log-detailed.css
  services/
    taskapi/
      component.json
      script.js
      service.php
```

`task-dashboard` is legacy fallback/reference only. The active app is split across `projects`, `tasks`, and `task-view`.

## Routes

`app.json` maps:

```text
/         -> projects
/projects -> projects
/tasks    -> tasks
/task     -> task-view
/task-work-log-summery  -> task-work-log-summery
/task-work-log-detailed -> task-work-log-detailed
```

Navigation should use the DAVVAG shell route component when available:

```javascript
handler = exports.getShellComponent("soss-routes");
handler.appNavigate("../tasks?projectId=" + projectId);
handler.appNavigate("../task?projectId=" + projectId + "&taskId=" + taskId);
```

Important DAVVAG route rule:

```text
soss-routes.appNavigate() appends plain paths to the current hash route.
```

So from:

```text
#/app/task-tracker/projects
```

this is wrong:

```javascript
handler.appNavigate("/tasks?projectId=" + projectId);
```

because it becomes:

```text
#/app/task-tracker/projects/tasks?projectId=...
```

Use sibling navigation instead:

```javascript
handler.appNavigate("../tasks?projectId=" + projectId);
```

Back navigation examples:

```javascript
handler.appNavigate("../projects");
handler.appNavigate("../tasks?projectId=" + projectId);
```

Each split component has a hash fallback for direct testing, but shell navigation should use `exports.getShellComponent("soss-routes")`.

## Screens

`components/projects`:

- Lists projects.
- Creates and edits projects.
- Configures SMTP and IMAP settings.
- Assigns project profiles.
- Changes DAVVAG view object permission through `openViewObject`.
- Navigates into `/tasks` for the selected project.

`components/tasks`:

- Shows tasks under one project.
- Uses status tabs: `New`, `In Progress`, `Waiting`, `Done`, `Closed`.
- Creates and edits tasks.
- Requires a server-validated task type when creating or editing a task.
- Displays task types on task lists and task detail surfaces.
- Limits task assignee choices to profiles allocated on the project.
- Handles attachments with FileReader previews and `davvag-tools/davvag-file-uploader`.
- Navigates to `/task` for work-progress logging.

`components/my-tasks`:

- Opens on the `Open` tab by default.
- The `Open` tab includes assigned tasks whose status is not `Done`, `Closed`, or `Completed`.
- Keeps the individual status tabs available for exact-status filtering.

`components/task-view`:

- Shows one task.
- Uses a full-width Task Progress surface.
- Shows assignees and task attachments in the Task Progress summary.
- Keeps Discussion and Progress Timeline as expandable sections inside Task Progress, with Discussion above Progress Timeline.
- Logs work inside the Progress Timeline section with manual work date, time-only start/end fields, automatic duration, status, and progress.
- Provides threaded task discussion inside the Discussion section with comments, replies, and comment attachments.

`components/task-work-log-summery`:

- Shows exact work-log totals for weekly, monthly, or specific inclusive date ranges.
- Filters to all accessible projects or one selected accessible project.
- Filters by the task type stored on each task; untyped legacy tasks are `Uncategorized`.
- Switches between Project-wise task totals and Date-wise project/task totals.
- Preserves the requested `summery` spelling in the component and route identifier; the visible title is Work Log Summary.

`components/task-work-log-detailed`:

- Shows one row per matching work log with project, task, profile, comments, times, status, progress, minutes, `HH:MM`, and decimal hours.
- Uses the same filters and backend inclusion rules as the Summary report.
- Displays and filters by task type.
- Preserves filters when navigating between the Summary and Detailed reports.

`components/task-style`:

- Loads shared Task Manager compatibility CSS for both DAVVAG docks.
- Keeps Bootstrap 3-style classes used by the task app working inside the default `davvag-cms` dock, which uses Bootstrap 4.
- Component scripts also call `ensureTaskCommonStyles()` so `/admin#/app/...` and `/#/app/...` both fetch `components/task-tracker/task-style/file/task-common.css`.

## Service API

Backend descriptor:

```text
services/taskapi/component.json
```

Backend implementation:

```text
services/taskapi/service.php
```

Class:

```php
TaskManagerService
```

Methods:

```text
POST ListProfiles
POST ListProjects
POST ProjectDetails
POST SaveProject
POST DeleteProject
POST ListTasks
POST ListTaskTypes
POST SaveTask
POST DeleteTask
POST TaskDetails
POST SaveWorkLog
POST WorkLogSummary
POST WorkLogDetailed
POST SaveComment
POST NotifyTaskAssignees
```

`ProjectDetails` returns:

```text
project
profiles
accessProfileIds
```

`TaskDetails` returns:

```text
task
assignees
attachments
workLogs
comments
notifications
```

Handler naming must keep DAVVAG convention:

```text
POST SaveTask -> postSaveTask($req, $res)
POST ProjectDetails -> postProjectDetails($req, $res)
```

## Request Locking

Save, delete, and service-backed edit actions must guard against duplicate requests.

- Check the component's reactive `isBusy` flag before starting the action.
- Set `isBusy = true` immediately before invoking the service.
- Disable the corresponding edit, save, delete, and permission buttons with `v-bind:disabled="isBusy"`.
- Reset `isBusy = false` in both the service success and error callbacks.
- Perform client-side validation before enabling the lock so validation errors do not leave the form disabled.

## Data Model

Schemas live under:

```text
davvag-core/localhost/schemas/
```

Namespaces:

```text
task_manager_projects
task_manager_project_access
task_manager_tasks
task_manager_task_assignees
task_manager_task_attachments
task_manager_work_logs
task_manager_task_comments
task_manager_comment_attachments
task_manager_notifications
```

All persistence goes through `SOSSData`.

## Task Types

Tasks store their category in the `taskType` field of `task_manager_tasks`. `ListTaskTypes` is the single source of truth for task creation and report filters. The controlled values are:

```text
Support
Development
Quality Assurance
Bug Fix
Meeting
Research
Design
Documentation
Deployment
Maintenance
Training
Administration
Other
Uncategorized
```

`SaveTask` canonicalizes values case-insensitively and rejects values outside this list. New tasks must select a type. Tasks created before this field existed are normalized to `Uncategorized` when read. New tasks imported by `TaskEmailClient` use `Support`.

## Permissions

This app uses two permission layers.

First, DAVVAG view-object permissions use the same pattern as `productapp-v2/components/frmproduct-list`:

```javascript
openViewObject(target.sysviewobject, function (data, shellpopup) {
    target.sysviewobject = data;
    api.services.SaveProject(target);
    shellpopup.close();
});
```

Do not rename `sysviewobject`; it is a framework system column.

Second, project profile access is stored in:

```text
task_manager_project_access
```

Non-`sysadmin` users only see projects linked to their current profile. Task assignee options are loaded from `ProjectDetails`, so tasks can only be assigned to profiles allocated on the project.

`anonymous.json` was intentionally not updated for this app.

## Attachments

The upload flow follows the Album-form pattern from `davvag-cms-generalapps`:

1. User selects files in `components/tasks`.
2. `FileReader` creates local previews.
3. `SaveTask` persists attachment metadata.
4. `davvag-tools/davvag-file-uploader` uploads the file bytes. This wrapper opens the DAVVAG upload modal and writes files through `soss-uploader` internally.

```javascript
exports.getAppComponent("davvag-tools", "davvag-file-uploader", function (uploader) {
    uploader.initialize();
    uploader.upload(newfiles,
    "task_manager_attachments",
        taskId,
        function () {
            newfiles = [];
        }
    );
});
```

The uploader names files as `{taskId}-{file.name}`. Pass only `taskId` as the third argument.

Read URL:

```text
components/dock/soss-uploader/service/get/task_manager_attachments/{taskId}-{fileName}
```

If the file store name changes, update:

```text
components/tasks/script.js
components/task-view/script.js
```

## Work Logs

Work logs are saved in:

```text
task_manager_work_logs
```

`SaveWorkLog` accepts:

```text
taskId
profileId
profileName
comments
logDate
startDate
endDate
durationInMinutes
progress
status
```

`SaveWorkLog` always stamps the current profile details server-side:

```php
$user = Profile::getUserProfile();
$profileid = $user->profile->id;
$name = $user->profile->name;
```

The saved fields are `profileId` and `profileName`, and Task View displays `profileName` for each progress entry.

The Task View UI stores `logDate`, `startTime`, and `endTime` while the user edits. When work date changes, the selected date is applied to both start and end. When start/end time changes, minutes are recalculated automatically. Before saving, `components/task-view/script.js` combines the date and times into full `startDate` and `endDate` values for the service. If duration is zero and both full dates are provided, the service also calculates minutes. Saving a work log updates the parent task status and progress.

## Work Log Reports

Work Log Summary and Work Log Detailed are read-only reports over the existing work-log namespace. They do not create reporting tables. `schemas/task_manager_work_log_report.json` defines the `SOSSData::ExecuteRaw()` join used by both endpoints. SQL applies the inclusive `logDate` range, optional project and task-type filters, and project-profile access condition before rows reach PHP. PHP then shapes the already-filtered rows and aggregates exact `durationInMinutes` values.

Raw queries bypass `sysviewobject`, so the report SQL must retain its `task_manager_project_access` `EXISTS` condition for non-sysadmin profiles. Dates remain strictly validated as `YYYY-MM-DD`; task types are canonicalized against the controlled server list; and project/profile/admin parameters remain server-derived or cast integers. Never accept browser-provided SQL fragments.

`WorkLogSummary` returns:

```text
filters
totalMinutes
totalHHMM
totalHours
projects -> tasks
dates -> projects -> tasks
```

`WorkLogDetailed` returns the same filters and totals plus ordered `rows`. Weekly ranges run Monday through Sunday. Monthly ranges use the complete calendar month containing `startDate`. Specific ranges require valid start and end dates. Summary and Detailed totals must match for identical effective filters and permissions.

## Discussion Comments

Task discussion rows are saved in:

```text
task_manager_task_comments
```

Comment attachment metadata is saved in:

```text
task_manager_comment_attachments
```

`SaveComment` accepts:

```text
taskId
parentCommentId
body
Attachments
```

The service stamps `profileId`, `profileName`, `commentDate`, and `status` server-side. Current profile values come from the same helper used by work logs:

```php
$user = Profile::getUserProfile();
$profileid = $user->profile->id;
$name = $user->profile->name;
```

Replies use `parentCommentId = root commentId`. `TaskDetails` returns root comments with one-level `replies` arrays and an `Attachments` array on every comment/reply. The Task View discussion is read-first: existing comments render before the new-comment form, and each reply form is hidden until the user clicks `Reply to`; after saving or cancelling, the reply form is hidden again.

The Task View uploads comment files with `davvag-tools/davvag-file-uploader`:

```javascript
exports.getAppComponent("davvag-tools", "davvag-file-uploader", function (uploader) {
    uploader.initialize();
    uploader.upload(files,
    "task_manager_comment_attachments",
        commentId,
        cb
    );
});
```

The uploader names files as `{commentId}-{file.name}`. Pass only `commentId` as the third argument.

Read URL:

```text
components/dock/soss-uploader/service/get/task_manager_comment_attachments/{commentId}-{fileName}
```

Saving a comment or reply also queues `NotifyTaskAssignees` with event type `Discussion`.

## Notifications

`NotifyTaskAssignees` queues rows in:

```text
task_manager_notifications
```

Actual email delivery is still an extension point. Project SMTP/IMAP fields are stored in `task_manager_projects` for future integration with `plugins/notify` or a tenant-local mail plugin.

## Task Email Client

`services/TaskEmailClient` exposes:

```text
GET components/task-tracker/TaskEmailClient/service/getMail
```

Optional query parameters:

```text
projectId
search
limit
markSeen
```

Default search is `UNSEEN`, default limit is `25`, and imported messages are marked seen unless `markSeen=false`.

The service uses the PHP IMAP extension inside the service class, reads project IMAP settings from `task_manager_projects`, and only imports email from profiles assigned to that project through `task_manager_project_access` or `profileids`.

New email threads create `task_manager_tasks` rows with task type `Support`, `emailMessageId`, `emailFromEmail`, and `emailFromName`, then assign the sender profile in `task_manager_task_assignees`. Replies are matched by `Message-ID`, `In-Reply-To`, or `References`; matching messages are saved to `task_manager_task_comments` with `emailMessageId` and `emailFromEmail`.

Email attachments are stored directly into the same DAVVAG uploader paths:

```text
MEDIA_FOLDER/DATASTORE_DOMAIN/task_manager_attachments/{taskId}-{fileName}
MEDIA_FOLDER/DATASTORE_DOMAIN/task_manager_comment_attachments/{commentId}-{fileName}
```

Metadata is saved in `task_manager_task_attachments` or `task_manager_comment_attachments`, so existing UI download URLs continue to work.

## Validation Commands

From repository root:

```powershell
C:\xampp\php\php.exe -l davvag-core\localhost\apps\task-tracker\services\taskapi\service.php
C:\xampp\php\php.exe -l davvag-core\localhost\apps\task-tracker\services\TaskEmailClient\service.php
node --check davvag-core\localhost\apps\task-tracker\components\projects\script.js
node --check davvag-core\localhost\apps\task-tracker\components\tasks\script.js
node --check davvag-core\localhost\apps\task-tracker\components\task-view\script.js
Get-Content davvag-core\localhost\apps\task-tracker\app.json -Raw | ConvertFrom-Json | Out-Null
Get-ChildItem davvag-core\localhost\schemas\task_manager_*.json | ForEach-Object { Get-Content $_.FullName -Raw | ConvertFrom-Json | Out-Null }
```

## Runtime Note

The repository root `config.json` may resolve the active tenant to a folder outside this repo depending on `RESOURCE_LOCATION` and `LOCAL_DEV_HOST`.

This scaffold was created in:

```text
C:\xampp\htdocs\git\davvag-core\davvag-core\localhost
```

Before browser testing, confirm `TENANT_RESOURCE_LOCATION` resolves to this tenant or sync the app and schemas to the active tenant.
