# AI Agent Creator

AI Agent Creator is the DAVVAG app for building, saving, testing, and running agents.
It lets you choose an LLM provider, define the agent identity and system prompt, and attach runtime skills that can access tenant data or call external services before the model replies.

## What The App Does

- Builds agent configurations for OpenAI, Ollama, LM Studio, Google AI, or a custom API.
- Saves agents with a workflow address and a system identity.
- Tests a saved agent from the creator console.
- Runs runtime skills before the model is called.
- Logs usage and errors into DAVVAG data schemas when the related plugins are available.

## Main Parts

- `components/creator-console` is the UI where you create and test agents.
- `services/creator-api` is the runtime service that validates config, saves agents, runs skills, and talks to the provider.
- `assets/docs` is the place for local documentation like this file.

## Service And Data Flow

The app is already wired for more than prompt-only agents.

1. The creator console submits the agent form to `creator-api`.
2. The service validates the provider settings, prompt, parameters, and skills.
3. When you save an agent, it stores the configuration and links it to a profile and system user.
4. When the agent runs, the service prepares runtime context from:
   - `message`
   - `profile`
   - `session`
   - `flow`
   - `connector`
   - `payload`
   - `now`
5. Skills are executed first.
6. The model gets the startup prompt plus the profile, session, and executed skill results.
7. The reply and session history are saved.

## Built-In Runtime Skills

The service currently supports two skill types:

- `data_query`
- `service_call`

### `data_query`

Use this when the agent needs to search tenant data before answering.

Supported fields:

- `code`
- `name`
- `type`: must be `data_query`
- `enabled`
- `runMode`: `always`, `triggered`, or `manual`
- `description`
- `triggerKeywords`
- `source`: usually `json_file`
- `dataFile`: file name inside tenant data storage
- `queryFields`: fields used for matching
- `limit`: 1 to 25
- `data`: optional inline records array

How it works:

- If `data` is provided, the service searches those inline records.
- Otherwise it reads the JSON file from the tenant data area.
- The service matches the message against `queryFields`.
- The matched records are passed to the model as trusted context.

### `service_call`

Use this when the agent needs to call another DAVVAG service or an external HTTP endpoint.

Supported fields:

- `code`
- `name`
- `type`: must be `service_call`
- `enabled`
- `runMode`: `always`, `triggered`, or `manual`
- `description`
- `triggerKeywords`
- `method`: `GET`, `POST`, `PUT`, or `PATCH`
- `url`
- `headers`
- `bodyTemplate`
- `timeoutSeconds`: 2 to 60

How it works:

- The service renders templates in the URL, headers, and body.
- It uses the current runtime context to replace placeholders.
- The HTTP response is captured and passed back as skill output.
- The model is told not to claim success unless the service call skill succeeded.

## Template Variables

You can use `{{...}}` placeholders inside service URLs, headers, and body templates.

Common values available at runtime:

- `{{message}}`
- `{{profile.profileId}}`
- `{{profile.externalId}}`
- `{{session.sessionId}}`
- `{{session.context}}`
- `{{flow.flowCode}}`
- `{{connector.code}}`
- `{{payload}}`
- `{{now}}`

Example:

```json
{
  "profileId": "{{profile.profileId}}",
  "sessionId": "{{session.sessionId}}",
  "message": "{{message}}"
}
```

## How To Add A Skill

You can add skills directly in the console under **Runtime Skills**, or paste the JSON into the `Skills JSON` field.

You can also open the **Visual Builder** popup, which:

- Reads the current `Skills JSON` value and decodes it into a list
- Lets you inspect and edit one skill at a time
- Lets you add, duplicate, or delete skills visually
- Writes the updated array back into the `Skills JSON` field when you apply changes
- Can reload the raw JSON if you edit it by hand outside the popup

### Option 1: Use The Buttons

- Click **Data Query** to insert a `data_query` template.
- Click **Service Call** to insert a `service_call` template.
- Edit the generated JSON to match your data source or endpoint.

### Option 2: Edit The JSON Manually

The `skills` field must be a JSON array.

Example `data_query` skill:

```json
[
  {
    "code": "lookup-products",
    "name": "Lookup products",
    "type": "data_query",
    "enabled": true,
    "runMode": "triggered",
    "description": "Search tenant JSON data for products.",
    "triggerKeywords": ["product", "price", "stock"],
    "dataFile": "products.json",
    "queryFields": ["name", "sku", "description"],
    "limit": 5
  }
]
```

Example `service_call` skill:

```json
[
  {
    "code": "create-order",
    "name": "Create order",
    "type": "service_call",
    "enabled": true,
    "runMode": "triggered",
    "description": "Create an order when the customer asks to buy.",
    "triggerKeywords": ["order", "buy", "purchase"],
    "method": "POST",
    "url": "http://localhost/git/davvag-core/components/sales/order-api/service/CreateOrder",
    "headers": {
      "Content-Type": "application/json"
    },
    "bodyTemplate": {
      "profileId": "{{profile.profileId}}",
      "customerRef": "{{profile.externalId}}",
      "sessionId": "{{session.sessionId}}",
      "message": "{{message}}"
    }
  }
]
```

## Skill Execution Rules

- `enabled: false` means the skill will not run.
- `runMode: always` runs every time.
- `runMode: triggered` runs when the message matches a trigger keyword.
- `runMode: manual` is stored but skipped by the runtime executor.
- If a triggered skill has no keywords, `data_query` skills can still run, but `service_call` skills need an explicit trigger or `always`.

## Tenant Data Access

`data_query` skills can read tenant JSON files through the app storage layer.

Recommended pattern:

- Place a JSON file in the tenant data area used by the app.
- Keep the file as an array of records when possible.
- Point `dataFile` to that file name.
- Set `queryFields` to the fields you want searched.

The service resolves the file through the tenant data path and prevents path traversal or absolute path injection.

## What The Creator Console Sends

The console submits the full agent payload, including:

- agent code and name
- description and capabilities
- provider settings
- startup prompt
- temperature, max tokens, and streaming
- skills JSON

When you save an agent, the service also prepares workflow metadata so other DAVVAG workflows can call `creator-api/TestAgent` or run the saved agent through `RunAgent` and `InteractWithAgent`.

## Saved Data And Logs

When the required plugins are installed, the service uses DAVVAG data schemas for:

- `profile`
- `users`
- `usergroups`
- `domain_permision`
- `ai_agent_billing_usage`
- `ai_agent_error_log`

That means the app can keep an agent identity, write billing usage, and record runtime errors.

## Practical Workflow

1. Choose a provider.
2. Set the model and connection details.
3. Write the startup prompt.
4. Add one or more skills.
5. Generate the config.
6. Save the agent.
7. Test it from the console.
8. Connect it to your other DAVVAG services or workflows.

## Notes For Extending The App

- Add more skill types in `services/creator-api/service.php` if you need new runtime actions.
- Add UI helpers in `components/creator-console/script.js` if you want more templates.
- Keep skill payloads JSON-safe so they can be persisted and rendered into prompts.
- Prefer template variables over hardcoded IDs, URLs, or session values.
