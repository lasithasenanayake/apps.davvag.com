# YouTube Growth Agent - Phase 0-3 Read-Only Advisor

This tenant app implements the read-only DAVVAG command centre, content-intelligence workspace, and experiment workflow defined in `YouTube-Growth-Agent-DAVVAG-Specification.md`.

## Included

- Multiple isolated channel workspaces and a channel switcher.
- Google web-server OAuth with YouTube read-only and Analytics read-only scopes.
- AES-256-GCM encrypted OAuth state and credential storage outside the web application directory.
- Server-side profile/channel authorization on every channel-scoped service.
- Uploads-playlist catalogue import; video details are read in batches and `search.list` is not used.
- Resumable initial/daily sync jobs, quota accounting, retry handling, Reporting API setup, and idempotent imports.
- Official-metric command centre, video library, video inspector, recommendation inbox, and weekly plan.
- Automatic timestamped YouTube caption downloads after separate owner consent, plus user-provided transcripts with provenance and validated timestamps.
- Per-video audience-retention imports from the YouTube Analytics API.
- Timestamped Shorts candidates, SEO/video briefs, public competitor workspaces, and a content calendar.
- Packaging variants, native A/B test preparation, community themes, manual reply drafts, experiment journals, and session-path proposals.
- Saved-agent reuse through `ai-agent-creator`, strict JSON/evidence validation, auditable runs, and deterministic fallbacks.
- Sinhala/English generation requests based on channel settings.
- Disconnect, local stored-data deletion, and audit records covering Phase 0-3 namespaces.
- No channel write-back, scraping, audiovisual download, automatic comments, numeric viral score, or unrelated-owner aggregate total.

## Growth Studio

Open either route for the Phase 2/3 workspace:

```text
#/app/youtube-growth-agent/intelligence
#/app/youtube-growth-agent/growth-studio
```

Select a video before importing a transcript, refreshing retention/comments, or generating AI-assisted output. Merely opening the page does not call an AI provider. Every saved-agent action requires a per-action confirmation that the selected video context may be sent to the provider configured in AI Agent Creator.

## Saved-agent mapping

The app calls `CreatorService::interactWithAgent()` and does not duplicate provider, model, key, session, or skill logic.

Defaults:

```text
Shorts/transcript analysis  transcript-analyzer-agent
SEO and video briefs       seo-suggestion-agent
Packaging                  seo-suggestion-agent
Community/session paths    YTG_AI_AGENT_CODE fallback
```

Optional protected overrides:

```text
YTG_TRANSCRIPT_AGENT_CODE
YTG_SEO_AGENT_CODE
YTG_PACKAGING_AGENT_CODE
YTG_COMMUNITY_AGENT_CODE
YTG_STRATEGIST_AGENT_CODE
```

Invalid, unavailable, or unsupported agent JSON is rejected. The app then returns deterministic output based on authorized workspace data.

## Google API configuration

Sign in as a DAVVAG system administrator and open `#/app/youtube-growth-agent/settings`. Save the Google client ID, client secret, encryption key, privacy policy URL, and terms URL. The app generates the OAuth callback from the active service URL and displays the exact URI to register in Google Cloud.

The protected configuration file is:

```text
TENANT_RESOURCE_LOCATION/data/youtube-growth-agent/configuration.php
```

Supported environment variables or protected constants:

```text
YTG_GOOGLE_CLIENT_ID
YTG_GOOGLE_CLIENT_SECRET
YTG_OAUTH_REDIRECT_URI
YTG_ENCRYPTION_KEY              at least 32 characters
YTG_PRIVACY_POLICY_URL
YTG_TERMS_URL
YTG_AI_AGENT_CODE               defaults to youtube-growth-strategist
YTG_DAILY_QUOTA_LIMIT           defaults to 9500; maximum 10000
YTG_DERIVED_METRICS_ENABLED     defaults to false
```

The Google callback typically is:

```text
https://your-host/base/components/youtube-growth-agent/youtube-auth/service/OAuthCallback
```

Enable the YouTube Data API v3, YouTube Analytics API, and YouTube Reporting API. Production use also requires the appropriate consent screen, verified domain, privacy policy, terms, and policy review.

Encrypted runtime credentials are stored beneath:

```text
MEDIA_FOLDER/youtube-growth-agent/{DATASTORE_DOMAIN}/
```

Only opaque `credentialRef` values are stored in `ytg_oauth_grants`.

## Hourly cron

System administrators can copy the token-protected daily-analysis URL from **Settings & Privacy**. Configure cPanel to request that URL hourly. The endpoint processes connected channels whose daily sync is not yet complete and skips channels with a completed `DAILY_SYNC` job for the current date.

Successful fully completed invocations return only `done`. Invalid tokens and incomplete or failed invocations return an empty error response. Aggregate invocations are retained in `ytg_cron_runs`; per-channel results remain in `ytg_sync_jobs`.

## Deliberately deferred

- Automatic caption-track listing/download is available from Growth Studio. The owner must explicitly enable the incremental `youtube.force-ssl` scope once; the base connection remains least-privilege. Selecting a video without a stored transcript then imports its best serving caption track automatically, while manual upload remains available when YouTube has no downloadable track.
- Starting or controlling native YouTube A/B tests through an API. The app prepares variants and reminders for manual use in YouTube Studio.
- Publishing comment replies, changing playlists/end screens, or applying metadata.
- Phase 4 incremental write authorization, approval queues, team workflow, and asset rendering/export.

## Verification baseline

The Phase 2/3 implementation is verified for PHP syntax, JSON parsing, declared resource/dependency existence, service descriptor/handler matching, saved-agent validator fallbacks, and delete-data namespace coverage. Live browser, Google OAuth/API, datastore migration, and two-profile/two-channel isolation tests still require a configured running tenant.
