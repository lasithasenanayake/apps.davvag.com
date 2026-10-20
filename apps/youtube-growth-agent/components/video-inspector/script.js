WEBDOCK.component().register(function (exports) {
    var api, sync, workbench, ai;
    var state = {
        channelId: "", videoId: "", loading: false, syncing: false, busy: "", error: "", info: "", analysis: null, activeTab: "overview",
        competitor: {urls: "", workspace: null, loading: false, transcriptVideo: null, transcriptText: "", transcriptLanguage: "English", sourceType: "MANUAL_TEXT", rightsConfirmed: false, languageOverride: "", aiConsent: false, result: null}
    };
    exports.vue = {data: state, methods: {
        load: load, syncVideo: syncVideo, setTab: setTab, back: function () { navigate("videos"); },
        formatNumber: function (value) { return Number(value || 0).toLocaleString(); }, formatDate: formatDate, formatDuration: formatDuration,
        formatWatchMinutes: formatWatchMinutes, formatAverageDuration: formatAverageDuration, formatAveragePercentage: formatAveragePercentage, syncErrorActions: syncErrorActions,
        addCompetitorVideos: addCompetitorVideos, removeCompetitorVideo: removeCompetitorVideo, openCompetitorTranscript: openCompetitorTranscript,
        closeCompetitorTranscript: closeCompetitorTranscript, loadCompetitorTranscriptFile: loadCompetitorTranscriptFile, saveCompetitorTranscript: saveCompetitorTranscript, generateCompetitorResearch: generateCompetitorResearch,
        competitorResearch: competitorResearch, evidenceText: evidenceText, formatRate: formatRate
    }, onReady: function () { initialize(); }};
    exports.onReady = function () {};

    function initialize() {
        api = exports.getComponent("api"); sync = exports.getComponent("youtube-sync"); workbench = exports.getComponent("growth-workbench"); ai = exports.getComponent("growth-ai");
        state.channelId = query("channelId") || window.localStorage.getItem("ytg.selectedChannelId") || ""; state.videoId = query("videoId");
        if (!state.channelId || !state.videoId) { state.error = "Channel and video IDs are required."; return; }
        if (!workbench || !ai) { state.error = "Competitor research services are not loaded."; return; }
        load();
    }
    function setTab(tab) { state.activeTab = tab; if (tab === "competitors" && !state.competitor.workspace) { loadCompetitorWorkspace(); } }
    function load(preserveMessages) {
        state.loading = true; if (!preserveMessages) { state.error = ""; state.info = ""; }
        api.services.GetVideoAnalysis({channelId: state.channelId, videoId: state.videoId}).then(function (response) {
            state.loading = false;
            if (response.success) { state.analysis = response.result; loadCompetitorWorkspace(); }
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
