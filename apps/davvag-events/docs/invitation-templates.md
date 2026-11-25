# Invitation editor and templates (1.1.0)

The editor now follows Event details ? Choose invitation ? Review & share. Dates use native date/time pickers in the selected event time zone. Hosts do not enter ISO dates or UTC offsets. Date conversion and validity checks run on the server; stored instants remain UTC. An unchanged local value retains its exact existing instant, including repeated daylight-saving hours.

Switching a design changes its visual theme without clearing names, venue, dates, address or the host's own message. The five starter designs are available for every occasion. Custom designs may be available for all occasions or a specific occasion. Colours and lettering remain host choices. Advanced activities, programme and section settings stay behind optional controls.

## Administrator template builder

In the editor's Choose invitation step, tenant administrators see Create reusable designs. Choose Create a template, add a name, card body HTML and separate CSS, then save. Existing custom templates can be edited there. Saving creates a new version; it does not silently update any event's saved draft or published invitation. Select the new version and save/publish the event to use it.

Supported placeholders: `{{title}}`, `{{hosts}}`, `{{heading}}`, `{{message}}`, `{{venue}}`, `{{address}}`, `{{start}}`, `{{end}}`, `{{rsvpDeadline}}`, `{{timezone}}`. Values render as escaped text. Use `var(--accent)`, `var(--background)` and `var(--font)` so hosts can choose colours and lettering.

```html
<section class="invitation">
  <p>{{heading}}</p>
  <h1>{{title}}</h1>
  <p>{{hosts}}</p>
  <p>{{start}}</p>
  <h2>{{venue}}</h2>
  <p>{{address}}</p>
</section>
```

```css
body { margin: 0; font-family: var(--font); }
.invitation {
  min-height: 650px;
  padding: 48px 24px;
  background: var(--background);
  color: var(--accent);
  text-align: center;
  animation: welcome 1s ease both;
}
@keyframes welcome {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
```

Cards support semantic text/layout HTML and self-contained CSS, including keyframes. Scripts, event handlers, forms, embeds, navigation links, external imports/URLs, and executable CSS are rejected. Guest interactions such as RSVP remain outside the card. Cards render in an opaque-origin sandboxed iframe with scripts/network/forms disabled by CSP. Reduced-motion preferences turn off animation. Preview, host-selected themes and guest publication use the same card renderer.

The versioned library is stored only inside this app at `templates/library.json`, with a separate lock and atomic file replacement. The PHP service account needs write access to that directory. The library contains presentation content, not provider credentials or private guest data. Up to 40 custom designs and a 2 MB total version library are supported. Template HTML is limited to 24 KB and CSS to 16 KB per version. PHP DOM is required for validation.

`POST events/SaveTemplate` requires a session CSRF token and an authenticated tenant administrator. Input: optional id/version, name, occasion (`all` or an event occasion), html, css, active, and design defaults. Stale edits return CONFLICT. `GET events/Session` includes the current selectable templates, hidden templates for administrators, and supported time zones. `SaveEvent` accepts localStart, localEnd and localRsvpDeadline as picker values (`YYYY-MM-DDTHH:mm`), timezone, templateId and templateVersion. Client HTML is never accepted as an event template snapshot.

No framework core, tenant registry or external schema files were changed for this update. Existing event records were not rewritten. Legacy published invitations without a template snapshot keep their previous header until the host saves and republishes a design.

Checks: 26 PHP date/template/security/versioning checks, 13 JavaScript editor/preview checks, the existing identity/Retry checks, syntax/resource/descriptor checks and all 14 route templates (including the three editor steps) using installed Vue 2.0.3. Browser visual inspection was blocked: the browser connector was unavailable, and automatic approval review rejected inspecting the existing Edge window because it contained unrelated signed-in pages.
