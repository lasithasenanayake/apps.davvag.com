# Davvag Flow Designer Agent Guide

This document is for AI agents and developers extending the Davvag Flow Designer app in this folder.

## App Identity

Runtime app code:

```text
davvag-flow-designer
```

Display name:

```text
Davvag Flow Designer
```

Primary folder:

```text
davvag-core/localhost/apps/davvag-flow-designer
```

The app edits workflow JSON files stored outside this app, under:

```text
davvag-core/localhost/davvag-flow
```

The workflow runtime plugin lives at:

```text
davvag-core/localhost/plugins/davvag-flow
```

## Folder Map

```text
apps/davvag-flow-designer/
  AGENTS.md
  app.json
  app.php
  components/
    workflow-designer/
      component.json
      partial.html
      script.js
      workflow-designer.css
  services/
    flow-designer-api/
      component.json
      script.js
      service.php
```

## Registration

The app is registered in:

```text
davvag-core/localhost/tenant.json
davvag-core/localhost/sysadmin.json
```

It is currently intended for sysadmin use. Do not add it to `anonymous.json` unless the workflow files are safe for public editing.

`app.json` maps:

```text
startupComponent: workflow-designer
onLoad: flow-designer-api
/ -> workflow-designer
```

`app.php` is intentionally small. It mounts:

```html
<main webdock-component="workflow-designer"></main>
```

and loads:

```html
<script src="/lib/webdock.js" webdockapp="davvag-flow-designer"></script>
```

## Runtime Dependencies

Frontend:

- DAVVAG Webdock.
- jQuery, provided by `/lib/jquery.js`.
- No third-party canvas, graph, or drag/drop library is used.
- The component uses direct DOM/jQuery rendering inside `exports.onReady`, not Vue templates.

Backend:

- PHP service component naming follows Webdock convention.
- `flow-designer-api/component.json` maps service methods to `service.php`.
- Handler class is `davvag_flow_designer\FlowDesignerService`.

## Component Overview

Primary UI files:

```text
components/workflow-designer/partial.html
components/workflow-designer/script.js
components/workflow-designer/workflow-designer.css
```

The UI has three main regions:

| Region | Purpose |
| --- | --- |
| Left panel | Workflow metadata, file list, toolbox, service app filter. |
| Center stage | Dotted workflow canvas, nodes, success/fail links. |
| Right panel | Node inspector and read-only workflow JSON preview. |

Header actions:

| Action | Selector | Notes |
| --- | --- | --- |
| New | `data-new-workflow` | Creates an unsaved workflow in memory. |
| Save | `data-save-workflow` | Persists JSON through `SaveWorkflow`. |
| Run | `data-run-workflow` | Executes the current in-memory workflow with test input JSON. |
| Delete | `data-delete-workflow` | Deletes the selected workflow file. |
| Maximize/Restore | `data-toggle-maximize` | Only maximizes the designer component, not Webdock globally. |

## Frontend State

State lives in `script.js`:

```javascript
var state = {
    workflows: [],
    templates: [],
    namespaces: [],
    workflow: null,
    filename: "new-flow",
    namespace: "",
    selectedNodeId: "",
    linking: null,
    templateSearch: "",
    appServiceFilter: "",
    windowMaximized: false,
    runInput: "{}",
    runResult: "No run yet.",
    runSuccess: null
};
```

Important state fields:

| Field | Meaning |
| --- | --- |
| `workflows` | File previews returned by the backend. |
| `templates` | Toolbox templates returned by the backend. |
| `workflow` | Current editable workflow object. |
| `filename` | Current workflow id without `.json`. |
| `namespace` | Optional subfolder under `davvag-flow`. |
| `selectedNodeId` | Node currently shown in the inspector. |
| `linking` | Temporary source node and edge type while creating a link. |
| `templateSearch` | Toolbox text filter. |
| `appServiceFilter` | App-code filter for `App Services` templates. |
| `windowMaximized` | Whether the designer has the full-window CSS class. |
| `runInput` | Editable JSON object passed into a workflow test run. |
| `runResult` | Last workflow execution result or error display. |
| `runSuccess` | `true`, `false`, or `null` for result panel styling. |

## Service API

Backend descriptor:

```text
services/flow-designer-api/component.json
```

Backend implementation:

```text
services/flow-designer-api/service.php
```

Class:

```php
davvag_flow_designer\FlowDesignerService
```

Methods:

| Webdock call | PHP method | HTTP | Purpose |
| --- | --- | --- | --- |
| `DesignerData` | `getDesignerData` | GET | Returns workflow list, namespace list, and toolbox templates. |
| `ListWorkflows` | `getListWorkflows` | GET | Returns workflow and namespace lists. |
| `LoadWorkflow` | `postLoadWorkflow` | POST | Loads one workflow JSON file. |
| `SaveWorkflow` | `postSaveWorkflow` | POST | Validates and saves one workflow JSON file. |
| `RunWorkflow` | `postRunWorkflow` | POST | Executes the current workflow object with test input JSON. |
| `DeleteWorkflow` | `postDeleteWorkflow` | POST | Deletes one workflow JSON file. |

Service responses return a nested result object through Webdock:

```javascript
response.success === true
response.result.success === true
```

Use `serviceResult(response)` in `script.js` when adding service calls.

## Test Run

The designer can execute the current in-memory workflow object without saving it first.

Frontend selectors:

```text
data-run-workflow
data-run-input-json
data-use-input-schema
data-copy-run-result
data-run-result
```

Frontend functions:

| Function | Purpose |
| --- | --- |
| `sampleInputFromSchema(schema)` | Builds a sample JSON object from `workflow.inputData`. |
| `useInputSchema()` | Fills the run input editor from `workflow.inputData`. |
| `runWorkflow()` | Calls `flow-designer-api/RunWorkflow`. |
| `copyRunResult()` | Copies the last displayed execution result. |

`runWorkflow()` sends:

```json
{
  "namespace": "",
  "filename": "english",
  "workflow": {},
  "inputData": {}
}
```

`workflow` is the current edited object from the canvas. This means unsaved changes can be tested.

Backend behavior:

1. Validates the workflow shape.
2. Requires the Davvag Flow runtime from `plugins/davvag-flow/flow.php`.
3. Initializes `excutionStack`, `outData`, and `inputData.workflowid`.
4. Calls `DavvagFlow::Execute($namespace, $flowid, $inputData, null, $executeData, $workflow)`.
5. Returns the final execution object when successful.
6. Returns error details plus partial execution data when the workflow throws.

Result panel display:

- Successful run: shows the workflow execution object.
- Failed run: shows `{ runSuccess: false, error, result }`.

Important warning: test runs execute real workflow nodes. Service nodes, email nodes, API nodes, and class nodes can have side effects.

## Workflow File Resolution

Root workflow:

```text
davvag-flow/{flowid}.json
```

Namespaced workflow:

```text
davvag-flow/{namespace}/{flowid}.json
```

The backend accepts:

```json
{
  "namespace": "",
  "filename": "english"
}
```

or:

```json
{
  "namespace": "davvag-attributes",
  "filename": "testflow.json"
}
```

The backend strips `.json` and validates both namespace and filename with:

```text
letters, numbers, dots, underscores, hyphens
```

Do not allow path separators in filename or namespace.

## Workflow JSON Contract

The runtime workflow format is owned by:

```text
plugins/davvag-flow/flow.php
```

Top-level fields:

| Field | Meaning |
| --- | --- |
| `name` | Workflow display name. |
| `start_up_node` | First node executed by the runtime. |
| `inputData` | Optional input schema/description. |
| `{nodeId}` | Each executable node keyed by id. |
| `__designer` | Designer-only metadata for canvas positions. |

Reserved top-level keys in the frontend:

```javascript
name
start_up_node
inputData
__designer
```

Everything else with an object value and `urntype` is treated as a node.

## Designer Metadata

Canvas positions are stored in:

```json
{
  "__designer": {
    "nodes": {
      "start": {
        "x": 120,
        "y": 120
      }
    }
  }
}
```

This keeps the original workflow runtime contract intact. The runtime should ignore `__designer` because it is never referenced as a step.

When adding nodes or changing node ids, always update:

```text
workflow.__designer.nodes
success/fail references
start_up_node if needed
```

## Node Types

The designer supports the same node types as `plugins/davvag-flow/flow.php`.

### Class Node

Calls a PHP class method from:

```text
plugins/davvag-flow/lib
```

Shape:

```json
{
  "urntype": "class",
  "file": "tags.php",
  "class": "tags",
  "method": {
    "name": "addTag",
    "params": [],
    "return": true,
    "returnobj": "tag"
  },
  "success": "next-node",
  "fail": "nodefail"
}
```

### Service Node

Calls a Webdock app service component.

Shape:

```json
{
  "urntype": "service",
  "appCode": "task-tracker",
  "componentCode": "taskapi",
  "method": {
    "type": "post",
    "name": "SaveTask",
    "params": [],
    "return": true,
    "returnobj": "saved_task"
  },
  "success": "next-node",
  "fail": "nodefail"
}
```

The runtime calls:

```text
apps/{appCode}/{component location}/{componentCode}/service.php
{method.type}{method.name}()
```

Example:

```text
postSaveTask($req, $res)
```

### Create Object Node

Builds an object from literal values or resolved object references.

Shape:

```json
{
  "urntype": "create_object",
  "method": {
    "type": "create_object",
    "name": "BuildObject",
    "return": true,
    "returnobj": "result"
  },
  "variables": [
    {
      "name": "message",
      "value": "Done"
    }
  ]
}
```

## Links

Only two link fields exist in the runtime:

```text
success
fail
```

The UI creates links from node handles:

| Handle | Field |
| --- | --- |
| `S` | `success` |
| `F` | `fail` |

Link creation flow:

1. Click a node `S` or `F` handle.
2. Click the target node.
3. The source node gets `success` or `fail` set to the target id.

Delete behavior:

- Deleting a node removes stale `success` and `fail` references from other nodes.
- If the deleted node was `start_up_node`, the first remaining node becomes startup.

## Toolbox Discovery

Toolbox templates are returned by:

```php
FlowDesignerService::toolbox()
```

Current categories:

| Category | Source |
| --- | --- |
| `Starter` | Built-in blank class, service, and create-object templates. |
| `Plugin Classes` | Methods discovered from `plugins/davvag-flow/lib/*.php`. |
| `App Services` | Service methods discovered from tenant `apps/*/app.json` and service `component.json` files. |

Class template discovery:

- Uses `token_get_all`.
- Reads PHP class and function tokens.
- Skips magic methods starting with `__`.

Service template discovery:

- Scans all tenant app descriptors under `apps`.
- Finds components where `type === "service"`.
- Reads each service `component.json`.
- Reads `serviceHandler.methods`.
- Skips `davvag-flow-designer` itself to avoid self-calling templates.

Frontend toolbox filters:

| Control | State | Behavior |
| --- | --- | --- |
| Search | `templateSearch` | Searches category, label, urntype, and service app code. |
| App Services | `appServiceFilter` | Filters only `App Services` by `node.appCode`. |

## Inspector

The inspector is generated by:

```javascript
renderInspector()
```

It edits:

- Node id.
- Node type.
- Success/fail links.
- Type-specific fields.
- Params JSON.
- Variables JSON.
- Raw node JSON.

Inspector helpers:

| Function | Purpose |
| --- | --- |
| `normalizeNodeShape(node)` | Ensures `method`, `params`, return flags, and variables are present. |
| `changeNodeId(newId)` | Renames nodes and rewrites links. |
| `changeNodeType(type)` | Converts node shape between class, service, and create_object. |
| `applyJsonPath(path, textarea)` | Applies JSON editors to node fields. |
| `deleteSelectedNode()` | Removes the selected node and stale links. |
| `duplicateNode()` | Copies selected node without success/fail links. |

When adding new editable fields, update both:

```text
renderInspector()
bindEvents()
```

## Canvas

Canvas constants:

```javascript
var nodeWidth = 236;
var nodeHeight = 106;
```

Canvas size:

```text
2400 x 1500
```

Key functions:

| Function | Purpose |
| --- | --- |
| `renderCanvas()` | Recreates DOM node cards. |
| `drawConnections()` | Draws SVG Bezier links. |
| `appendEdge()` | Adds one success/fail edge. |
| `nodePosition(nodeId)` | Reads or initializes `__designer.nodes[nodeId]`. |
| `canvasPoint(event)` | Converts drop event to canvas coordinates. |

Node dragging updates:

```text
workflow.__designer.nodes[nodeId].x
workflow.__designer.nodes[nodeId].y
```

and redraws SVG connections.

## Maximize Button

The maximize button is local to the workflow designer only.

Selectors/classes:

```text
data-toggle-maximize
.flow-designer.is-window-maximized
body.flow-designer-window-open
```

Functions:

```javascript
toggleWindowMaximize()
setWindowMaximized(isMaximized)
```

Behavior:

- Toggles fixed full-window layout on the designer component.
- Updates `aria-label`, `title`, and `aria-pressed`.
- Redraws connections after layout change.
- `Esc` restores only when the designer is maximized.

Do not replace this with global Webdock window controls unless the shell provides a stable API for it.

## Backend Validation

Before saving, `validateWorkflow()` checks:

- `name` exists.
- `start_up_node` exists.
- `start_up_node` points to an existing node when nodes exist.
- Each node has `urntype`.
- `success` and `fail` links point to existing nodes.

This validation is intentionally light. It does not validate method signatures or PHP class existence for every node, because old workflows may contain custom shapes.

## Editing Rules

Preserve existing workflow fields whenever possible.

Important compatibility notes:

- Do not rename `start_up_node`; this spelling is used by the runtime.
- Do not rename `scopData` in workflow parameter references; this spelling is used by the runtime.
- Do not rename `excutionStack`; this spelling is used by the runtime output.
- Do not remove unknown fields from node JSON unless the user explicitly asks.
- Keep `__designer` optional so old workflows still load.

## Security Notes

The backend deliberately restricts workflow filenames and namespaces. Keep that restriction.

Never allow:

```text
../
..\
absolute paths
slashes inside filename or namespace
```

The app edits executable workflow definitions. Keep it limited to authenticated/admin groups unless access requirements change.

Avoid writing secrets to workflow JSON. Workflows should reference app services or protected config for secrets.

## Adding New Features

Recommended extension points:

| Feature | Likely file/function |
| --- | --- |
| Add a new node type | `renderInspector`, `normalizeNodeShape`, backend validation if needed. |
| Add toolbox metadata | `FlowDesignerService::template()` and discovery methods. |
| Add app/service filtering | `renderTemplates`, `renderAppServiceFilter`, `templateAppCode`. |
| Add canvas zoom/pan | `workflow-designer.css`, canvas event handlers, `drawConnections`. |
| Add import/export | Frontend header action plus service method if saving to disk. |
| Add execution/testing | New service method that calls `DavvagFlow::Execute` carefully. |
| Add permissions/audit | `flow-designer-api/service.php` save/delete methods. |

## Verification

Run JavaScript syntax check:

```powershell
node --check davvag-core\localhost\apps\davvag-flow-designer\components\workflow-designer\script.js
```

Run PHP syntax check with XAMPP PHP:

```powershell
C:\xampp\php\php.exe -l davvag-core\localhost\apps\davvag-flow-designer\services\flow-designer-api\service.php
```

Validate JSON descriptors:

```powershell
Get-Content davvag-core\localhost\apps\davvag-flow-designer\app.json | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\apps\davvag-flow-designer\components\workflow-designer\component.json | ConvertFrom-Json | Out-Null
Get-Content davvag-core\localhost\apps\davvag-flow-designer\services\flow-designer-api\component.json | ConvertFrom-Json | Out-Null
```

Smoke-test service discovery:

```powershell
C:\xampp\php\php.exe -r "require 'davvag-core/localhost/apps/davvag-flow-designer/services/flow-designer-api/service.php'; class R { public function Body($json=false) { return (object) array(); } } $s = new \davvag_flow_designer\FlowDesignerService(); $o = $s->getDesignerData(new R(), null); echo count($o->workflows) . ':' . count($o->toolbox);"
```

Expected discovery should return workflow count and toolbox count, for example:

```text
11:383
```

The exact toolbox count changes when app services or flow plugin classes are added.

## Manual QA Checklist

When XAMPP/Apache and a sysadmin session are available:

1. Open the app from Webdock as `davvag-flow-designer`.
2. Confirm workflow files load in the left file list.
3. Load `english.json` and confirm startup node is `reg-step-1`.
4. Filter `App Services` by an app name and confirm only that app's services are shown.
5. Drag a toolbox node to the canvas.
6. Move the node and confirm the JSON preview updates `__designer.nodes`.
7. Create `success` and `fail` links and confirm SVG lines redraw.
8. Edit params JSON and apply it.
9. Delete a node and confirm stale links are removed.
10. Use maximize and restore; confirm only the designer changes size.
11. Use `Use Schema` in Test Run and confirm input JSON is generated from `inputData`.
12. Run a harmless create-object workflow and confirm `outData` appears in the result.
13. Save a copy under a test filename and reload it.

## Known Constraints

- There is no graph layout engine; positions are manual.
- There is no undo/redo stack.
- Test Run executes the real workflow runtime and can trigger side effects.
- The JSON preview is read-only except through inspector fields.
- The designer stores canvas metadata in `__designer`.
- Backend validation checks workflow shape, not callable PHP signatures.
- Visual browser verification requires a running local XAMPP/Apache setup and an authorized Webdock session.

## Related Documentation

General Davvag Flow documentation:

```text
docs/davvag-flow.md
```

General app development documentation:

```text
docs/03-application-development.md
docs/11-app-developer-guide.md
```
