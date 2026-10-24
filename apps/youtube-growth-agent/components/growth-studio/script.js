WEBDOCK.component().register(function (exports) {
    var workbench, ai, auth;
    var state = {
        channelId: "", videoId: "", loading: false, busy: "", error: "", info: "", activeTab: "transcript",
        data: null, aiConsent: false, transcriptText: "", transcriptLanguage: "English", captionLanguage: "", aiResult: null,
        autoTranscriptAttempts: {},
        competitor: {reference: "", label: ""},
        calendar: {title: "", format: "VIDEO", plannedAt: "", notes: ""},
        experiment: {name: "", type: "PACKAGING", hypothesis: "", variantsText: "Variant A\nVariant B", primaryMetric: "thumbnailImpressionsCtr"}
    };
    exports.vue = {data: state, methods: {
        load: load, selectVideo: selectVideo, setTab: function (tab) { state.activeTab = tab; state.aiResult = null; },
        importTranscript: importTranscript, requestCaptionAccess: requestCaptionAccess, downloadYouTubeTranscript: function () { downloadYouTubeTranscript(false); },
        exportTranscript: exportTranscript, transcriptPreview: transcriptPreview, transcriptSource: transcriptSource,
        syncRetention: syncRetention, generateShorts: function () { generate("GenerateShortCandidates"); },
        generateBrief: function () { generate("GenerateVideoBrief"); }, generateCompetitorIdeas: function () { generate("GenerateCompetitorIdeas"); }, addCompetitor: addCompetitor, refreshCompetitors: refreshCompetitors,
        saveCalendar: saveCalendar, generatePackaging: function () { generate("GeneratePackagingVariants"); }, syncComments: syncComments,
        analyzeCommunity: function () { generate("AnalyzeCommunity"); }, generateSession: function () { generate("GenerateSessionRecommendations"); },
        createExperiment: createExperiment, updateExperiment: updateExperiment, formatDate: formatDate, formatTime: formatTime, formatNumber: formatNumber, formatRatio: formatRatio,
        resultBlocks: resultBlocks, markdownHtml: markdownHtml, fieldLabel: fieldLabel, youtubeVideoUrl: youtubeVideoUrl,
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
            if (response.result.transcript) { response.result.transcript = normalizeTranscript(response.result.transcript); }
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
            if (response.success) {
                if (response.result && response.result.transcript) {
                    var transcript = normalizeTranscript(response.result.transcript);
                    state.data.transcript = transcript; state.transcriptText = transcript.plainText || ""; state.transcriptLanguage = transcript.language || "English";
                }
                state.info = response.result.message || "Timestamped transcript downloaded.";
            }
            else { state.error = message(response, "The timestamped transcript could not be downloaded."); }
        }).error(function () { state.busy = ""; state.error = "The timestamped transcript could not be downloaded."; });
    }
    function transcriptPreview() {
        var transcript = state.data && state.data.transcript ? state.data.transcript : null;
        return transcript && transcript.segments && transcript.segments.slice ? transcript.segments.slice(0, 200) : [];
    }
    function normalizeTranscript(transcript) {
        if (!transcript || typeof transcript !== "object") { return transcript; }
        var segments = transcript.segments;
        for (var attempt = 0; attempt < 4 && typeof segments === "string"; attempt++) {
            try { segments = JSON.parse(segments); } catch (error) { segments = []; break; }
        }
        if (segments && !Array.isArray(segments) && typeof segments === "object") {
            segments = segments.segments || segments.items || segments.values || segments.data || Object.keys(segments).sort(function (a, b) { return Number(a) - Number(b); }).map(function (key) { return segments[key]; });
        }
        transcript.segments = Array.isArray(segments) ? segments.map(function (item) {
            if (typeof item === "string") { try { return JSON.parse(item); } catch (error) { return null; } }
            return item;
        }).filter(function (item) { return item && typeof item === "object" && isFinite(Number(item.startMs)) && isFinite(Number(item.endMs)) && String(item.text || "").trim(); }) : [];
        if (!transcript.plainText && transcript.segments.length) { transcript.plainText = transcript.segments.map(function (item) { return item.text; }).join("\n"); }
        return transcript;
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
    function addCompetitor() {
        if (state.busy || !state.competitor.reference) { return; }
        state.busy = "AddCompetitor"; state.error = ""; state.info = "";
        workbench.services.AddCompetitor({channelId: state.channelId, competitorReference: state.competitor.reference, label: state.competitor.label}).then(function (response) {
            if (!response.success) { state.busy = ""; state.error = message(response, "Comparison channel could not be added."); return; }
            var competitorId = response.result && response.result.competitor ? response.result.competitor.competitorId : 0;
            state.busy = "RefreshCompetitors";
            return workbench.services.RefreshCompetitors({channelId: state.channelId, competitorId: competitorId}).then(function (refreshResponse) {
                state.busy = "";
                if (!refreshResponse.success) { state.error = message(refreshResponse, "Channel was added, but its latest videos could not be analyzed."); load(); return; }
                state.competitor.reference = ""; state.competitor.label = "";
                state.info = refreshResponse.result.message || "Comparison channel added and analyzed."; load();
            }).error(function () { state.busy = ""; state.error = "Channel was added, but its latest videos could not be analyzed."; load(); });
        }).error(function () { state.busy = ""; state.error = "Comparison channel could not be added."; });
    }
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
    function formatNumber(value) { return Number(value || 0).toLocaleString(); }
    function formatRatio(value) { return value === null || typeof value === "undefined" ? "Unavailable" : (Number(value) * 100).toFixed(2) + "%"; }

    function resultBlocks(value) {
        value = parseJsonValue(value);
        if (value === null || typeof value === "undefined" || value === "") { return []; }
        if (typeof value !== "object") { return [{title: "Suggestion", subtitle: "", items: [resultItem(value)]}]; }
        if (Array.isArray(value)) { return [{title: "Suggestions", subtitle: "", items: resultItems(value)}]; }
        var blocks = [];
        Object.keys(value).forEach(function (key) {
            if (key === "task" || key === "dataSource" || key === "source") { return; }
            var sectionValue = parseJsonValue(value[key]);
            var subtitle = value.dataSource ? fieldLabel(value.dataSource) : "";
            if (sectionValue && typeof sectionValue === "object" && !Array.isArray(sectionValue)) {
                var collection = sectionValue.observations || sectionValue.suggestions || sectionValue.items;
                if (Array.isArray(collection)) {
                    blocks.push({title: fieldLabel(key), subtitle: sectionValue.dataSource ? fieldLabel(sectionValue.dataSource) : subtitle, items: resultItems(collection)});
                    return;
                }
            }
            blocks.push({title: fieldLabel(key), subtitle: subtitle, items: Array.isArray(sectionValue) ? resultItems(sectionValue) : [resultItem(sectionValue)]});
        });
        return blocks.length ? blocks : [{title: "Suggestion", subtitle: "", items: [resultItem(value)]}];
    }

    function resultItems(values) {
        var items = [];
        for (var index = 0; index < values.length; index++) { items.push(resultItem(parseJsonValue(values[index]))); }
        return items;
    }

    function resultItem(value) {
        if (value === null || typeof value === "undefined") { return {heading: "", text: "Not available", details: [], videoIds: []}; }
        if (typeof value !== "object") { return {heading: "", text: String(value), details: [], videoIds: []}; }
        if (Array.isArray(value)) { return {heading: "", text: value.map(displayValue).join("\n"), details: [], videoIds: []}; }
        var headingKeys = ["title", "name", "primaryTopic", "theme"];
        var textKeys = ["text", "reasoning", "hook", "concept", "draft", "audiencePromise", "hypothesis", "promise", "description"];
        var heading = firstValue(value, headingKeys);
        var text = firstValue(value, textKeys);
        if (!text && heading) { text = heading; heading = ""; }
        var used = {};
        headingKeys.concat(textKeys).forEach(function (key) { if (value[key] !== null && typeof value[key] !== "undefined" && value[key] !== "") { used[key] = true; } });
        var videoIds = [];
        ["evidenceVideoIds", "videoIds"].forEach(function (key) {
            if (Array.isArray(value[key])) { value[key].forEach(function (id) { addVideoId(videoIds, id); }); used[key] = true; }
        });
        if (value.targetVideoId) { addVideoId(videoIds, value.targetVideoId); used.targetVideoId = true; }
        var details = [];
        Object.keys(value).forEach(function (key) {
            if (used[key] || key === "task" || key === "dataSource" || key === "source") { return; }
            var detailValue = displayValue(value[key]);
            if (detailValue !== "") { details.push({label: fieldLabel(key), value: detailValue}); }
        });
        if (!text && !heading && details.length) { text = details.shift().value; }
        return {heading: heading, text: text || "Suggestion", details: details, videoIds: videoIds};
    }

    function firstValue(value, keys) {
        for (var index = 0; index < keys.length; index++) {
            var item = value[keys[index]];
            if (item !== null && typeof item !== "undefined" && item !== "") { return displayValue(item); }
        }
        return "";
    }

    function addVideoId(items, value) {
        var id = String(value || "").trim();
        if (/^[A-Za-z0-9_-]{6,80}$/.test(id) && items.indexOf(id) < 0) { items.push(id); }
    }

    function displayValue(value) {
        value = parseJsonValue(value);
        if (value === null || typeof value === "undefined") { return ""; }
        if (value === true) { return "Yes"; }
        if (value === false) { return "No"; }
        if (Array.isArray(value)) {
            return value.map(function (item) { return displayValue(item); }).filter(function (item) { return item !== ""; }).join("\n");
        }
        if (typeof value === "object") {
            return Object.keys(value).map(function (key) { var item = displayValue(value[key]); return item === "" ? "" : "**" + fieldLabel(key) + ":** " + item; }).filter(function (item) { return item !== ""; }).join("\n");
        }
        return String(value);
    }

    function parseJsonValue(value) {
        if (typeof value !== "string") { return value; }
        var trimmed = value.trim();
        if (!trimmed || ((trimmed.charAt(0) !== "{" || trimmed.charAt(trimmed.length - 1) !== "}") && (trimmed.charAt(0) !== "[" || trimmed.charAt(trimmed.length - 1) !== "]"))) { return value; }
        try { return JSON.parse(trimmed); } catch (error) { return value; }
    }

    function fieldLabel(value) {
        var text = String(value || "").replace(/_/g, " ").replace(/([a-z0-9])([A-Z])/g, "$1 $2").trim();
        if (!text) { return "Details"; }
        return text.toLowerCase().replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
    }

    function markdownHtml(value) {
        var lines = String(value === null || typeof value === "undefined" ? "" : value).replace(/\r\n?/g, "\n").split("\n");
        var html = []; var listType = "";
        function closeList() { if (listType) { html.push("</" + listType + ">"); listType = ""; } }
        lines.forEach(function (line) {
            var trimmed = line.trim(); var match;
            if ((match = /^[-*]\s+(.+)$/.exec(trimmed))) { if (listType !== "ul") { closeList(); listType = "ul"; html.push("<ul>"); } html.push("<li>" + inlineMarkdown(match[1]) + "</li>"); return; }
            if ((match = /^\d+[.)]\s+(.+)$/.exec(trimmed))) { if (listType !== "ol") { closeList(); listType = "ol"; html.push("<ol>"); } html.push("<li>" + inlineMarkdown(match[1]) + "</li>"); return; }
            closeList();
            if (!trimmed) { return; }
            if ((match = /^(#{1,4})\s+(.+)$/.exec(trimmed))) { var level = Math.min(6, match[1].length + 3); html.push("<h" + level + ">" + inlineMarkdown(match[2]) + "</h" + level + ">"); return; }
            if ((match = /^>\s?(.+)$/.exec(trimmed))) { html.push("<blockquote>" + inlineMarkdown(match[1]) + "</blockquote>"); return; }
            html.push("<p>" + inlineMarkdown(trimmed) + "</p>");
        });
        closeList();
        return html.join("");
    }

    function inlineMarkdown(value) {
        var escaped = escapeHtml(value);
        escaped = escaped.replace(/`([^`]+)`/g, "<code>$1</code>");
        escaped = escaped.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
        escaped = escaped.replace(/__([^_]+)__/g, "<strong>$1</strong>");
        escaped = escaped.replace(/\*([^*]+)\*/g, "<em>$1</em>");
        escaped = escaped.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, function (match, label, url) { return '<a href="' + escapeAttribute(url) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>'; });
        return escaped;
    }

    function escapeHtml(value) { return String(value).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#39;"); }
    function escapeAttribute(value) { return String(value).replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;"); }
    function youtubeVideoUrl(videoId) { return "https://www.youtube.com/watch?v=" + encodeURIComponent(String(videoId || "")); }
});
