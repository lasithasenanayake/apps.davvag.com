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
- Timestamped Shorts candidates, SEO/video briefs, URL-based public competitor workspaces, a performing-video shortlist, and a content calendar.
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
YTG_KEYWORD_AGENT_CODE
YTG_TITLE_AGENT_CODE
YTG_DESCRIPTION_AGENT_CODE
YTG_HASHTAG_AGENT_CODE
YTG_COMPETITOR_AGENT_CODE
YTG_VIDEO_IDEA_AGENT_CODE
```

System administrators can map these tasks to any saved agent directly in **Settings & Privacy**. The selector is populated from `ai-agent-creator`; provider credentials and prompts remain owned by that app. Missing agents or rejected JSON use a deterministic, channel-scoped fallback.

## Competitor growth analysis

In **Growth Studio > Research**, add a competitor using a public YouTube `/channel/UC...` URL, `@handle` URL, bare `@handle`, legacy `/user/` URL, or bare `UC...` ID. The app resolves supported references with the documented `channels.list` parameters and imports the newest 20 uploads from the public uploads playlist. It does not use `search.list`, scrape YouTube, or download competitor transcripts.

The shortlist retains public titles, descriptions, tags, hashtags, publish dates, view counts, likes, comments, durations, and the channel's current public subscriber count. When `YTG_DERIVED_METRICS_ENABLED` is enabled, it labels and calculates:

```text
views per day = public views / days since publication
views-to-subscriber ratio = public views / current public subscriber count
```

These are DAVVAG product calculations, not YouTube metrics. A system administrator can enable them in Settings & Privacy only after accepting the applicable YouTube derived-metrics requirements. If the policy flag is disabled or a channel hides its subscriber count, unavailable ratios are not invented. The AI action then runs separately mapped competitor, keyword, title, description, hashtag, and video-idea tasks against the shortlist plus the selected owned video's metadata and authorized transcript. Every accepted suggestion must cite one of the supplied public competitor video IDs.

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

System administrators can copy the daily-analysis service URL from **Settings & Privacy**. Configure cPanel to request that URL hourly and grant its `RunDailyCron` operation through the normal service permissions. The endpoint processes connected channels whose daily sync is not yet complete and skips channels with a completed `DAILY_SYNC` job for the current date.

Successful fully completed invocations return only `done`. Incomplete or failed invocations return an empty error response. Aggregate invocations are retained in `ytg_cron_runs`; per-channel results remain in `ytg_sync_jobs`.

## Deliberately deferred

- Automatic caption-track listing/download is available from Growth Studio. The owner must explicitly enable the incremental `youtube.force-ssl` scope once; the base connection remains least-privilege. Selecting a video without a stored transcript then imports its best serving caption track automatically, while manual upload remains available when YouTube has no downloadable track.
- Starting or controlling native YouTube A/B tests through an API. The app prepares variants and reminders for manual use in YouTube Studio.
- Publishing comment replies, changing playlists/end screens, or applying metadata.
- Phase 4 incremental write authorization, approval queues, team workflow, and asset rendering/export.

## Verification baseline

The Phase 2/3 implementation is verified for PHP syntax, JSON parsing, declared resource/dependency existence, service descriptor/handler matching, saved-agent validator fallbacks, and delete-data namespace coverage. Live browser, Google OAuth/API, datastore migration, and two-profile/two-channel isolation tests still require a configured running tenant.
