# YouTube Growth Agent for DAVVAG

## Product research, functional specification, AI-agent design, and Codex build prompt

**Recommended application name:** YouTube Growth Agent  
**DAVVAG app code:** `youtube-growth-agent`  
**Primary users:** creators, channel managers, small agencies, and ministry/media teams managing one or more YouTube channels  
**Initial language support:** English and Sinhala, with a design that can add more languages later

---

## 1. Product outcome

Build a DAVVAG web application that connects to multiple YouTube channels, imports each channel's authorized data, analyses its catalogue and audience behaviour, and gives the creator a prioritized set of actions to improve discovery, views, watch time, subscribers, and engagement.

The application must answer five questions every day:

1. What is working on this channel?
2. What is preventing growth?
3. Which existing videos should be improved first?
4. What should the creator publish next?
5. What exact action should the creator take now, and which metric should be reviewed afterward?

The application should begin as an **advisor with human approval**, not an autonomous channel editor. It may prepare titles, descriptions, thumbnail briefs, Shorts candidates, comment replies, and experiments, but it must not publish or alter a channel without explicit user approval.

---

## 2. Research findings that should shape the product

### 2.1 What YouTube officially indicates

- YouTube Search evaluates how well the title, description, and video content match the viewer's query, together with the engagement generated for that search. Search is not simply a list of the most-viewed videos.
- YouTube says accurate, compelling titles and thumbnails are important. Misleading or sensational packaging can repel viewers and may violate policy.
- Tags play only a minimal discovery role and are mainly useful for common misspellings. Therefore, this product must not make tag scoring its main feature.
- Recommendations respond to viewer behaviour and satisfaction: whether people choose a video, how much they watch, and whether they appear satisfied.
- Retention curves should be used to identify weak openings, drop-off points, rewatched sections, and the appropriate video length for a channel's audience.
- Publish time is not known to affect a normal video's long-term performance. Time is more important for live streams and Premieres. For normal uploads, the application may optimize the launch workflow and early audience response, but must not promise that a particular hour will make a video viral.
- YouTube Studio now supports native A/B testing of up to three titles, thumbnails, or title-thumbnail combinations for eligible long-form videos. It selects winners using watch-time share, not CTR alone. The DAVVAG app should prepare variants and instruct the user how to run the native test; it should not pretend it can start that test through an undocumented API.

Primary official references:

- [YouTube performance and discovery FAQ](https://support.google.com/youtube/answer/141805?hl=en)
- [YouTube recommendation-system guidance](https://support.google.com/youtube/answer/16559651?hl=en)
- [YouTube description guidance](https://support.google.com/youtube/answer/12948449?hl=en)
- [YouTube tags guidance](https://support.google.com/youtube/answer/146402?hl=en)
- [YouTube native title and thumbnail A/B testing](https://support.google.com/youtube/answer/16391400?hl=en-GB)

### 2.2 What competing platforms demonstrate

| Platform | Useful patterns to learn from | Opportunity for this DAVVAG app |
| --- | --- | --- |
| YouTube Studio | Authoritative private analytics, retention, Research tools, native title/thumbnail tests | Convert raw reports into a prioritized daily and weekly action plan across separate channel workspaces |
| vidIQ | Keyword estimates, daily ideas, AI coach, competitor tracking, outliers, clipping, posting guidance | Add transparent evidence, confidence, experiment history, multilingual Sinhala/English output, and channel-specific recommendations |
| TubeBuddy | SEO workflow, keyword explorer, catalogue tools, thumbnail analysis, suggested Shorts, A/B workflow | Combine catalogue optimization, transcript intelligence, retention, and action tracking inside DAVVAG |
| Viewstats | Competitor monitoring, outlier discovery, trend tracking, thumbnail research | Add niche-pattern research while clearly separating public YouTube data from product-generated estimates |

Competitor feature references:

- [vidIQ keyword tools](https://vidiq.com/features/keyword-tools/)
- [vidIQ competitor tools](https://vidiq.com/features/competitors/)
- [vidIQ AI Coach](https://vidiq.com/features/ai-youtube-coach/)
- [TubeBuddy Keyword Explorer and Suggested Shorts](https://www.tubebuddy.com/tools/keyword-explorer/)
- [TubeBuddy SEO Studio](https://www.tubebuddy.com/tools/seo-studio/)
- [Viewstats creator research tools](https://www.viewstats.com/info)

### 2.3 Recommended differentiation

The strongest product position is:

> **An evidence-based YouTube operating system that turns channel data, transcripts, retention, audience questions, and competitor patterns into a prioritized growth plan.**

Key differentiators:

- Multiple isolated channel workspaces with a fast channel switcher.
- An AI-generated daily action list and weekly growth plan.
- Every recommendation includes evidence, reasoning, confidence, effort, the next action, and a metric to review.
- Transcript plus retention analysis to locate strong Shorts candidates inside existing long-form videos.
- Sinhala and English title, hook, description, caption, and content-brief generation.
- An experiment journal that records what changed and whether it helped.
- Honest data labels: **YouTube metric**, **public YouTube data**, **user-provided data**, or **product estimate**.
- No fake certainty, guaranteed growth, or misleading “viral score.”

---

## 3. Essential platform and policy constraints

### 3.1 Supported official data sources

Use the following documented APIs:

1. **YouTube Data API v3** for channel identity, upload playlists, videos, metadata, statistics, comments, captions metadata, playlists, and approved write actions.
2. **YouTube Analytics API** for targeted reports such as top videos, watch time, average view duration, average view percentage, subscribers gained/lost, traffic sources, search terms, audience segments, and per-video retention.
3. **YouTube Reporting API** for scheduled bulk daily reports. As of January 2026 it provides channel reach reports containing thumbnail impressions and thumbnail CTR. Important report types include:
   - `channel_basic_a3`
   - `channel_traffic_source_a3`
   - `channel_reach_basic_a1`
   - `channel_reach_combined_a1`
   - `channel_end_screens_a2`
   - `channel_subtitles_a3`

Official references:

- [YouTube Data API reference](https://developers.google.com/youtube/v3/docs)
- [YouTube Analytics channel reports](https://developers.google.com/youtube/analytics/channel_reports)
- [YouTube Reporting API channel reports](https://developers.google.com/youtube/reporting/v1/reports/channel_reports)
- [Choosing Analytics versus Reporting APIs](https://developers.google.com/youtube/reporting)

### 3.2 Quota-aware synchronisation

- A default YouTube Data API project receives 10,000 quota units per day.
- `channels.list`, `playlistItems.list`, and `videos.list` are inexpensive reads.
- `search.list` costs 100 units, so do not use it to retrieve a connected channel's uploads. Get the uploads playlist from `channels.list`, paginate it with `playlistItems.list`, and retrieve videos in `videos.list` batches.
- `captions.list` costs 50 units per video. Do not scan every caption on a large catalogue by default. Process recent videos, high-opportunity videos, or user-selected videos first.
- Track quota consumption per operation and stop non-essential work before the quota is exhausted.

Reference: [YouTube Data API quota calculator](https://developers.google.com/youtube/v3/determine_quota_cost)

### 3.3 Transcript rules

For a connected channel:

1. Use `captions.list` and `captions.download` only with the necessary authorization and sufficient channel permissions.
2. If no downloadable caption track is available, ask the user to upload the original media, audio, subtitle, or transcript to the application.
3. Speech-to-text may process user-supplied original media.
4. Do **not** download or cache audiovisual content from YouTube. YouTube's developer policy prohibits downloading or storing YouTube audiovisual content without prior written approval.
5. Store transcript provenance: `youtube_caption`, `user_upload`, `manual_text`, or `speech_to_text`.

References:

- [YouTube captions.list](https://developers.google.com/youtube/v3/docs/captions/list)
- [YouTube API Services Developer Policies](https://developers.google.com/youtube/terms/developer-policies)

### 3.4 Data storage and deletion

- Display the freshest available YouTube data.
- Refresh or delete stored authorized metadata within 30 days unless a more specific policy permits longer storage.
- Verify at least every 30 days that the user is still authorized and that stored videos still exist.
- Provide **Disconnect Channel** and **Delete My Stored YouTube Data** actions.
- Complete a requested user-data deletion as soon as possible and within seven calendar days.
- Never combine data belonging to unrelated content owners into one aggregate total. Multi-channel support should use isolated workspaces and a channel switcher. Only implement cross-channel aggregation when the YouTube policy and the user's recognized content-owner relationship clearly permit it.
- Keep OAuth tokens, client secrets, and AI provider keys on the server. Never expose them through frontend JavaScript or DAVVAG component payloads.

### 3.5 Derived metrics and recommendation scores

YouTube generally prohibits creating substitute or modified metrics from API data unless the developer accepts the applicable derived-metrics amendment. The current amendment permits useful creator analytics such as custom channel scores, ratios, content tagging, sentiment, and historical comparisons, provided the implementation follows its rules.

Before releasing numeric opportunity scores, engagement ratios, channel health scores, outlier scores, or long-term historical snapshots:

1. Apply for/accept the YouTube derived-metrics amendment for the analytics use case.
2. Clearly label every derived result as a product calculation, not a YouTube metric.
3. Never redefine a YouTube metric.
4. Do not infer protected audience traits or sensitive characteristics.
5. If the amendment is not accepted, show official metrics separately and provide qualitative recommendations without derived numeric scores.

Reference: [YouTube additional policies for derived metrics](https://developers.google.com/youtube/terms/derived-metrics-policy)

---

## 4. User roles and multi-channel behaviour

### Roles

- **Owner:** connects/disconnects channels, manages permissions, approves write actions, deletes stored data.
- **Manager:** analyses channels, creates plans, prepares metadata and experiments.
- **Editor:** works with video briefs, Shorts candidates, titles, descriptions, and thumbnail briefs.
- **Viewer:** read-only analytics and reports.

### Multi-channel requirements

- A user may connect several channels through separate OAuth grants.
- Each channel has its own timezone, language, content pillars, target audience, competitors, strategy, tasks, and AI memory.
- The selected channel ID is mandatory in every query and service operation.
- Never rely only on a browser-selected channel; verify server-side that the current DAVVAG user has access to the requested channel.
- Do not show a combined views/subscribers total across unrelated channel owners.
- Show connection health, granted scopes, last metadata sync, last Analytics sync, and last Reporting job import for every channel.

---

## 5. Core application modules

### 5.1 Portfolio and channel switcher

- Connected channel cards.
- Connection/sync status.
- Last successful analysis.
- Open recommendations count.
- Quick link to add another channel.
- No prohibited cross-owner aggregate totals.

### 5.2 Channel command centre

Show raw official metrics for 7, 28, 90, and 365 days where available:

- Views and engaged views.
- Watch time.
- Average view duration and percentage.
- Subscribers gained and lost.
- Likes, comments, and shares.
- Thumbnail impressions and CTR from Reporting API reach reports.
- Traffic-source mix.
- Search terms.
- Top videos and rising videos.
- Returning/subscribed audience indicators where the API supports them.
- Latest AI diagnosis and top three actions.

Do not use universal “good CTR” or “good retention” thresholds. Compare like with like inside the same channel: format, duration band, video age, language, topic pillar, and traffic source.

### 5.3 Video library and video inspector

Filters:

- Long-form, Shorts, live, and archived live.
- Published date.
- Topic pillar and language.
- High/low impressions.
- High/low CTR.
- High/low retention.
- Search-led, Browse-led, Suggested-led, External-led, or Shorts-led.
- Has transcript / missing transcript.
- Open recommendation / completed improvement.

Video inspector tabs:

1. Overview and metadata.
2. Reach and traffic sources.
3. Retention curve and transcript timeline.
4. Search terms and topic alignment.
5. Comments, questions, and sentiment themes.
6. Shorts candidates.
7. Title, description, thumbnail, chapters, cards, playlist, and end-screen recommendations.
8. Action and experiment history.

### 5.4 AI recommendation inbox

Every recommendation must contain:

- Channel and optional video.
- Recommendation type.
- Clear observation.
- Evidence with metric names, date range, and data source.
- Why the issue matters.
- Exact action steps.
- Suggested asset or text.
- Expected outcome stated as a hypothesis, never a guarantee.
- Confidence: low, medium, or high.
- Effort: small, medium, or large.
- Metric to review.
- Review date.
- Status: new, accepted, in progress, completed, dismissed, or needs data.

Priority categories:

- **Do today**
- **Do this week**
- **Test next**
- **Create next**
- **Needs more data**

### 5.5 SEO and topic research

The research engine should combine:

- Search queries that already send traffic to the connected channel.
- Titles, descriptions, transcripts, topics, and performance of the channel's own videos.
- Public metadata and refreshed statistics from user-selected competitor channels.
- YouTube search results used sparingly due to quota.
- User-entered topic seeds.
- Optional external trend or keyword providers when legally licensed and configured.

Important truthfulness rule: the official YouTube APIs do not expose exact keyword search volume. Any “volume,” “competition,” or “opportunity” result from the DAVVAG product must be labelled as an estimate and explain its inputs. Do not scrape YouTube Studio's Research tab or undocumented endpoints.

Outputs:

- Search terms the channel already wins.
- Under-served long-tail topics.
- Follow-up ideas from high-performing videos.
- Topic clusters and playlist opportunities.
- Search-intent classification: learn, compare, solve, story, news, inspiration, or transaction.
- Suggested title angles, opening hooks, viewer promise, and outline.
- Cannibalization warning when the channel already has overlapping videos.

### 5.6 Transcript and Shorts finder

For each selected long-form video:

1. Import time-coded captions or a user-provided transcript.
2. Align transcript segments with retention data.
3. Detect strong openings, self-contained insights, questions, emotional moments, stories, surprising statements, and retention spikes.
4. Penalize segments that require too much prior context or end without resolution.
5. Produce 3–10 candidate clips with start/end timestamps.

Each Shorts candidate should include:

- Start and end timestamp.
- Candidate duration.
- Exact source excerpt or paraphrased moment.
- Why the moment may work.
- A 1–2 second hook suggestion.
- On-screen caption plan.
- Short title options.
- Description and limited relevant hashtags.
- CTA connected to the full video or playlist.
- Suggested crop/visual notes.
- Confidence and evidence.

The MVP recommends clips. Later phases may render clips only from user-supplied original media.

### 5.7 Packaging workshop

- Generate accurate title alternatives based on topic, search intent, transcript, and audience language.
- Generate thumbnail concepts as briefs: focal subject, emotion, composition, contrast, and optional 2–5 word text.
- Detect mismatch between title promise, thumbnail promise, and actual video opening.
- Prepare up to three materially different variants for YouTube Studio's native A/B test.
- Add a Studio test checklist and a review reminder.
- Never describe a variant as a guaranteed winner.

### 5.8 Audience and community intelligence

- Import recent comment threads through the documented API.
- Classify recurring questions, objections, requested topics, testimonies, and confusion points.
- Generate human-review reply drafts.
- Create an audience-language dictionary from real comment wording.
- Produce future video ideas and FAQ content.
- Sentiment may be analyzed only in aggregate and must not infer sensitive protected traits.
- No automatic bulk replies in the MVP.

### 5.9 Content planner and calendar

- Content pillars per channel.
- Backlog of ideas with evidence and target audience.
- Video brief: promise, title angles, thumbnail brief, hook, structure, CTA, related playlist, and distribution plan.
- Long-form-to-Shorts repurposing plan.
- Calendar in the channel's timezone.
- Suggested timing should distinguish:
  - **Live/Premiere timing:** informed by when viewers are active.
  - **Normal upload launch slot:** an experiment based on the channel's history, not a long-term ranking promise.
- Weekly mix by topic pillar and format.

### 5.10 Experiment journal

Track one meaningful change at a time where practical:

- Title/thumbnail test.
- Opening-hook style.
- Video length band.
- Topic cluster.
- CTA position.
- End-screen or playlist path.
- Live/Premiere timing.
- Normal-upload launch slot.

Store hypothesis, variants, start/end date, official metrics, result, limitations, and next decision. Native YouTube tests must be recorded as native tests, with results entered or imported only through a documented source.

---

## 6. Recommendation logic

These are hypotheses to test against the channel's own baseline, not universal laws.

| Observed pattern | Likely diagnosis | Recommended action |
| --- | --- | --- |
| Strong impressions, weak CTR versus comparable videos | Packaging is not converting exposure | Prepare three accurate title/thumbnail variants for a native test |
| Strong CTR, sharp early retention loss | The promise attracted clicks but the opening did not deliver quickly | Rewrite the opening, remove slow setup, and align the first 30 seconds with the title promise in future videos |
| Low impressions, strong retention and satisfaction signals | Good content may have a narrow topic, weak packaging, or insufficient distribution path | Improve topic framing, connect playlists/end screens, and create adjacent follow-up content |
| Search traffic is strong for a specific query | The channel has demonstrated search authority | Create a topic cluster, strengthen accurate query alignment, and add related playlist paths |
| Browse or Suggested traffic is rising | The concept/packaging fits a wider viewer need | Build a series using the same audience promise without copying the same video |
| Rewatched or high-retention segment | A moment is independently valuable | Create a Short candidate or use the pattern as a future hook |
| Repeated comment question | Audience demand is explicit | Create an FAQ Short, community post, or follow-up video |
| Good video performance but weak end-screen clicks | Session path is underdeveloped | Recommend one highly relevant next video or playlist and improve the spoken CTA |
| Subscribers gained cluster around one topic | That topic attracts the desired audience | Add more depth and a consistent series pathway |
| Old evergreen video still receives search traffic but metadata is weak | Existing demand can be served more clearly | Refresh title/description only after checking current CTR and avoid changing what is already working |

### Recommendation-generation guardrails

- Require a minimum evidence threshold before making a strong recommendation.
- Return “needs more data” when sample size is too small.
- Separate Shorts, long-form, and live benchmarks.
- Separate recent-launch performance from lifetime performance.
- Do not compare a two-minute video with a two-hour livestream without normalization and clear disclosure.
- Cite the exact date window and data source in every diagnosis.
- Do not produce advice solely from one metric.

---

## 7. AI-agent system

### 7.1 Orchestrator Agent

Selects the correct specialist, supplies channel context, validates output structure, removes unsupported claims, and creates or updates recommendations.

### 7.2 Channel Strategist Agent

Analyses channel direction, content pillars, audience promise, winning formats, declining areas, and the weekly plan.

### 7.3 Catalogue Audit Agent

Scans existing videos for metadata gaps, weak internal linking, missing transcripts, stale evergreen opportunities, playlist gaps, and videos needing further analysis.

### 7.4 Discovery and SEO Agent

Uses existing search terms, topic clusters, public competitor patterns, and content relevance to suggest discoverable topics and metadata. It must treat keyword volume as an estimate unless supplied by a licensed provider.

### 7.5 Retention and Shorts Agent

Aligns retention points with transcript timestamps, explains drop-offs and spikes, and selects self-contained Shorts candidates.

### 7.6 Packaging Agent

Creates accurate title options, thumbnail briefs, hook alignment, and native A/B test variants.

### 7.7 Community Insight Agent

Groups comments into questions and themes, drafts replies, and suggests content based on audience language. It must not profile individual commenters or infer sensitive attributes.

### 7.8 Experiment Analyst Agent

Designs tests, records limitations, compares results only when samples are reasonably comparable, and recommends the next experiment.

### Required structured AI output

All agents must return validated JSON. A recommendation object should contain at least:

```json
{
  "channelId": "local-channel-id",
  "videoId": "optional-youtube-video-id",
  "type": "PACKAGING|RETENTION|SEO|SHORTS|COMMUNITY|PLAYLIST|CONTENT_IDEA|TIMING",
  "observation": "Plain-language finding",
  "evidence": [
    {
      "source": "YOUTUBE_ANALYTICS|YOUTUBE_REPORTING|YOUTUBE_DATA|USER_DATA|PRODUCT_ESTIMATE",
      "metric": "metric name",
      "value": "display value",
      "dateRange": "YYYY-MM-DD/YYYY-MM-DD"
    }
  ],
  "reasoning": "Why the evidence supports the recommendation",
  "actions": ["Specific action 1", "Specific action 2"],
  "hypothesis": "Expected but unguaranteed outcome",
  "confidence": "LOW|MEDIUM|HIGH",
  "effort": "SMALL|MEDIUM|LARGE",
  "metricToReview": "Official metric or clearly labelled product estimate",
  "reviewAfterDays": 14,
  "requiresApproval": true
}
```

The server must reject invalid JSON, missing evidence, non-existent metrics, invented timestamps, unsupported guarantees, and advice that conflicts with YouTube policy.

---

## 8. DAVVAG application architecture

Follow the supplied DAVVAG Tenant Development Guide. Keep the application inside the tenant and use existing DAVVAG patterns.

### 8.1 App structure

```text
apps/youtube-growth-agent/
  app.json
  app.php
  components/
    main-view/
    channel-switcher/
    command-centre/
    video-library/
    video-inspector/
    recommendation-inbox/
    seo-research/
    shorts-finder/
    packaging-workshop/
    content-calendar/
    experiment-journal/
    settings/
  services/
    api/
    youtube-auth/
    youtube-sync/
    youtube-analytics/
    transcript-service/
    ai-orchestrator/
    research-service/
```

Reusable server-only integrations may live under:

```text
plugins/youtube-growth/
```

Suggested contents:

- OAuth client and callback helpers.
- Encrypted credential repository.
- YouTube Data, Analytics, and Reporting API clients.
- Quota manager and retry policy.
- AI provider adapter.
- Transcript normalization and timecode tools.
- JSON schema validation.
- Audit logger.

Never expose this plugin folder or its secrets over HTTP.

### 8.2 DAVVAG registration

- Register all components and services in `apps/youtube-growth-agent/app.json`.
- Add the app to `tenant.json` without removing existing apps.
- Add owner/admin access to `sysadmin.json`.
- Add normal authorized access to `web_user.json` when appropriate.
- Do not grant anonymous access to connected channel data.
- Follow an existing app shell and existing authentication/profile conventions.

### 8.3 Suggested services

| Service method | Purpose |
| --- | --- |
| `StartConnect` | Create OAuth state and redirect URL |
| `OAuthCallback` | Validate state, exchange code, identify channel, and store encrypted grant |
| `ListChannels` | Return channels available to the current DAVVAG user |
| `DisconnectChannel` | Revoke/delete the local connection and queue stored-data deletion |
| `DeleteChannelData` | Delete locally stored YouTube data while clearly stating that YouTube data itself is unaffected |
| `RunInitialSync` | Import catalogue, metadata, baseline reports, and create Reporting jobs |
| `RunDailySync` | Refresh recent statistics, metadata, reports, comments, and recommendations |
| `SyncVideo` | Refresh one selected video and its analysis inputs |
| `GetDashboard` | Return official metrics and latest recommendations for one channel |
| `ListVideos` | Paginated, filterable video catalogue |
| `GetVideoAnalysis` | Reach, traffic, retention, transcript, comments, and recommendations |
| `ImportTranscript` | Import authorized captions or a user-provided transcript |
| `GenerateShortCandidates` | Create timestamped Shorts suggestions |
| `GenerateChannelPlan` | Create a weekly strategy and task list |
| `GenerateVideoBrief` | Create a researched production brief |
| `GeneratePackagingVariants` | Produce title and thumbnail-test briefs |
| `ListRecommendations` | Filtered recommendation inbox |
| `UpdateRecommendationStatus` | Accept, dismiss, complete, or reopen an action |
| `AddCompetitor` | Add a user-selected public comparison channel |
| `RefreshCompetitors` | Refresh public metadata within policy and quota limits |
| `CreateExperiment` | Record hypothesis and variants |
| `CompleteExperiment` | Store result and next decision |
| `PreviewMetadataUpdate` | Show a full diff and required preserved fields |
| `ApplyMetadataUpdate` | Optional later phase; apply only after explicit approval |

DAVVAG service responses should remain plain PHP objects/arrays and allow the framework to wrap successful results. Validate all IDs, dates, enum values, paging, and user ownership server-side.

### 8.4 DAVVAG workflows

Create workflows under:

```text
davvag-flow/youtube-growth-agent/
```

Recommended workflows:

1. `initial-channel-sync.json`
2. `daily-channel-sync.json`
3. `analyze-video.json`
4. `generate-weekly-plan.json`
5. `generate-short-candidates.json`
6. `refresh-competitors.json`
7. `review-experiments.json`
8. `delete-channel-data.json`

Use the tenant's existing scheduler/task infrastructure to trigger recurring work. Make jobs idempotent, resumable, channel-scoped, and observable.

---

## 9. Data model

Create one DAVVAG schema JSON file per namespace under `schemas/`. Names below are suggestions and may be adjusted after inspecting existing tenant conventions.

| Namespace | Purpose | Important fields |
| --- | --- | --- |
| `ytg_channels` | Connected channel workspace | id, profileId, youtubeChannelId, title, timezone, defaultLanguage, scopes, status, lastSyncAt |
| `ytg_channel_access` | User/channel role mapping | channelId, profileId, role, status |
| `ytg_oauth_grants` | Server-side credential reference and consent record | channelId, profileId, credentialRef, scopes, expiresAt, lastVerifiedAt; never expose in UI |
| `ytg_videos` | Refreshed video catalogue | channelId, youtubeVideoId, title, description, publishedAt, durationSeconds, contentType, categoryId, tags, thumbnail, metadataRefreshedAt |
| `ytg_video_statistics` | Authorized/public statistical snapshots | channelId, videoId, capturedAt, views, likes, comments; retention depends on policy acceptance |
| `ytg_analytics_daily` | Daily official activity metrics | channelId, videoId, date, contentType, views, engagedViews, watchMinutes, avgViewDuration, avgViewPercentage, subscribersGained, subscribersLost |
| `ytg_reach_daily` | Reporting API reach data | channelId, videoId, date, trafficSource, deviceType, impressions, impressionsCtr |
| `ytg_traffic_sources` | Discovery breakdown and search details | channelId, videoId, date/dateRange, sourceType, sourceDetail, views, watchMinutes |
| `ytg_retention_points` | Per-video retention curve | channelId, videoId, elapsedRatio, audienceWatchRatio, relativeRetention, startedWatching, stoppedWatching, refreshedAt |
| `ytg_transcripts` | Time-coded transcript and provenance | channelId, videoId, language, sourceType, segments, refreshedAt |
| `ytg_comments` | Refreshed comment sample for insight | channelId, videoId, youtubeCommentId, text, publishedAt, likeCount, refreshedAt |
| `ytg_content_tags` | Product-generated topic classification | channelId, videoId, tag, provenance, modelVersion |
| `ytg_competitors` | User-selected public comparison channels | channelId, competitorYoutubeChannelId, label, active, refreshedAt |
| `ytg_competitor_videos` | Refreshed public catalogue sample | competitorId, youtubeVideoId, metadata, statistics, refreshedAt |
| `ytg_recommendations` | Prioritized action inbox | channelId, videoId, type, observation, evidence, actions, confidence, effort, status, reviewAt |
| `ytg_short_candidates` | Timestamped repurposing suggestions | channelId, videoId, startMs, endMs, hook, captionPlan, titleOptions, evidence, status |
| `ytg_content_ideas` | Backlog and researched briefs | channelId, pillarId, idea, intent, evidence, brief, status |
| `ytg_calendar_items` | Planned content | channelId, ideaId, format, plannedAt, timezone, status |
| `ytg_experiments` | Hypotheses and results | channelId, videoId, type, variants, startAt, endAt, metrics, result, limitations, status |
| `ytg_sync_jobs` | Sync and workflow state | channelId, type, cursor, status, attempts, startedAt, completedAt, error |
| `ytg_agent_runs` | AI observability | channelId, videoId, agentType, model, promptVersion, inputRefs, output, validationStatus, tokenUsage |
| `ytg_audit_log` | Security and approvals | profileId, channelId, action, target, beforeData, afterData, approvedAt, createdAt |

Security requirements:

- Do not place OAuth access tokens, refresh tokens, client secrets, or AI keys in generic object fields returned by SOSSData.
- Use a dedicated encrypted server-side secret mechanism and store only a credential reference in `ytg_oauth_grants`.
- Apply `channelId` and authorized-profile constraints to every query, including raw reports.
- Because DAVVAG raw queries interpolate placeholders, cast numeric values, validate dates, whitelist sort fields/enums, and never accept raw SQL fragments from the browser.

---

## 10. Synchronisation design

### Initial connection

1. User selects **Connect YouTube Channel**.
2. Start with least-privilege scopes: YouTube read-only and Analytics read-only.
3. Validate OAuth state on callback.
4. Retrieve the owned channel identity.
5. Create an isolated channel workspace.
6. Retrieve the uploads playlist and paginate the video catalogue.
7. Retrieve video details/statistics in batches.
8. Create Reporting API jobs for basic, traffic-source, and reach reports.
9. Import targeted 28/90/365-day Analytics reports as available.
10. Generate the initial channel audit.
11. Ask separately for the additional caption or write scope only when the user uses those features.

### Daily sync

- Refresh channel and recent video metadata.
- Import newly uploaded videos.
- Import available Reporting API files exactly once.
- Refresh important Analytics reports.
- Refresh authorized existence/permission status.
- Refresh comments for recent or selected videos.
- Run rule-based detectors.
- Invoke AI only for new findings or materially changed evidence.
- Update, supersede, or close recommendations instead of creating duplicates.

### On-demand deep analysis

- Fetch one video's current data.
- Retrieve its retention report.
- Import captions only when authorized and requested.
- Generate transcript/retention insights and Shorts candidates.
- Produce a packaging and content-improvement brief.

### Failure handling

- Exponential backoff for retryable API errors.
- Stop and mark reauthorization required for revoked/expired grants that cannot refresh.
- Track quota exhaustion separately from application failures.
- Save pagination/report cursors so interrupted syncs resume safely.
- Do not silently substitute stale data; show freshness timestamps.

---

## 11. Safe write-back design for a later phase

Read-only analysis is the MVP. Optional write support can be added after the app is stable.

Before any write:

1. Request incremental write scope.
2. Show the exact current and proposed values.
3. Explain which YouTube fields will change.
4. Require a final explicit confirmation.
5. Record an audit event.
6. Read the current video resource immediately before writing.
7. Preserve all required and unchanged properties.

This is especially important for `videos.update`: fields omitted from an included part can be deleted or reset. Never submit a partial `snippet` built only from the new title. Include the required title/category and deliberately preserve the remaining mutable snippet fields.

Reference: [YouTube videos.update](https://developers.google.com/youtube/v3/docs/videos/update)

---

## 12. MVP scope and delivery phases

### Phase 0 — Compliance and integration foundation

- Google Cloud project and documented APIs.
- OAuth consent, privacy policy, terms, delete-data flow, and channel disconnect.
- Derived-metrics amendment decision.
- Server-side secret encryption.
- Quota and audit logging.

### Phase 1 — Read-only growth command centre

- Connect multiple channels as isolated workspaces.
- Channel switcher.
- Initial and daily catalogue sync.
- Analytics and Reporting ingestion.
- Command centre and video library.
- Rule-based channel/video diagnosis.
- AI recommendation inbox.
- Daily actions and weekly plan.

### Phase 2 — Transcript, Shorts, and content intelligence

- Authorized captions and user transcript upload.
- Retention timeline.
- Shorts candidate generator.
- SEO/topic research.
- Competitor workspace.
- Video briefs and content calendar.
- Sinhala/English content generation.

### Phase 3 — Packaging, community, and experiments

- Title/thumbnail workshop.
- Native YouTube A/B test preparation and reminders.
- Comment themes and reply drafts.
- Experiment journal.
- End-screen, playlist, and session-path recommendations.

### Phase 4 — Approved actions and team workflow

- Incremental write authorization.
- Metadata update preview and approval.
- Team roles and approval queues.
- Optional asset export/rendering from user-provided source media.

---

## 13. MVP acceptance criteria

The MVP is complete when:

1. A DAVVAG user can connect at least two YouTube channels and switch between them.
2. Data from one channel can never be requested by a user without access to it.
3. The application imports the channel's uploads without using expensive `search.list` calls.
4. Channel and video dashboards show raw official metrics with source and freshness.
5. Reporting API reach data is imported when available.
6. The app provides at least five evidence-backed recommendation types.
7. Every recommendation includes a date range, evidence source, confidence, action, and review metric.
8. The app can deep-analyse one video's retention.
9. The app can import an authorized or user-provided transcript and generate timestamped Shorts candidates.
10. The app produces Sinhala or English output according to channel settings.
11. Disconnect and delete-data flows work and are audited.
12. No secret is present in frontend source, API payloads, logs, or generic schemas.
13. Sync jobs are resumable and do not duplicate imported daily reports.
14. The user can accept, dismiss, complete, and review recommendations.
15. No channel-changing action occurs without explicit approval.

---

## 14. Master Codex build prompt

Use the prompt below when the full DAVVAG tenant repository is available to Codex.

> You are working inside an existing DAVVAG tenant. Build a production-ready application named **YouTube Growth Agent** with app code `youtube-growth-agent`.
>
> First read the tenant's DAVVAG development guide completely. Inspect `tenant.json`, group-access files, the existing scheduler/task apps, authentication/profile conventions, `davvag-sample-app-1`, and two mature apps that use services, schemas, raw reports, and workflows. Preserve all existing applications and user changes.
>
> Implement the specification in `YouTube-Growth-Agent-DAVVAG-Specification.md`, beginning with Phase 0 and Phase 1 only unless a later phase is explicitly requested.
>
> The MVP must be a read-only YouTube growth command centre with multiple isolated channel workspaces, secure OAuth, quota-aware catalogue synchronisation, YouTube Analytics and Reporting ingestion, a channel/video dashboard, and an evidence-backed recommendation inbox. Use documented YouTube APIs only. Do not scrape YouTube or YouTube Studio, use undocumented endpoints, download YouTube audiovisual content, expose credentials, aggregate unrelated content-owner data, or invent unavailable metrics.
>
> Use DAVVAG conventions:
>
> - New app files under `apps/youtube-growth-agent/`.
> - Schemas under `schemas/`.
> - Workflows under `davvag-flow/youtube-growth-agent/`.
> - Reusable server-only code under a tenant-local plugin.
> - `SOSSData` for persistence.
> - Service handlers for business logic and transformers only for simple forwarding/CRUD.
> - Register the app and permissions without removing existing entries.
>
> Before coding, produce a short implementation inventory: reusable tenant components, chosen OAuth/secret approach, scheduler integration, schema list, service list, workflow list, and any missing configuration. Then implement in small verified stages.
>
> Required engineering rules:
>
> - Every request is scoped and authorized by `channelId` on the server.
> - Encrypt tokens; return only safe connection metadata to the browser.
> - Use the uploads playlist rather than `search.list` to import a connected channel's videos.
> - Batch and paginate API reads, track quota, and use retry/backoff.
> - Record data source, date range, and freshness.
> - Keep official YouTube metrics separate from product estimates.
> - Do not create numeric derived scores until the derived-metrics policy requirement is explicitly enabled.
> - Validate all AI JSON against a server-side schema and reject unsupported claims.
> - Provide reauthorization, disconnect, and delete-stored-data flows.
> - Make sync jobs idempotent and resumable.
> - Add audit logs for OAuth, deletion, approvals, and future write actions.
> - Validate every raw-query parameter; never accept browser-provided SQL fragments or arbitrary sort clauses.
>
> Verification must include descriptor/component/service endpoints, schema creation, authorization isolation between two test users/channels, pagination, idempotent resync, revoked OAuth behaviour, quota exhaustion, empty datasets, AI validation failures, and delete-data behaviour. Report exactly what was implemented, tested, deferred, and what configuration the administrator must supply.

---

## 15. Recommended first build decision

Start with a **read-only, multi-channel advisor** and make these the first visible features:

1. Connect channel.
2. Channel command centre.
3. Video opportunity list.
4. Daily top-three actions.
5. Weekly channel plan.
6. One-video retention and transcript analysis.
7. Timestamped Shorts suggestions.

This creates immediate creator value while keeping OAuth scope, policy risk, and accidental channel changes under control. After the recommendations are reliable, add approved metadata updates, team workflows, and media rendering.
