# DAVVAG Tenant Development Guide

This README is for AI agents creating new DAVVAG applications inside this tenant:

```text
\davvag-core\davvag-core\{tenant}
```

Use this document when you need to create a new application, add services, define workflows, or create schema-driven database structures.

## 1. Tenant Structure

Important tenant folders:

```text
apps/          Applications live here.
davvag-flow/   Workflow JSON files live here.
global/        Global config and templates for this tenant.
plugins/       Tenant-local PHP plugins.
schemas/       Database schema and raw query definitions.
```

Important tenant files:

```text
tenant.json       Registers installed apps and startup apps.
config.json       Overrides root framework config for this tenant.
anonymous.json    App access for anonymous users.
web_user.json     App access for authenticated web users.
sysadmin.json     App access for administrators.
sossgrid.conf     MySQL connection config when DB_CONFIG_FILE points here.
```

The framework resolves this tenant through `TENANT_RESOURCE_LOCATION`. Most paths in this README are relative to this folder.

## 2. Application Lifecycle

A DAVVAG application is created under:

```text
apps/{appCode}/
```

Minimum app structure:

```text
apps/my-new-app/
  app.json
  app.php
  components/
    main-view/
      component.json
      script.js
      partial.html
      main-view.css
  services/
    api/
      component.json
      script.js
      service.php
```

Lifecycle steps:

1. Create the app folder under `apps/`.
2. Add `app.json`.
3. Add `app.php` if the app can be launched directly.
4. Add frontend components and backend services.
5. Register all components in `app.json`.
6. Register the app in `tenant.json`.
7. Add app access to group JSON files such as `web_user.json` or `sysadmin.json`.
8. Add schemas under `schemas/` for new database namespaces.
9. Test through `/components/...` endpoints.

Use lowercase hyphenated app codes, for example:

```text
customer-portal
inventory-admin
davvag-sample-app-1
```

## 3. Create a New Application

Create:

```text
apps/my-new-app/app.json
```

Template:

```json
{
  "components": {
    "main-view": {
      "type": "component",
      "location": "components"
    },
    "api": {
      "type": "service",
      "location": "services"
    }
  },
  "description": {
    "title": "My New App",
    "author": "DAVVAG",
    "version": "0.1",
    "icon": "appicon.png"
  },
  "tags": ["showindock"],
  "configuration": {
    "webdock": {
      "startupComponent": "main-view",
      "onLoad": ["api"],
      "routes": {
        "partials": {
          "/": "main-view"
        }
      }
    }
  }
}
```

Key fields:

| Field | Purpose |
| --- | --- |
| `components` | Registers frontend components and backend services. |
| `type` | Usually `component` or `service`. |
| `location` | Folder under the app where the component lives. |
| `description` | Metadata used by app lists, dock, and CMS views. |
| `tags` | App discovery tags such as `showindock` or `showincms`. |
| `configuration.webdock.startupComponent` | Default UI component for the app. |
| `configuration.webdock.onLoad` | Service/shell components loaded when the app starts. |
| `configuration.webdock.routes.partials` | Route-to-component mapping used by app shells. |

Register the app in:

```text
tenant.json
```

Add:

```json
{
  "my-new-app": {
    "version": "latest"
  }
}
```

Do not remove existing app entries.

Grant group access by adding the same app entry to one or more group files:

```text
anonymous.json
web_user.json
sysadmin.json
```

## 4. Create an App Entry File

Create:

```text
apps/my-new-app/app.php
```

Use an existing app `app.php` as a template when possible. The page should load the required frontend libraries and include `webdock.js` with the current app code.

Typical requirement:

```html
<script src="lib/jquery.js"></script>
<script src="lib/webdock.js" webdockapp="my-new-app"></script>
```

Then add placeholders for UI components:

```html
<div webdock-component="main-view"></div>
```

If the app uses an existing shell such as `dock` or `davvag-cms`, follow that shell's existing pattern instead of inventing a new page shell.

## 5. Create a Frontend Component

Create:

```text
apps/my-new-app/components/main-view/component.json
apps/my-new-app/components/main-view/script.js
apps/my-new-app/components/main-view/partial.html
apps/my-new-app/components/main-view/main-view.css
```

`component.json` template:

```json
{
  "name": "main-view",
  "description": "Main UI component",
  "author": "DAVVAG",
  "version": "0.1",
  "resources": {
    "files": [
      {
        "type": "mainScript",
        "location": "script.js"
      },
      {
        "type": "mainView",
        "location": "partial.html"
      }
    ],
    "css": [
      {
        "type": "css",
        "location": "main-view.css"
      }
    ]
  }
}
```

`script.js` template:

```javascript
WEBDOCK.component().register(function (exports) {
    var scope;
    var api;

    var bindData = {
        items: [],
        form: {},
        errors: []
    };

    exports.vue = {
        data: bindData,
        methods: {
            save: save
        }
    };

    exports.onReady = function (element) {
        scope = bindData;
        api = exports.getComponent("api");
    };

    function save() {
        scope.errors = [];

        if (!api) {
            scope.errors.push("Service component not loaded.");
            return;
        }

        api.services.Save(scope.form).then(function (response) {
            if (response.success) {
                scope.items.push(response.result);
                scope.form = {};
            } else {
                scope.errors.push("Save failed.");
            }
        }).error(function () {
            scope.errors.push("Save request failed.");
        });
    }
});
```

`partial.html` template:

```html
<section id="my-new-app-main">
  <form v-on:submit.prevent="save">
    <input type="text" v-model="form.title" placeholder="Title">
    <button type="submit">Save</button>
  </form>

  <p v-for="error in errors">{{ error }}</p>

  <ul>
    <li v-for="item in items">{{ item.title }}</li>
  </ul>
</section>
```

Notes:

1. `webdock.js` injects `mainView` HTML into `[webdock-component]`.
2. `exports.getComponent("api")` retrieves a service component loaded by `configuration.webdock.onLoad`.
3. Service methods are exposed under `api.services.{methodName}`.

## 6. Create a Service

Create:

```text
apps/my-new-app/services/api/component.json
apps/my-new-app/services/api/script.js
apps/my-new-app/services/api/service.php
```

`component.json` template:

```json
{
  "name": "api",
  "description": "Application API service",
  "author": "DAVVAG",
  "version": "0.1",
  "resources": {
    "files": [
      {
        "type": "mainScript",
        "location": "script.js"
      }
    ]
  },
  "serviceHandler": {
    "file": "service.php",
    "class": "my_new_app\\ApiService",
    "methods": {
      "Save": {
        "method": "POST"
      },
      "List": {
        "method": "GET"
      },
      "Delete": {
        "method": "POST"
      }
    }
  },
  "transformers": {}
}
```

`script.js` for a pure service can be minimal:

```javascript
WEBDOCK.component().register(function (exports) {
});
```

`service.php` template:

```php
<?php
namespace my_new_app;

require_once(PLUGIN_PATH . "/sossdata/SOSSData.php");

class ApiService {
    public function postSave($req, $res) {
        $data = $req->Body(true);
        return SOSSData::Insert("my_new_app_items", $data);
    }

    public function getList($req, $res) {
        $query = isset($_GET["query"]) ? $_GET["query"] : "";
        return SOSSData::Query("my_new_app_items", $query);
    }

    public function postDelete($req, $res) {
        $data = $req->Body(true);
        return SOSSData::Delete("my_new_app_items", $data);
    }
}
?>
```

Service method naming rule:

```text
HTTP method + ucwords(handlerName)
```

Examples:

| URL | PHP method |
| --- | --- |
| `GET /components/my-new-app/api/service/List` | `getList($req, $res)` |
| `POST /components/my-new-app/api/service/Save` | `postSave($req, $res)` |
| `POST /components/my-new-app/api/service/Delete` | `postDelete($req, $res)` |

If no exact method exists, the framework tries:

```php
__handle($req, $res)
```

Service response rule:

1. Return plain PHP objects, arrays, or strings.
2. The framework wraps successful service returns as `{ "success": true, "result": ... }`.
3. Use `$res->SetError("message")` for service-level failures.

## 7. Add Service Dependencies

If a service needs tenant-local or global plugins, declare them in `component.json`:

```json
{
  "dependency": {
    "plugins": [
      {
        "type": "php",
        "plugin_location": "local",
        "location": "/profile/profile.php"
      },
      {
        "type": "php",
        "plugin_location": "global",
        "location": "/sossdata/SOSSData.php"
      }
    ]
  }
}
```

Rules:

| `plugin_location` | Loads from |
| --- | --- |
| `global` | Root framework `plugins/`. |
| `local` | Tenant `plugins/`. |
| other/default | Explicit path in `location`. |

Prefer declared dependencies when possible. Direct `require_once()` is acceptable for common framework facades like `SOSSData`.

## 8. Create Database Structure With Schemas

Create schema file:

```text
schemas/my_new_app_items.json
```

Template:

```json
{
  "fields": [
    {
      "fieldName": "id",
      "dataType": "int",
      "annotations": {
        "isPrimary": true,
        "autoIncrement": true
      }
    },
    {
      "fieldName": "title",
      "dataType": "java.lang.String",
      "annotations": {
        "maxLen": 255,
        "encoding": "utf8"
      }
    },
    {
      "fieldName": "status",
      "dataType": "java.lang.String",
      "annotations": {
        "maxLen": 50,
        "default": "Active"
      }
    },
    {
      "fieldName": "amount",
      "dataType": "decimal",
      "annotations": {
        "decimalPoints": "10,2"
      }
    },
    {
      "fieldName": "metadata",
      "dataType": "object",
      "annotations": {
        "maxLen": 2000
      }
    }
  ]
}
```

Supported common data types:

```text
int
float
double
short
long
decimal
java.lang.String
java.util.Date
boolean
object
```

Schema behavior:

1. `SOSSData` loads schemas from `schemas/{namespace}.json`.
2. The MySQL connector creates missing tables on first use.
3. Missing columns can be added when the table is used.
4. System columns are added automatically:

```text
sysversionid
syscreated
sysupdated
sysviewobject
syscreatedby
syslastupdatedby
```

Use the schema namespace as the first argument to `SOSSData`:

```php
SOSSData::Insert("my_new_app_items", $data);
SOSSData::Query("my_new_app_items", "status:Active");
```

Query syntax:

```text
field:value,anotherField:anotherValue
```

Example:

```php
SOSSData::Query("my_new_app_items", "status:Active,title:Test");
```

Security note: the current SQL connector builds SQL strings directly. Validate user input before passing it into query strings.

## 9. Create Raw Query Helpers

For complex queries, create a schema with `rawquery`, or place SQL helpers under:

```text
schemas/mysqlquery/
schemas/query/
```

Service usage:

```php
$params = new \stdClass();
$params->parameters = new \stdClass();
$params->parameters->status = "Active";

return SOSSData::ExecuteRaw("my_new_app_report", $params);
```

Use existing files in `schemas/mysqlquery/` as examples.

## 10. Create a Workflow

Workflow files live in:

```text
davvag-flow/
davvag-flow/{namespace}/
```

Create:

```text
davvag-flow/my-new-app/create-item.json
```

Workflow template using an app service:

```json
{
  "name": "Create Item Workflow",
  "start_up_node": "save-item",
  "inputData": [
    {
      "name": "title",
      "datatype": "string"
    },
    {
      "name": "status",
      "datatype": "string"
    }
  ],
  "save-item": {
    "urntype": "service",
    "appCode": "my-new-app",
    "componentCode": "api",
    "method": {
      "type": "post",
      "name": "Save",
      "params": [
        {
          "name": "postData",
          "type": "object",
          "value": "inputData"
        }
      ],
      "return": true,
      "returnobj": "savedItem"
    },
    "success": "build-result",
    "fail": "nodefail"
  },
  "build-result": {
    "urntype": "create_object",
    "method": {
      "type": "create_object",
      "name": "Result",
      "return": true,
      "returnobj": "result"
    },
    "variables": [
      {
        "name": "message",
        "value": "Item saved"
      },
      {
        "name": "item",
        "type": "object",
        "value": "scopData.outData.savedItem"
      }
    ],
    "fail": "nodefail"
  },
  "nodefail": {
    "urntype": "class",
    "file": "test.php",
    "class": "test",
    "method": {
      "name": "fail",
      "params": [
        {
          "inputData": "title"
        }
      ],
      "return": true,
      "returnobj": "failed"
    }
  }
}
```

Execute from PHP:

```php
require_once(PLUGIN_PATH_LOCAL . "/davvag-flow/flow.php");

$input = new \stdClass();
$input->title = "Test";
$input->status = "Active";

$result = DavvagFlow::Execute("my-new-app", "create-item", $input);
```

Workflow path resolution:

| Call | File |
| --- | --- |
| `DavvagFlow::Execute(null, "testflow", $input)` | `davvag-flow/testflow.json` |
| `DavvagFlow::Execute("my-new-app", "create-item", $input)` | `davvag-flow/my-new-app/create-item.json` |

Supported tenant workflow node types:

| `urntype` | Purpose |
| --- | --- |
| `service` | Call a DAVVAG app service component. |
| `class` | Load an activity class from `plugins/davvag-flow/lib`. |
| `create_object` | Build an output object from constants or workflow data. |

Workflow output:

1. Returned objects are stored in `outData.{returnobj}`.
2. Debug and error logs are stored in `excutionStack`.
3. The original input is returned as `inputData`.

## 11. Use a Workflow From a Service

Example service method:

```php
public function postRunWorkflow($req, $res) {
    require_once(PLUGIN_PATH_LOCAL . "/davvag-flow/flow.php");

    $data = $req->Body(true);
    return \DavvagFlow::Execute("my-new-app", "create-item", $data);
}
```

Add it to the service descriptor:

```json
{
  "RunWorkflow": {
    "method": "POST"
  }
}
```

Endpoint:

```text
POST /components/my-new-app/api/service/RunWorkflow
```

Handler:

```php
postRunWorkflow($req, $res)
```

## 12. Add Transformers Instead of PHP Services

For simple CRUD, a service component can use `transformers`:

```json
{
  "transformers": {
    "Save": {
      "method": "POST",
      "route": "/Save",
      "destUrl": "SOSSData",
      "destMethod": "insert",
      "namespace": "my_new_app_items"
    },
    "List": {
      "method": "GET",
      "route": "/List",
      "destUrl": "SOSSData",
      "destMethod": "query",
      "namespace": "my_new_app_items"
    }
  }
}
```

Transformer endpoint:

```text
POST /components/my-new-app/api/transform/Save
GET  /components/my-new-app/api/transform/List?query=status:Active
```

Use PHP service handlers when logic is complex. Use transformers for simple forwarding or simple datastore operations.

## 13. Required Registration Checklist

For every new application:

```text
[ ] apps/{appCode}/app.json exists.
[ ] apps/{appCode}/app.php exists if launchable.
[ ] Every component in app.json has a matching component.json.
[ ] Service component class and namespace match serviceHandler.class.
[ ] Service PHP method names match HTTP method plus handler name.
[ ] App is added to tenant.json.
[ ] App is added to sysadmin.json.
[ ] App is added to web_user.json or anonymous.json when appropriate.
[ ] Schemas exist for every SOSSData namespace.
[ ] Workflows exist under davvag-flow/ when referenced.
[ ] Plugin dependencies are declared or required.
```

## 14. Test URLs

After creating an app, test:

```text
GET  /components/object/appdescriptor/my-new-app
GET  /components/my-new-app/main-view/object?object=desc
GET  /components/my-new-app/main-view/file/script.js
GET  /components/my-new-app/main-view/file/partial.html
GET  /components/my-new-app/api/object?object=desc
POST /components/my-new-app/api/service/Save
GET  /components/my-new-app/api/service/List
```

If the app should be visible in the current user's launcher:

```text
GET /components/object/apps
```

## 15. Common Failure Points

| Symptom | Likely cause |
| --- | --- |
| `Startup app is configured but not installed` | App exists in startup config but is missing from `tenant.json > apps`. |
| `Application descriptor not found` | Missing `apps/{appCode}/app.json` or incorrect `appCode`. |
| `Component not found in app descriptor` | Component folder exists but is not registered in `app.json`. |
| `Component descriptor not found` | Wrong `location` in `app.json`, wrong folder name, or missing `component.json`. |
| `Class not found in PHP implementation` | `serviceHandler.class` does not match PHP namespace/class. |
| `Method not found in PHP implementation` | Handler method name does not match `get{Name}` or `post{Name}`. |
| `No Schema File Found` | Missing `schemas/{namespace}.json`. |
| App not visible | Group JSON file does not include the app or auth access denies it. |
| CORS failure | Credentialed request is using wildcard `Access-Control-Allow-Origin`. |

## 16. AI Agent Rules for This Tenant

Use these rules when generating code:

1. Keep new application code under `apps/{appCode}`.
2. Keep database schemas under `schemas/`.
3. Keep workflows under `davvag-flow/`.
4. Keep reusable tenant-only PHP code under `plugins/`.
5. Register apps in `tenant.json` and group JSON files.
6. Use `SOSSData` for database access.
7. Use service handlers for business logic.
8. Use transformers for simple datastore or REST forwarding.
9. Preserve existing folder names, including `apps` and `templetes`.
10. Do not expose tenant plugins or config files directly over HTTP.
11. Do not copy secrets from `config.json` or `global/config` into frontend code.
12. Prefer following `davvag-sample-app-1` when unsure.

## 17. Minimal End-to-End App Recipe

To create a working app named `my-new-app`:

1. Create `apps/my-new-app/app.json`.
2. Create `apps/my-new-app/app.php`.
3. Create `apps/my-new-app/components/main-view/component.json`.
4. Create `apps/my-new-app/components/main-view/script.js`.
5. Create `apps/my-new-app/components/main-view/partial.html`.
6. Create `apps/my-new-app/services/api/component.json`.
7. Create `apps/my-new-app/services/api/script.js`.
8. Create `apps/my-new-app/services/api/service.php`.
9. Create `schemas/my_new_app_items.json`.
10. Add `my-new-app` to `tenant.json`.
11. Add `my-new-app` to `sysadmin.json`.
12. Add `my-new-app` to `web_user.json` if normal users should see it.
13. Optional: create `davvag-flow/my-new-app/create-item.json`.
14. Test the `/components/...` routes.

This is the preferred pattern for AI-generated DAVVAG applications.
