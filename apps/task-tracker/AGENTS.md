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
- Limits task assignee choices to profiles allocated on the project.
- Handles attachments with FileReader previews and `davvag-tools/davvag-file-uploader`.
- Navigates to `/task` for work-progress logging.

`components/task-view`:

- Shows one task.
- Uses a full-width Task Progress surface.
- Shows assignees and task attachments in the Task Progress summary.
- Keeps Discussion and Progress Timeline as expandable sections inside Task Progress, with Discussion above Progress Timeline.
- Logs work inside the Progress Timeline section with manual work date, time-only start/end fields, automatic duration, status, and progress.
- Provides threaded task discussion inside the Discussion section with comments, replies, and comment attachments.

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
POST SaveTask
POST DeleteTask
POST TaskDetails
POST SaveWorkLog
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

New email threads create `task_manager_tasks` rows with `emailMessageId`, `emailFromEmail`, and `emailFromName`, then assign the sender profile in `task_manager_task_assignees`. Replies are matched by `Message-ID`, `In-Reply-To`, or `References`; matching messages are saved to `task_manager_task_comments` with `emailMessageId` and `emailFromEmail`.

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
