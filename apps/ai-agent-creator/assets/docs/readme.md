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

## Model Selection And Catalog

The backend `Providers` method is the single source of truth for provider and model choices. The console loads it at startup; it does not contain a second hard-coded model map. Each curated entry includes the exact model ID, display name, lifecycle, supported input/output modalities, context and output limits, supported parameters, API adapter mode, pricing units, official URL, and a last-verified date.

Use **Refresh available models** to query authenticated OpenAI or Google model lists, Ollama `GET /api/tags`, or LM Studio `GET /api/v1/models`. Discovered generation models are merged with curated entries. Embedding-only LM Studio models and Google models without `generateContent` are excluded. If discovery fails, the curated list remains usable and the console shows a non-blocking warning. API keys are sent only for the discovery request and are never returned in catalog metadata or stored in browser storage.

The advanced **Custom model ID** choice defaults to text input/output and unknown pricing until a curated entry is added. The custom provider is deliberately described as a fixed OpenAI-compatible chat contract; it does not claim support for arbitrary request schemas.

### Updating Models And Prices

Edit `CreatorService::providerMap()` in `services/creator-api/service.php`. Use only official provider model and pricing documentation, update `pricingLastVerified` and each model's `pricing.lastVerified`, and leave unknown fields as `null`. Do not infer output modalities from input capabilities. Current official references are:

- OpenAI model catalog and pricing: `https://developers.openai.com/api/docs/models`
- Gemini models and pricing: `https://ai.google.dev/gemini-api/docs/models` and `https://ai.google.dev/gemini-api/docs/pricing`
- Ollama vision and model-list APIs: `https://docs.ollama.com/capabilities/vision` and `https://docs.ollama.com/api/tags`
- LM Studio model-list API: `https://lmstudio.ai/docs/developer/rest/list`

## Pricing And Estimates

The estimator uses catalog prices and integer pico-dollar arithmetic for token rates. It separates uncached input, cached input (when published), and output tokens. Runtime responses add `usage.cost` without changing existing token-usage keys or billing-schema records.

All displayed costs are estimates. Provider-reported token counts are preferred; character-based token estimates are used when a provider omits usage. Tool, storage, long-context tier, image/audio/video, tax, hosting, and other fees are excluded unless the selected catalog entry publishes an applicable unit. The UI displays **Pricing unavailable** rather than inventing a value. Ollama and LM Studio show a zero per-token provider API fee while explicitly excluding hardware, hosting, and electricity.

## Multimodal Configuration

Saved configurations add, without replacing existing keys:

```json
{
  "modalities": {
    "input": ["text", "image"],
    "output": ["text"]
  }
}
```

The console only enables modalities declared by the selected curated/discovered model. Older records without `modalities` load as text-only. Attachments are limited to 8 per request, 10 MB each, and 20 MB total. MIME type, reference scheme, modality compatibility, and malformed data are validated on both sides. Server filesystem paths, traversal, private-network references, and arbitrary server-side URL fetching are rejected. Inline base64 is sent to providers but never persisted in session history; only safe attachment metadata or a durable external reference is retained.

### Runtime Content And Outputs

`TestAgent`, `RunAgent`, and `InteractWithAgent` continue to accept the existing `message` string. Callers may add `content`:

```json
{
  "agentCode": "vision-agent",
  "message": "Describe this image",
  "content": [
    {"type": "text", "text": "Describe this image"},
    {"type": "image", "url": "data:image/jpeg;base64,...", "mimeType": "image/jpeg", "name": "photo.jpg", "size": 1024}
  ]
}
```

Allowed types are `text`, `image`, `audio`, `video`, and `document` (`file` is accepted as an alias for `document`). Successful responses always retain string `reply` and add `outputs`, which may contain image/audio/video/document URLs and MIME types.

## Provider Limitations

- OpenAI: new curated models use the Responses API and native `input_text`/`input_image` parts. Existing saved configurations without `apiMode` retain Chat Completions. The curated general models currently produce text only in this app.
- Google: `generateContent` maps inline data or provider-supported file URIs into native parts for text, image, audio, video, and documents. General Gemini entries are not labeled as media-output generators.
- Ollama: the HTTP chat API is the only runtime. Vision sends the REST API's base64 `images` array and is enabled only for configured vision models. The saved CLI field is manual metadata and is never executed.
- LM Studio: the OpenAI-compatible chat endpoint is used. Discovery excludes embeddings and enables image input only when LM Studio reports `capabilities.vision` for that model.
- Other: text-only fixed chat payload and response parsing. Validated manual token limits and token pricing may be supplied; multimodal request mappings remain disabled rather than being guessed.
- Streaming: disabled because the current Webdock service transport has no end-to-end SSE channel. It is always saved and sent as `false`.

## Backward Compatibility And Security

All existing public methods, component codes, workflow metadata, top-level agent keys, and common request/response fields remain unchanged. Existing callers that send only `agentCode`, `message`, `profile`, `sessionId`, `flow`, `connector`, and `payload` stay on the text path. Older configuration records receive defensive runtime defaults and require no migration.

Configuration previews, YAML, saved-agent responses, prompt context, and provider errors mask secrets. Provider connection drafts live only in memory and are isolated per provider. Anonymous requests with no explicit profile or session receive a unique ephemeral identity/session instead of an identity derived from message text. Agent and session JSON writes use a lock, same-directory temporary file, and atomic rename while retaining the existing JSON format.

## Tests

No production credentials or paid calls are used:

```powershell
C:\xampp\php\php.exe -l services\creator-api\service.php
C:\xampp\php\php.exe -l tests\run.php
C:\xampp\php\php.exe -d xdebug.mode=off tests\run.php
```

The harness covers catalog metadata, secret masking, old-record defaults, streaming consistency, model limits, exact cost calculation and unavailable pricing, OpenAI/Google/Ollama multimodal payloads, attachment safety, LM Studio filtering, provider-error sanitization, and atomic persistence. Browser smoke testing should cover provider switching, model filtering, custom model selection, estimates, save/reload, attachment preview/removal/rejection, and a test call using non-production credentials or a local provider.
