# YouTube Growth Agent — Phase 0/1

This tenant app implements the read-only DAVVAG MVP defined in `YouTube-Growth-Agent-DAVVAG-Specification.md`.

## Included

- Multiple isolated channel workspaces and a channel switcher.
- Google web-server OAuth with YouTube read-only and Analytics read-only scopes.
- AES-256-GCM encrypted OAuth state and credential storage outside the web application directory.
- Server-side profile/channel authorization on every channel-scoped service.
- Uploads-playlist catalogue import; video details are read in batches of 50 and `search.list` is not used.
- Resumable initial/daily sync jobs, quota safety accounting, retry handling, Reporting API job setup and idempotent report imports.
- Official-metric command centre, video library, video inspector, recommendation inbox and weekly plan.
- Evidence/source/date validation, safe AI fallback, disconnect, local stored-data deletion and audit records.
- No channel write-back, scraping, audiovisual download, automatic comments, numeric viral score or unrelated-owner aggregate total.

## Google API configuration

Sign in as a DAVVAG system administrator and open `#/app/youtube-growth-agent/settings`. The Google client ID, client secret, encryption key, privacy policy URL and terms URL can be saved there. The app generates its OAuth callback from the active service URL and displays the exact URI to register in Google Cloud.

The server stores the configuration in the tenant-protected file below. Direct HTTP access is denied and secret values are never returned to the browser.

```text
TENANT_RESOURCE_LOCATION/data/youtube-growth-agent/configuration.php
```

Environment variables or protected DAVVAG constants with these names remain supported as fallback configuration:

```text
YTG_GOOGLE_CLIENT_ID
YTG_GOOGLE_CLIENT_SECRET
YTG_OAUTH_REDIRECT_URI          generated automatically when saved in Settings
YTG_ENCRYPTION_KEY              at least 32 characters
YTG_PRIVACY_POLICY_URL
YTG_TERMS_URL
```

Optional:

```text
YTG_AI_AGENT_CODE               defaults to youtube-growth-strategist
YTG_DAILY_QUOTA_LIMIT           defaults to 9500; maximum 10000
YTG_DERIVED_METRICS_ENABLED     defaults to false
```

The callback registered in Google Cloud must exactly match the read-only URI shown by Settings, typically:

```text
https://your-host/base/components/youtube-growth-agent/youtube-auth/service/OAuthCallback
```

Enable the YouTube Data API v3, YouTube Analytics API, and YouTube Reporting API for the OAuth project. Production OAuth and data use also require an appropriate consent screen, verified domain, privacy policy, terms, and YouTube API policy review as applicable.

Encrypted runtime credentials are stored beneath:

```text
MEDIA_FOLDER/youtube-growth-agent/{DATASTORE_DOMAIN}/
```

Only opaque `credentialRef` values are stored in `ytg_oauth_grants`.

## Deferred by the specification

Phase 2–4 features are not presented as complete: transcript/caption import, retention curves, Shorts candidates, competitor research, packaging workshop, content calendar, experiments, community reply drafting and approved metadata write-back. The Video Inspector marks these boundaries instead of inventing unavailable data.
