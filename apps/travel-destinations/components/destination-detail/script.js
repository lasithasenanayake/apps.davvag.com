WEBDOCK.component().register(function (exports) {
    var api, maps, router, rootElement, mapHandle, mapRenderTimer;
    var mapRenderAttempts = 0, mapRenderStarted = false;
    var state = {
        destination: emptyDestination(), hasDestination: false, reviews: [], comments: [], conditions: [], capabilities: {}, mapConfig:{enabled:false}, mapError:"",
        loading: true, error: "", actionMessage: "", savingFavorite: false, actionLocks: {review:false,comment:false,condition:false,report:false},
        reviewForm: {overall_rating: 5, review_title: "", review_markdown: "", visit_date: ""},
        commentForm: {comment_markdown: ""},
        conditionForm: {report_type: "general_update", description: ""}
    };
    var viewState = state;
    exports.vue = {
        data: state,
        methods: {back: back, share: share, saveFavorite: saveFavorite, submitReview: submitReview, submitComment: submitComment, submitCondition: submitCondition, markHelpful: markHelpful, reportDestination: reportDestination, number: number},
        computed: {
            descriptionHtml: function () { return safeMarkdown(viewState.destination.description_markdown); },
            locationLabel: function () {
                return [viewState.destination.village, viewState.destination.nearest_town, viewState.destination.district, viewState.destination.province].filter(Boolean).join(", ");
            },
            directionsUrl: function () {
                if (viewState.mapConfig.enabled && viewState.destination.latitude !== undefined) {
                    return "https://www.google.com/maps/dir/?api=1&destination=" + encodeURIComponent(viewState.destination.latitude + "," + viewState.destination.longitude);
                }
                return viewState.destination.directions_url || "";
            }
        },
        onReady: function (scope, element) {
            viewState = scope || state; rootElement = element;
            api = exports.getComponent("api"); maps = resolveMaps(exports); router = exports.getShellComponent("soss-routes");
            if (mapRenderTimer) { clearTimeout(mapRenderTimer); }
            mapRenderAttempts = 0; mapRenderStarted = false; mapHandle = null;
            viewState.loading = true; viewState.error = ""; viewState.hasDestination = false; viewState.destination = emptyDestination();
            if (!api || !api.services) { viewState.loading = false; viewState.error = "Destination services are unavailable."; return; }
            var id = queryValue("id");
            if (!id) { viewState.loading = false; viewState.error = "No destination was selected."; return; }
            Promise.all([api.services.Capabilities({}), api.services.GetDestination({id: Number(id)}), api.services.GetMapConfiguration({})]).then(function (responses) {
                viewState.capabilities = responses[0].success ? (responses[0].result || {}) : {};
                if (!responses[1].success) { throw new Error("Destination was not found."); }
                viewState.mapConfig = responses[2].success ? (responses[2].result || {enabled:false}) : {enabled:false};
                viewState.destination = normalizeDestination(responses[1].result); viewState.hasDestination = true; viewState.loading = false; loadCommunity(); scheduleMap();
            }).catch(function (error) { viewState.hasDestination = false; viewState.loading = false; viewState.error = error.message || "Destination could not be loaded."; });
        }
    };
    function emptyDestination() {
        return {id:null,name:"",short_summary:"",description_markdown:"",verification_status:"",categories:[],amenities:[],rating_average:0,review_count:0};
    }
    function normalizeDestination(destination) {
        var normalized = Object.assign(emptyDestination(), destination || {});
        normalized.categories = Array.isArray(normalized.categories) ? normalized.categories : [];
        normalized.amenities = Array.isArray(normalized.amenities) ? normalized.amenities : [];
        return normalized;
    }
    function loadCommunity() {
        if (!viewState.hasDestination || !viewState.destination.id) { return; }
        var request = {destinationId: viewState.destination.id, page: 0, pageSize: 30};
        api.services.GetDestinationReviews(request).then(function (r) { replaceItems(viewState.reviews,resultItems(r)); });
        api.services.GetDestinationComments(request).then(function (r) { replaceItems(viewState.comments,resultItems(r)); });
        api.services.GetDestinationConditions(request).then(function (r) { replaceItems(viewState.conditions,resultItems(r)); });
    }
    function saveFavorite() {
        if (viewState.savingFavorite) { return; } viewState.savingFavorite = true;
        api.services.SaveFavorite({destination_id: viewState.destination.id}).then(function (r) { viewState.savingFavorite = false; if (!r.success) { viewState.error = message(r, "Could not save this place."); } }).error(function () { viewState.savingFavorite = false; viewState.error = "Could not save this place."; });
    }
    function submitReview() {
        if (String(viewState.reviewForm.review_markdown || "").trim().length < 2) {
            viewState.error = "Write at least two characters in your review.";
            return;
        }
        var payload = JSON.parse(JSON.stringify(viewState.reviewForm)); payload.destination_id = viewState.destination.id;
        locked("review", function () {
            return api.services.SaveReview(payload);
        }, function (r) {
            if (r && r.success) {
                viewState.reviewForm = {overall_rating:5,review_title:"",review_markdown:"",visit_date:""};
                viewState.actionMessage = "Your review was submitted and is awaiting moderation.";
            } else {
                viewState.error = message(r, "Review could not be submitted.");
            }
        }, "Review could not be submitted.");
    }
    function submitComment() {
        var commentText = String(viewState.commentForm.comment_markdown || "").trim();
        if (commentText.length < 2) {
            viewState.error = "Write at least two characters in your comment.";
            return;
        }
        locked("comment", function () {
            return api.services.SaveComment({destination_id:viewState.destination.id,comment_markdown:commentText});
        }, function (r) {
            if (r && r.success) {
                viewState.commentForm.comment_markdown = "";
                viewState.actionMessage = "Your comment was submitted and is awaiting moderation.";
            } else {
                viewState.error = message(r, "Comment could not be submitted.");
            }
        }, "Comment could not be submitted.");
    }
    function submitCondition() {
        var payload = JSON.parse(JSON.stringify(viewState.conditionForm)); payload.destination_id = viewState.destination.id;
        locked("condition", function () {
            return api.services.SubmitConditionReport(payload);
        }, function (r) {
            if (r && r.success) {
                viewState.conditionForm.description = "";
                viewState.actionMessage = "Your condition report was submitted and is awaiting moderation.";
            } else {
                viewState.error = message(r, "Condition could not be submitted.");
            }
        }, "Condition could not be submitted.");
    }
    function markHelpful(review) {
        var key = "helpful-" + review.id;
        locked(key, function () {
            return api.services.MarkReviewHelpful({review_id:review.id});
        }, function (r) {
            if (r && r.success) { review.helpful_count = r.result.helpful_count; }
            else { viewState.error = message(r, "Helpful vote could not be saved."); }
        }, "Helpful vote could not be saved.");
    }
    function reportDestination() {
        locked("report", function () {
            return api.services.SubmitContentReport({entity_type:"destination",entity_id:viewState.destination.id,reason:"misleading_description",description:"Traveler requested moderator review from destination detail."});
        }, function (r) {
            if (r && r.success) { viewState.actionMessage = "Your report was sent to the moderation team."; }
            else { viewState.error = message(r, "Report could not be submitted."); }
        }, "Report could not be submitted.");
    }
    function locked(key, factory, handleResponse, failureMessage) {
        if (viewState.actionLocks[key]) { return; }
        viewState.error = ""; viewState.actionMessage = "";
        setLock(key, true);
        var request;
        try { request = factory(); } catch (error) { setLock(key, false); viewState.error = error.message; return; }
        if (!request || !request.then) { setLock(key, false); viewState.error = failureMessage || "The request could not be completed."; return; }
        request.then(function (response) {
            try { if (handleResponse) { handleResponse(response); } }
            finally { setLock(key, false); }
        }).error(function (error) {
            setLock(key, false);
            viewState.error = message(error, failureMessage || "The request could not be completed.");
        });
    }
    function setLock(key, value) {
        if (typeof Vue !== "undefined" && Vue.set) { Vue.set(viewState.actionLocks,key,value); }
        else { viewState.actionLocks[key] = value; }
    }
    function safeMarkdown(value) {
        var lines = String(value || "").replace(/\r\n?/g,"\n").split("\n");
        var html = [];
        var paragraph = [];
        var index = 0;

        function flushParagraph() {
            if (!paragraph.length) { return; }
            html.push("<p>" + paragraph.map(inlineMarkdown).join("<br>") + "</p>");
            paragraph = [];
        }

        while (index < lines.length) {
            var line = lines[index];
            var heading = /^(#{1,3})\s+(.+)$/.exec(line);

            if (line.indexOf("|") !== -1
                && index + 1 < lines.length
                && isTableSeparator(lines[index + 1])) {
                flushParagraph();
                var headings = tableCells(line);
                var alignments = tableCells(lines[index + 1]).map(tableAlignment);
                var bodyRows = [];
                index += 2;
                while (index < lines.length && lines[index].trim() !== "" && lines[index].indexOf("|") !== -1) {
                    bodyRows.push(tableCells(lines[index]));
                    index++;
                }
                html.push(renderTable(headings, alignments, bodyRows));
                continue;
            }

            if (heading) {
                flushParagraph();
                html.push("<h" + heading[1].length + ">" + inlineMarkdown(heading[2]) + "</h" + heading[1].length + ">");
                index++;
                continue;
            }

            if (/^\s*[-+*]\s+/.test(line)) {
                flushParagraph();
                var listItems = [];
                while (index < lines.length && /^\s*[-+*]\s+/.test(lines[index])) {
                    listItems.push(lines[index].replace(/^\s*[-+*]\s+/,""));
                    index++;
                }
                html.push("<ul>" + listItems.map(function (item) {
                    return "<li>" + inlineMarkdown(item) + "</li>";
                }).join("") + "</ul>");
                continue;
            }

            if (line.trim() === "") {
                flushParagraph();
            } else {
                paragraph.push(line);
            }
            index++;
        }
        flushParagraph();
        return html.join("");
    }
    function escapeHtml(value) {
        return String(value || "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
    }
    function inlineMarkdown(value) {
        var escaped = escapeHtml(value);
        return escaped
            .replace(/`([^`]+)`/g,"<code>$1</code>")
            .replace(/\*\*([^*]+)\*\*/g,"<strong>$1</strong>")
            .replace(/__([^_]+)__/g,"<strong>$1</strong>")
            .replace(/\*([^*]+)\*/g,"<em>$1</em>")
            .replace(/_([^_]+)_/g,"<em>$1</em>");
    }
    function tableCells(line) {
        var protectedPipes = String(line || "").replace(/\\\|/g,"\u0000");
        protectedPipes = protectedPipes.replace(/^\s*\|/,"").replace(/\|\s*$/,"");
        return protectedPipes.split("|").map(function (cell) {
            return cell.replace(/\u0000/g,"|").trim();
        });
    }
    function isTableSeparator(line) {
        var cells = tableCells(line);
        return cells.length > 0 && cells.every(function (cell) {
            return /^:?-{3,}:?$/.test(cell.replace(/\s/g,""));
        });
    }
    function tableAlignment(separator) {
        var value = String(separator || "").replace(/\s/g,"");
        if (/^:-+:$/.test(value)) { return "center"; }
        if (/^-+:$/.test(value)) { return "right"; }
        return "left";
    }
    function renderTable(headings, alignments, rows) {
        var columnCount = headings.length;
        rows.forEach(function (row) { columnCount = Math.max(columnCount,row.length); });
        var output = '<div class="td-prose-table-wrap" role="region" aria-label="Scrollable information table" tabindex="0"><table class="td-prose-table"><thead><tr>';
        var column;
        for (column = 0; column < columnCount; column++) {
            output += '<th class="td-align-' + (alignments[column] || "left") + '">' + inlineMarkdown(headings[column] || "") + "</th>";
        }
        output += "</tr></thead><tbody>";
        rows.forEach(function (row) {
            output += "<tr>";
            for (column = 0; column < columnCount; column++) {
                output += '<td class="td-align-' + (alignments[column] || "left") + '">' + inlineMarkdown(row[column] || "") + "</td>";
            }
            output += "</tr>";
        });
        return output + "</tbody></table></div>";
    }
    function replaceItems(target,items) { target.splice.apply(target,[0,target.length].concat(Array.isArray(items) ? items : [])); }
    function share() { if (navigator.share) { navigator.share({title:viewState.destination.name,url:window.location.href}); } else if (navigator.clipboard) { navigator.clipboard.writeText(window.location.href); } }
    function back() { if (router && router.appNavigate) { router.appNavigate("/"); } else { window.location.hash = "#/app/travel-destinations"; } }
    function queryValue(name) { var match = new RegExp("[?&]" + name + "=([^&]+)").exec(window.location.hash); return match ? decodeURIComponent(match[1]) : ""; }
    function resultItems(response) { return response && response.success && response.result && Array.isArray(response.result.items) ? response.result.items : []; }
    function message(response, fallback) {
        if (!response) { return fallback; }
        if (typeof response.result === "string" && response.result) { return response.result; }
        if (response.result && response.result.message) { return response.result.message; }
        if (response.responseJSON) { return message(response.responseJSON, fallback); }
        if (response.responseText) {
            try { return message(JSON.parse(response.responseText), fallback); }
            catch (ignore) {}
        }
        return fallback;
    }
    function number(value) { return Number(value || 0).toFixed(1); }
    function scheduleMap() {
        if (!viewState.mapConfig.enabled || viewState.destination.latitude === undefined) { return; }
        mapRenderAttempts = 0;
        mapRenderStarted = false;
        viewState.mapError = "";
        if (typeof Vue !== "undefined" && typeof Vue.nextTick === "function") {
            Vue.nextTick(function () { queueMapRender(0); });
        } else {
            queueMapRender(0);
        }
    }
    function queueMapRender(delay) {
        if (mapRenderTimer) { clearTimeout(mapRenderTimer); }
        mapRenderTimer = setTimeout(renderMap,delay);
    }
    function mapContainer() {
        var container = null;
        if (rootElement && rootElement.find) {
            container = rootElement.find("[data-google-detail-map]")[0];
        } else if (rootElement && rootElement.querySelector) {
            container = rootElement.querySelector("[data-google-detail-map]");
        }
        return container || document.querySelector("[data-google-detail-map]");
    }
    function renderMap() {
        if (mapRenderStarted || mapHandle) { return; }
        var container = mapContainer();
        if (!container) {
            mapRenderAttempts++;
            if (mapRenderAttempts < 50) {
                queueMapRender(100);
            } else {
                viewState.mapError = "The map area could not be initialized. Refresh the page and try again.";
            }
            return;
        }
        maps = maps || resolveMaps(exports);
        if (!maps || typeof maps.createMap !== "function") {
            mapRenderAttempts++;
            if (mapRenderAttempts < 50) {
                queueMapRender(100);
            } else {
                viewState.mapError = "The Google Maps runtime is unavailable. Refresh the page and try again.";
            }
            return;
        }
        mapRenderStarted = true;
        maps.createMap(container,viewState.mapConfig,{center:viewState.destination,zoom:14,points:[viewState.destination]})
            .then(function(result){mapHandle=result;mapRenderStarted=false;})
            .catch(function(error){mapRenderStarted=false;viewState.mapError=error.message||"Google Maps could not be loaded.";});
    }
    function resolveMaps(componentExports) {
        var registered = componentExports.getComponent("google-map-runtime");
        return window.TravelDestinationGoogleMaps || (registered && registered.runtime) || registered;
    }
});
