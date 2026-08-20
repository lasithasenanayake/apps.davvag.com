# AI destination autofill

Travel Destinations can use one saved agent from `ai-agent-creator` to suggest values for the destination submission form.

## Configure the default agent

1. Open **AI Agent Creator**, create an agent with a working provider configuration, and save it.
2. Open **Travel Destinations > AI settings** at `#/app/travel-destinations/admin/ai-settings`.
3. Select the default agent, choose a minimum confidence from `0.5` to `1`, and enable autofill.
4. Keep **Fill empty fields only** enabled if existing traveler input must never be replaced.

The selection is stored in `travel_destination_ai_settings`. Agent credentials remain owned by AI Agent Creator and are not copied to the travel schema or returned to the browser.

## Traveler flow

On **Submit a place**, enter a destination name and leave the name field, or select **Autofill with AI**. The backend calls `CreatorService::interactWithAgent()` with an isolated conversation key. Automatic lookup runs only for a new destination; the button remains available for explicit retries. If the agent recognizes the place above the configured confidence threshold, known form values are applied. Category and amenity names are matched only to active reference records already loaded by the form.

AI output is advisory. Travelers must review descriptions, access information, safety advice, and coordinates before saving or submitting.

## Security boundary

`EnrichDestination` is limited to authenticated travelers and administrators. `GetAdminAiSettings` and `SaveAiSettings` are limited to administrators. Anonymous users may read only the public-safe enabled/name state used to prepare the form; they cannot invoke the agent.

The destination name is length-limited and marked as untrusted data in the prompt. The agent reply must contain a JSON object; fenced JSON is also accepted. The service then:

- rejects unknown or low-confidence destinations;
- discards every property outside the destination-field allowlist;
- strips HTML and null bytes from text;
- enforces field lengths, language and stay-subtype formats;
- validates latitude and longitude ranges as a pair;
- bounds numeric distances and normalizes booleans; and
- returns neither raw agent configuration nor credentials.

The permission/schema installer seeds the disabled default configuration. Run `install-permissions.php` through the app installation workflow after deploying the new schema and service methods.
