WEBDOCK.component().register(function (exports) {
    var api, maps, router, rootElement, mapHandle;
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
            api = exports.getComponent("api"); maps = exports.getComponent("google-map-runtime"); router = exports.getShellComponent("soss-routes");
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
        var escaped = String(value || "").replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
        escaped = escaped.replace(/^### (.+)$/gm,"<h3>$1</h3>").replace(/^## (.+)$/gm,"<h2>$1</h2>").replace(/^# (.+)$/gm,"<h1>$1</h1>");
        escaped = escaped.replace(/\*\*([^*]+)\*\*/g,"<strong>$1</strong>").replace(/\*([^*]+)\*/g,"<em>$1</em>");
        return escaped.replace(/\n\n/g,"</p><p>").replace(/\n/g,"<br>");
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
    function scheduleMap() { if (viewState.mapConfig.enabled && viewState.destination.latitude !== undefined) { setTimeout(renderMap,0); } }
    function renderMap() {
        var container = rootElement && rootElement.find ? rootElement.find("[data-google-detail-map]")[0] : document.querySelector("[data-google-detail-map]");
        if (!container || !maps) { return; }
        maps.createMap(container,viewState.mapConfig,{center:viewState.destination,zoom:14,points:[viewState.destination]})
            .then(function(result){mapHandle=result;})
            .catch(function(error){viewState.mapError=error.message||"Google Maps could not be loaded.";});
    }
});
