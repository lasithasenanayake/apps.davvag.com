WEBDOCK.component().register(function (exports) {
    var api, sync, workbench, ai, metadataWorkflow;
    var state = {
        channelId: "", videoId: "", loading: false, syncing: false, busy: "", error: "", info: "", analysis: null, activeTab: "overview",
        competitor: {urls: "", workspace: null, loading: false, transcriptVideo: null, transcriptText: "", transcriptLanguage: "English", sourceType: "MANUAL_TEXT", rightsConfirmed: false, languageOverride: "", aiConsent: false, result: null},
        metadata: {workspace: null, loading: false, aiConsent: false, languageOverride: "", current: null, selectedTitle: "0", customTitle: "", edit: {title: "", thumbnailPrompt: "", description: "", chapters: "", keywords: "", hashtags: ""}}
    };
    exports.vue = {data: state, methods: {
        load: load, syncVideo: syncVideo, setTab: setTab, back: function () { navigate("videos"); },
        formatNumber: function (value) { return Number(value || 0).toLocaleString(); }, formatDate: formatDate, formatDuration: formatDuration,
        formatWatchMinutes: formatWatchMinutes, formatAverageDuration: formatAverageDuration, formatAveragePercentage: formatAveragePercentage, syncErrorActions: syncErrorActions,
        addCompetitorVideos: addCompetitorVideos, removeCompetitorVideo: removeCompetitorVideo, openCompetitorTranscript: openCompetitorTranscript,
        closeCompetitorTranscript: closeCompetitorTranscript, loadCompetitorTranscriptFile: loadCompetitorTranscriptFile, saveCompetitorTranscript: saveCompetitorTranscript, generateCompetitorResearch: generateCompetitorResearch,
        competitorResearch: competitorResearch, evidenceText: evidenceText, formatRate: formatRate,
        loadMetadataWorkspace: loadMetadataWorkspace, generateMetadataSuggestions: generateMetadataSuggestions, selectSuggestion: selectSuggestion, selectMetadataTitle: selectMetadataTitle,
        markMetadataApplied: markMetadataApplied, formatChapterTime: formatChapterTime, metricLabel: metricLabel, formatMetricValue: formatMetricValue, formatChange: formatChange
    }, onReady: function () { initialize(); }};
    exports.onReady = function () {};

    function initialize() {
        api = exports.getComponent("api"); sync = exports.getComponent("youtube-sync"); workbench = exports.getComponent("growth-workbench"); ai = exports.getComponent("growth-ai"); metadataWorkflow = exports.getComponent("metadata-workflow");
        state.channelId = query("channelId") || window.localStorage.getItem("ytg.selectedChannelId") || ""; state.videoId = query("videoId");
        if (!state.channelId || !state.videoId) { state.error = "Channel and video IDs are required."; return; }
        if (!workbench || !ai || !metadataWorkflow) { state.error = "Video intelligence services are not loaded."; return; }
        load();
    }
    function setTab(tab) { state.activeTab = tab; if (tab === "competitors" && !state.competitor.workspace) { loadCompetitorWorkspace(); } }
    function load(preserveMessages) {
        state.loading = true; if (!preserveMessages) { state.error = ""; state.info = ""; }
        api.services.GetVideoAnalysis({channelId: state.channelId, videoId: state.videoId}).then(function (response) {
            state.loading = false;
            if (response.success) { state.analysis = response.result; loadCompetitorWorkspace(); loadMetadataWorkspace(); }
            else { state.error = msg(response, "Video analysis could not be loaded."); }
        }).error(function () { state.loading = false; state.error = "Video analysis could not be loaded."; });
    }
    function loadCompetitorWorkspace() {
        if (state.competitor.loading) { return; } state.competitor.loading = true;
        workbench.services.GetCompetitorVideoWorkspace({channelId: state.channelId, videoId: state.videoId}).then(function (response) {
            state.competitor.loading = false;
            if (response.success) { state.competitor.workspace = response.result; }
            else { state.error = msg(response, "Competitor video research could not be loaded."); }
        }).error(function () { state.competitor.loading = false; state.error = "Competitor video research could not be loaded."; });
    }
    function loadMetadataWorkspace() {
        if (state.metadata.loading) { return; } state.metadata.loading = true;
        metadataWorkflow.services.GetMetadataWorkspace({channelId: state.channelId, videoId: state.videoId}).then(function (response) {
            state.metadata.loading = false;
            if (!response.success) { state.error = msg(response, "Metadata suggestions and application history could not be loaded."); return; }
            state.metadata.workspace = response.result;
            if (!state.metadata.current && response.result.suggestions && response.result.suggestions.length) { selectSuggestion(response.result.suggestions[0]); }
        }).error(function () { state.metadata.loading = false; state.error = "Metadata suggestions and application history could not be loaded."; });
    }
    function generateMetadataSuggestions() {
        if (state.busy) { return; }
        if (!state.analysis || !state.analysis.transcript) { state.error = "Import or download the inspected video's transcript before generating suggestions."; return; }
        if (!state.metadata.aiConsent) { state.error = "Confirm the saved-agent data disclosure for this generation action first."; return; }
        state.busy = "GenerateMetadataSuggestions"; state.error = ""; state.info = "";
        ai.services.GenerateMetadataSuggestions({channelId: state.channelId, videoId: state.videoId, languageOverride: state.metadata.languageOverride, confirmAgentDataShare: true}).then(function (response) {
            state.busy = "";
            if (!response.success) { state.error = msg(response, "Metadata suggestions could not be generated."); return; }
            selectSuggestion(response.result.suggestion); state.info = response.result.message; loadMetadataWorkspace();
        }).error(function () { state.busy = ""; state.error = "Metadata suggestions could not be generated."; });
    }
    function selectSuggestion(row) {
        if (!row) { return; } state.metadata.current = row; state.metadata.selectedTitle = "0"; state.metadata.customTitle = "";
        var suggestions = row.suggestions || {}, pairs = suggestions.titlePromptPairs || [], first = pairs[0] || {};
        state.metadata.edit.title = first.title || ""; state.metadata.edit.thumbnailPrompt = first.thumbnailPrompt || "";
        state.metadata.edit.description = suggestions.description || ""; state.metadata.edit.chapters = chapterText(suggestions);
        state.metadata.edit.keywords = (suggestions.keywords || []).join(", "); state.metadata.edit.hashtags = (suggestions.hashtags || []).join(" ");
    }
    function selectMetadataTitle() {
        var row = state.metadata.current, index = state.metadata.selectedTitle;
        if (!row) { return; }
        if (index === "custom") { state.metadata.edit.title = state.metadata.customTitle; return; }
        var pair = (row.suggestions.titlePromptPairs || [])[Number(index)]; if (!pair) { return; }
        state.metadata.edit.title = pair.title || ""; state.metadata.edit.thumbnailPrompt = pair.thumbnailPrompt || "";
    }
    function markMetadataApplied() {
        var row = state.metadata.current; if (state.busy || !row) { return; }
        if (state.metadata.selectedTitle === "custom") { state.metadata.edit.title = state.metadata.customTitle; }
        if (!window.confirm("Save these edited values as an immutable marked-as-applied record? This read-only integration will not update YouTube.")) { return; }
        state.busy = "MarkMetadataApplied"; state.error = ""; state.info = "";
        metadataWorkflow.services.MarkMetadataApplied({channelId: state.channelId, videoId: state.videoId, suggestionId: row.suggestionId, finalValues: {title: state.metadata.edit.title, thumbnailPrompt: state.metadata.edit.thumbnailPrompt, description: state.metadata.edit.description, chapters: state.metadata.edit.chapters, keywords: splitList(state.metadata.edit.keywords, false), hashtags: splitList(state.metadata.edit.hashtags, true)}}).then(function (response) {
            state.busy = ""; if (!response.success) { state.error = msg(response, "The application record could not be saved."); return; }
            state.info = response.result.message; loadMetadataWorkspace();
        }).error(function () { state.busy = ""; state.error = "The application record could not be saved."; });
    }
    function splitList(value, hashtags) { return String(value || "").split(hashtags ? /[\s,]+/ : /[,\n]+/).map(function (item) { return item.trim(); }).filter(function (item) { return !!item; }); }
    function chapterText(suggestions) {
        var chapters = suggestions.chapters || []; if (chapters.length) { return chapters.map(function (item) { return formatChapterTime(item.startMs) + " " + item.title; }).join("\n"); }
        var outline = suggestions.untimedTopicOutline || []; return outline.length ? "Untimed outline (source transcript has no timestamps)\n" + outline.map(function (item) { return "- " + item; }).join("\n") : "No source-timestamped chapters are available.";
    }
    function formatChapterTime(ms) { var seconds = Math.max(0, Math.floor(Number(ms || 0) / 1000)), h = Math.floor(seconds / 3600), m = Math.floor((seconds % 3600) / 60), s = seconds % 60; return (h ? h + ":" + (m < 10 ? "0" : "") : "") + m + ":" + (s < 10 ? "0" : "") + s; }
    function metricLabel(name) { return {impressions:"Impressions", impressionsCtr:"Impressions CTR", views:"Views", averageViewDuration:"Average view duration", watchMinutes:"Watch time (minutes)"}[name] || name; }
    function formatMetricValue(name, value) { if (value === null || typeof value === "undefined") { return "Unavailable"; } if (name === "impressionsCtr") { return Number(value).toLocaleString(undefined,{maximumFractionDigits:2}) + "%"; } if (name === "averageViewDuration") { return formatDuration(value); } return Number(value).toLocaleString(undefined,{maximumFractionDigits:2}); }
    function formatChange(metric) { if (!metric || metric.absoluteChange === null) { return metric && metric.status === "ZERO_BASELINE" ? "Percentage unavailable (zero baseline)" : "Not enough comparable data"; } var prefix = Number(metric.absoluteChange) > 0 ? "+" : ""; var percent = metric.percentageChange === null ? "percentage unavailable" : (Number(metric.percentageChange) > 0 ? "+" : "") + Number(metric.percentageChange).toLocaleString(undefined,{maximumFractionDigits:2}) + "%"; return prefix + Number(metric.absoluteChange).toLocaleString(undefined,{maximumFractionDigits:2}) + " (" + percent + ")"; }
    function syncVideo() {
        if (state.syncing) { return; } state.syncing = true; state.error = ""; state.info = "";
        sync.services.SyncVideo({channelId: state.channelId, videoId: state.videoId}).then(function (response) {
            state.syncing = false;
            if (response.success) { var result = response.result || {}; var message = result.message || "Video data refreshed."; if (result.analytics && result.analytics.success === false) { state.error = message; } else { state.info = message; } load(true); }
            else { state.error = msg(response, "Video refresh failed."); }
        }).error(function () { state.syncing = false; state.error = "Video refresh failed."; });
    }
    function addCompetitorVideos() {
        if (state.busy || !String(state.competitor.urls || "").trim()) { return; }
        var urls = String(state.competitor.urls).split(/[\r\n,]+/).map(function (value) { return value.trim(); }).filter(function (value) { return !!value; });
        state.busy = "AddCompetitorVideos"; state.error = ""; state.info = "";
        workbench.services.AddCompetitorVideos({channelId: state.channelId, videoId: state.videoId, urls: urls}).then(function (response) {
            state.busy = "";
            if (!response.success) { state.error = msg(response, "Competitor videos could not be added."); return; }
            state.competitor.workspace = response.result.workspace; state.competitor.urls = ""; state.info = response.result.message || "Competitor videos added.";
            var failed = (response.result.results || []).filter(function (item) { return item.status !== "READY"; });
            if (failed.length) { state.error = failed.map(function (item) { return (item.sourceUrl || item.videoId || "URL") + ": " + item.message; }).join("\n"); }
        }).error(function () { state.busy = ""; state.error = "Competitor videos could not be added."; });
    }
    function removeCompetitorVideo(item) {
        if (state.busy || !item || !window.confirm("Remove this competitor video and its stored transcript from this video workspace?")) { return; }
        state.busy = "RemoveCompetitorVideo"; state.error = ""; state.info = "";
        workbench.services.RemoveCompetitorVideo({channelId: state.channelId, videoId: state.videoId, competitorVideoId: item.youtubeVideoId}).then(function (response) {
            state.busy = ""; if (response.success) { state.competitor.workspace = response.result.workspace; state.competitor.result = null; state.info = response.result.message; } else { state.error = msg(response, "Competitor video could not be removed."); }
        }).error(function () { state.busy = ""; state.error = "Competitor video could not be removed."; });
    }
    function openCompetitorTranscript(item) {
        state.competitor.transcriptVideo = item; state.competitor.transcriptText = ""; state.competitor.transcriptLanguage = item.defaultAudioLanguage || item.defaultLanguage || (state.analysis && state.analysis.video ? state.analysis.video.language : "") || "English"; state.competitor.sourceType = "MANUAL_TEXT"; state.competitor.rightsConfirmed = false;
    }
    function closeCompetitorTranscript() { state.competitor.transcriptVideo = null; state.competitor.transcriptText = ""; state.competitor.rightsConfirmed = false; }
    function loadCompetitorTranscriptFile(event) {
        var file = event && event.target && event.target.files ? event.target.files[0] : null; if (!file) { return; }
        if (file.size > 1000000) { state.error = "Transcript files must be 1 MB or smaller."; event.target.value = ""; return; }
        var reader = new FileReader(); reader.onload = function () { state.competitor.transcriptText = String(reader.result || ""); state.competitor.sourceType = "USER_UPLOAD"; }; reader.onerror = function () { state.error = "Transcript file could not be read."; }; reader.readAsText(file); event.target.value = "";
    }
    function saveCompetitorTranscript() {
        var item = state.competitor.transcriptVideo; if (state.busy || !item) { return; }
        if (!state.competitor.rightsConfirmed) { state.error = "Confirm that you have the rights or permission to analyse this transcript."; return; }
        if (!String(state.competitor.transcriptText || "").trim()) { state.error = "Paste or upload transcript text first."; return; }
        state.busy = "ImportCompetitorTranscript"; state.error = ""; state.info = "";
        workbench.services.ImportCompetitorTranscript({channelId: state.channelId, videoId: state.videoId, competitorVideoId: item.youtubeVideoId, plainText: state.competitor.transcriptText, language: state.competitor.transcriptLanguage, sourceType: state.competitor.sourceType, rightsConfirmed: true}).then(function (response) {
            state.busy = ""; if (response.success) { state.competitor.workspace = response.result.workspace; closeCompetitorTranscript(); state.info = response.result.message; } else { state.error = msg(response, "Competitor transcript could not be saved."); }
        }).error(function () { state.busy = ""; state.error = "Competitor transcript could not be saved."; });
    }
    function generateCompetitorResearch() {
        var workspace = state.competitor.workspace; if (state.busy || !workspace || !workspace.videos || !workspace.videos.length) { state.error = "Add at least one competitor video first."; return; }
        if (!state.competitor.aiConsent) { state.error = "Confirm the saved-agent data disclosure for this research action first."; return; }
        state.busy = "GenerateCompetitorVideoResearch"; state.error = ""; state.info = ""; state.competitor.result = null;
        ai.services.GenerateCompetitorVideoResearch({channelId: state.channelId, videoId: state.videoId, competitorVideoIds: workspace.videos.map(function (item) { return item.youtubeVideoId; }), languageOverride: state.competitor.languageOverride, confirmAgentDataShare: true}).then(function (response) {
            state.busy = ""; if (response.success) { state.competitor.result = response.result.research; state.info = response.result.message; loadCompetitorWorkspace(); } else { state.error = msg(response, "Competitor research could not be generated."); }
        }).error(function () { state.busy = ""; state.error = "Competitor research could not be generated."; });
    }
    function competitorResearch() {
        if (state.competitor.result) { return state.competitor.result; }
        var saved = state.competitor.workspace && state.competitor.workspace.research ? state.competitor.workspace.research : null; if (!saved) { return null; }
        return {language: {name: saved.detectedLanguage, code: saved.languageCode, confidence: saved.languageConfidence, source: saved.languageSource}, keywords: saved.rankedKeywords || [], hashtags: saved.rankedHashtags || [], description: saved.description || "", tags: saved.tags || [], titlePromptPairs: saved.titlePromptPairs || [], limitations: saved.limitations || [], source: saved.resultSource};
    }
    function evidenceText(item) { return item && item.evidenceVideoIds && item.evidenceVideoIds.length ? item.evidenceVideoIds.join(", ") : "No evidence ID"; }
    function formatRate(item) { return item && item.viewsPerDay !== null && typeof item.viewsPerDay !== "undefined" ? Number(item.viewsPerDay).toLocaleString(undefined, {maximumFractionDigits: 2}) + " views/day estimate" : "Raw public views ranking"; }
    function query(name) { var match = new RegExp("[?&]" + name + "=([^&#]*)").exec(window.location.hash || ""); return match ? decodeURIComponent(match[1].replace(/\+/g, " ")) : ""; }
    function navigate(path) { var routes = exports.getShellComponent("soss-routes"); path += "?channelId=" + encodeURIComponent(state.channelId); if (routes && routes.appNavigate) { routes.appNavigate("../" + path); } else { window.location.hash = "#/app/youtube-growth-agent/" + path; } }
    function formatDate(value) { if (!value) { return "Not available"; } var date = new Date(String(value).replace(" ", "T")); return isNaN(date.getTime()) ? value : date.toLocaleString(); }
    function formatDuration(value) { var seconds = Math.max(0, Number(value || 0)), hours = Math.floor(seconds / 3600), minutes = Math.floor((seconds % 3600) / 60), rest = Math.floor(seconds % 60); return (hours ? hours + ":" : "") + (hours && minutes < 10 ? "0" : "") + minutes + ":" + (rest < 10 ? "0" : "") + rest; }
    function isAnalytics(row) { return row && row.source === "YOUTUBE_ANALYTICS"; }
    function formatWatchMinutes(row) { return isAnalytics(row) ? Number(row.watchMinutes || 0).toLocaleString(undefined, {maximumFractionDigits: 2}) : "N/A"; }
    function formatAverageDuration(row) { return isAnalytics(row) ? formatDuration(row.avgViewDuration) : "N/A"; }
    function formatAveragePercentage(row) { return isAnalytics(row) ? Number(row.avgViewPercentage || 0).toLocaleString(undefined, {maximumFractionDigits: 2}) + "%" : "N/A"; }
    function syncErrorActions(message) { var text = String(message || ""), projectMatch = /project(?:=|\s)(\d{6,20})/i.exec(text), actions = []; if (!projectMatch) { return actions; } var project = encodeURIComponent(projectMatch[1]); if (text.indexOf("youtubeanalytics.googleapis.com") >= 0) { actions.push({label: "Enable Analytics API", url: "https://console.developers.google.com/apis/api/youtubeanalytics.googleapis.com/overview?project=" + project}); } if (text.indexOf("youtubereporting.googleapis.com") >= 0) { actions.push({label: "Enable Reporting API", url: "https://console.developers.google.com/apis/api/youtubereporting.googleapis.com/overview?project=" + project}); } return actions; }
    function msg(response, fallback) { return response && response.result && response.result.message ? response.result.message : fallback; }
});
