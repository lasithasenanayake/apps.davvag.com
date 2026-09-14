# CMS v7 HTML app embeds

Add a section with type `html` to a CMS page. A component-only placeholder automatically discovers its source app:

```html
<div webdock-component="backup-files"></div>
```

Replace `backup-files` with any registered component name. The lookup is dynamic; no app/component names are hard-coded in CMS. You can include several placeholders in one section or across sections.

If multiple accessible apps register the same component name, specify the source app:

```html
<div webdock-component="backup-files" webdock-app="davvag-hosting-console"></div>
```

For a page stored in `content/pages/<slug>.json`, add this section to its `sections` array:

```json
{
  "type": "html",
  "html": "<div webdock-component=\"backup-files\"></div>"
}
```

Use actual HTML in the editor's HTML field, not escaped text such as `&lt;div&gt;`. Text sections display plain text.

## How apps are discovered

1. After Vue inserts an HTML section, the local `cmsApps` directive calls `davvag-tools/davvag-app-downloader`.
2. CMS obtains its own catalog from `components/object/apps?tags=showincms`. The optional `_=...` query value is jQuery's cache-busting timestamp.
3. The API reads the current user's group JSON and returns the registered apps tagged `showincms`, keyed by app code. This response contains metadata and configuration, not the full component registry.
4. For a component-only placeholder, the downloader reads those apps' descriptors through Webdock and finds the descriptor whose `components` contains the requested name. It reports missing, ambiguous, or failed lookups inline. An explicit `webdock-app` limits lookup to that permitted app.
5. The downloader loads the selected app's `configuration.webdock.onLoad` resources before mounting the requested component. Descriptor requests in progress are shared; completed descriptors use the framework cache.

CMS maintains its own catalog rather than reading or overwriting the global `window.apps` menu cache. Failed catalog requests can be retried on subsequent loads. `CMSV7.getApps(callback)` also supplies this catalog to existing hosting-console downloader calls. Other docks retain their `left-menu` provider.

The source app must be installed, tagged `showincms`, and allowed for the visitor's user group. Embedding does not grant app or service permissions. In the checked local group configuration, `davvag-hosting-console` is available to `sysadmin`, not anonymous or `web_user` visitors. Use an authorized account to embed `backup-files`; a missing app in the API response is not resolved by changing the placeholder.

Declare source apps in the CMS installation's `dependencies.apps` when authored pages require them. The example does not make hosting administration a mandatory dependency for all CMS sites.

Navigation cancels pending mounts and destroys embedded Vue instances when their section is removed. Unchanged HTML updates do not remount embeds.

Versions: CMS `1.2`, dock-shell `0.5`, legacy partial-app `0.9`, davvag-tools `0.9`, downloader `0.4`. Deploy both changed apps and refresh existing browser tabs.

Regression checks from the framework root:

```text
node --test tests/cms-html-embeds.test.cjs
```

Tests exercise loader and directive behavior with simulated DOM/Webdock callbacks. Live visual and authenticated backup-files verification requires a connected browser.
