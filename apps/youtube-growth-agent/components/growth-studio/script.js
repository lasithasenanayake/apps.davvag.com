WEBDOCK.component().register(function (exports) {
    var workbench, ai, auth;
    var state = {
        channelId: "", videoId: "", loading: false, busy: "", error: "", info: "", activeTab: "transcript",
        data: null, aiConsent: false, transcriptText: "", transcriptLanguage: "English", captionLanguage: "", aiResult: null,
        autoTranscriptAttempts: {},
        competitor: {youtubeChannelId: "", label: ""},
        calendar: {title: "", format: "VIDEO", plannedAt: "", notes: ""},
        experiment: {name: "", type: "PACKAGING", hypothesis: "", variantsText: "Variant A\nVariant B", primaryMetric: "thumbnailImpressionsCtr"}
    };
    exports.vue = {data: state, methods: {
        load: load, selectVideo: selectVideo, setTab: function (tab) { state.activeTab = tab; state.aiResult = null; },
        importTranscript: importTranscript, requestCaptionAccess: requestCaptionAccess, downloadYouTubeTranscript: function () { downloadYouTubeTranscript(false); },
        exportTranscript: exportTranscript, transcriptPreview: transcriptPreview, transcriptSource: transcriptSource,
        syncRetention: syncRetention, generateShorts: function () { generate("GenerateShortCandidates"); },
        generateBrief: function () { generate("GenerateVideoBrief"); }, addCompetitor: addCompetitor, refreshCompetitors: refreshCompetitors,
        saveCalendar: saveCalendar, generatePackaging: function () { generate("GeneratePackagingVariants"); }, syncComments: syncComments,
        analyzeCommunity: function () { generate("AnalyzeCommunity"); }, generateSession: function () { generate("GenerateSessionRecommendations"); },
        createExperiment: createExperiment, updateExperiment: updateExperiment, formatDate: formatDate, formatTime: formatTime,
        retentionHeight: function (point) { return Math.max(3, Math.min(100, Number(point.audienceWatchRatio || 0) * 100)) + "%"; },
        back: function () { navigate("dashboard"); }
    }, onReady: initialize};
    exports.onReady = function () {};

    function initialize() {
        workbench = exports.getComponent("growth-workbench"); ai = exports.getComponent("growth-ai"); auth = exports.getComponent("youtube-auth");
        state.channelId = query("channelId") || window.localStorage.getItem("ytg.selectedChannelId") || "";
        state.videoId = query("videoId") || "";
        if (!state.channelId) { state.error = "Select a channel workspace first."; return; }
        if (!workbench || !ai || !auth) { state.error = "Growth Studio services are not loaded."; return; }
        window.addEventListener("message", oauthMessage);
        load();
    }
    function load() {
        if (state.loading) { return; } state.loading = true; state.error = "";
        workbench.services.GetWorkbench({channelId: state.channelId, videoId: state.videoId}).then(function (response) {
            state.loading = false;
            if (!response.success) { state.error = message(response, "Growth Studio could not be loaded."); return; }
            state.data = response.result; state.videoId = response.result.selectedVideoId || state.videoId;
            if (response.result.transcript) {
                state.transcriptText = response.result.transcript.plainText || "";
                state.transcriptLanguage = response.result.transcript.language || "English";
            } else {
                state.transcriptText = "";
                var capabilities = response.result.capabilities || {};
                if (state.videoId && capabilities.automaticTranscriptImport && !state.autoTranscriptAttempts[state.videoId]) {
                    state.autoTranscriptAttempts[state.videoId] = true;
                    window.setTimeout(function () { downloadYouTubeTranscript(true); }, 0);
                }
            }
        }).error(function () { state.loading = false; state.error = "Growth Studio could not be loaded."; });
    }
    function selectVideo() { state.transcriptText = ""; state.aiResult = null; load(); }
    function importTranscript() {
        call(workbench, "ImportTranscript", {channelId: state.channelId, videoId: state.videoId, plainText: state.transcriptText, language: state.transcriptLanguage}, "Transcript saved.");
    }
    function requestCaptionAccess() {
        if (state.busy) { return; }
        var popup = window.open("about:blank", "ytgCaptionOAuth", "width=720,height=780");
        if (!popup) { state.error = "Google authorization popup was blocked."; return; }
        state.busy = "StartCaptionConnect"; state.error = ""; state.info = "";
        auth.services.StartCaptionConnect({channelId: state.channelId}).then(function (response) {
            state.busy = "";
            if (response.success) { popup.location.href = response.result.authUrl; }
            else { popup.close(); state.error = message(response, "Caption authorization could not be started."); }
        }).error(function () { state.busy = ""; popup.close(); state.error = "Caption authorization could not be started."; });
    }
    function oauthMessage(event) {
        if (!event || event.origin !== window.location.origin || !event.data || event.data.type !== "ytg-oauth-complete") { return; }
        if (event.data.success) {
            state.info = "Automatic timestamped caption downloads are enabled.";
            if (state.videoId) { delete state.autoTranscriptAttempts[state.videoId]; }
            load();
        } else { state.error = "Caption authorization was cancelled or failed."; }
    }
    function downloadYouTubeTranscript(automatic) {
        if (!state.videoId || state.busy) { return; }
        state.busy = "DownloadTranscript"; state.error = ""; state.info = automatic ? "Downloading the available YouTube caption track..." : "";
        workbench.services.DownloadTranscript({channelId: state.channelId, videoId: state.videoId, language: state.captionLanguage}).then(function (response) {
            state.busy = "";
            if (response.success) { state.info = response.result.message || "Timestamped transcript downloaded."; load(); }
            else { state.error = message(response, "The timestamped transcript could not be downloaded."); }
        }).error(function () { state.busy = ""; state.error = "The timestamped transcript could not be downloaded."; });
    }
    function transcriptPreview() {
        var transcript = state.data && state.data.transcript ? state.data.transcript : null;
        return transcript && transcript.segments && transcript.segments.slice ? transcript.segments.slice(0, 200) : [];
    }
    function transcriptSource() {
        var transcript = state.data && state.data.transcript ? state.data.transcript : null;
        if (!transcript) { return "No transcript"; }
        return transcript.sourceType === "YOUTUBE_CAPTION" ? "YouTube caption" : "User-provided";
    }
    function exportTranscript() {
        var transcript = state.data && state.data.transcript ? state.data.transcript : null;
        var segments = transcript && transcript.segments ? transcript.segments : [];
        if (!segments.length || !window.Blob || !window.URL || !window.URL.createObjectURL) { state.error = "No timestamped transcript is available to export."; return; }
        var lines = [];
        for (var index = 0; index < segments.length; index++) {
            lines.push("[" + formatTime(segments[index].startMs) + " - " + formatTime(segments[index].endMs) + "] " + (segments[index].text || ""));
        }
        var url = window.URL.createObjectURL(new Blob([lines.join("\r\n")], {type: "text/plain;charset=utf-8"}));
        var link = document.createElement("a");
        link.href = url; link.download = "youtube-transcript-" + state.videoId + "-timestamped.txt";
        document.body.appendChild(link); link.click(); document.body.removeChild(link);
        window.setTimeout(function () { window.URL.revokeObjectURL(url); }, 1000);
    }
    function syncRetention() { call(workbench, "SyncRetention", {channelId: state.channelId, videoId: state.videoId}, "Retention refreshed."); }
    function syncComments() { call(workbench, "SyncComments", {channelId: state.channelId, videoId: state.videoId}, "Comments refreshed."); }
    function addCompetitor() { call(workbench, "AddCompetitor", {channelId: state.channelId, youtubeChannelId: state.competitor.youtubeChannelId, label: state.competitor.label}, "Comparison channel added.", function () { state.competitor.youtubeChannelId = ""; state.competitor.label = ""; }); }
    function refreshCompetitors() { call(workbench, "RefreshCompetitors", {channelId: state.channelId}, "Comparison channels refreshed."); }
    function saveCalendar() { call(workbench, "SaveCalendarItem", {channelId: state.channelId, title: state.calendar.title, format: state.calendar.format, plannedAt: state.calendar.plannedAt, notes: state.calendar.notes}, "Calendar item saved.", function () { state.calendar.title = ""; state.calendar.notes = ""; }); }
    function createExperiment() {
        call(workbench, "CreateExperiment", {channelId: state.channelId, videoId: state.videoId, name: state.experiment.name, type: state.experiment.type, hypothesis: state.experiment.hypothesis, variants: String(state.experiment.variantsText || "").split(/\r?\n/), primaryMetric: state.experiment.primaryMetric}, "Experiment created.");
    }
    function updateExperiment(item, status) { call(workbench, "UpdateExperiment", {channelId: state.channelId, experimentId: item.experimentId, status: status, result: item.result || "", limitations: item.limitations || ""}, "Experiment updated."); }
    function generate(method) {
        if (!state.aiConsent) { state.error = "Confirm the saved-agent data disclosure for this action first."; return; }
        if (!state.videoId || state.busy) { return; } state.busy = method; state.error = ""; state.info = ""; state.aiResult = null;
        ai.services[method]({channelId: state.channelId, videoId: state.videoId, confirmAgentDataShare: true}).then(function (response) {
            state.busy = "";
            if (response.success) { state.aiResult = response.result; state.info = response.result.message || "AI-assisted result generated."; load(); }
            else { state.error = message(response, "Generation failed."); }
        }).error(function () { state.busy = ""; state.error = "Generation failed."; });
    }
    function call(component, method, payload, successMessage, after) {
        if (state.busy) { return; } state.busy = method; state.error = ""; state.info = "";
        component.services[method](payload).then(function (response) {
            state.busy = "";
            if (response.success) { state.info = response.result && response.result.message ? response.result.message : successMessage; if (after) { after(); } load(); }
            else { state.error = message(response, successMessage + " Failed."); }
        }).error(function () { state.busy = ""; state.error = successMessage + " Failed."; });
    }
    function query(name) { var match = new RegExp("[?&]" + name + "=([^&#]*)").exec(window.location.hash || ""); return match ? decodeURIComponent(match[1].replace(/\+/g, " ")) : ""; }
    function navigate(path) { var routes = exports.getShellComponent("soss-routes"); path += "?channelId=" + encodeURIComponent(state.channelId); if (routes && routes.appNavigate) { routes.appNavigate("../" + path); } else { window.location.hash = "#/app/youtube-growth-agent/" + path; } }
    function message(response, fallback) { return response && response.result && response.result.message ? response.result.message : fallback; }
    function formatDate(value) { if (!value) { return "Not set"; } var date = new Date(String(value).replace(" ", "T")); return isNaN(date.getTime()) ? value : date.toLocaleString(); }
    function formatTime(value) { var seconds = Math.max(0, Math.floor(Number(value || 0) / 1000)); var minutes = Math.floor(seconds / 60); var rest = seconds % 60; return minutes + ":" + (rest < 10 ? "0" : "") + rest; }
});
