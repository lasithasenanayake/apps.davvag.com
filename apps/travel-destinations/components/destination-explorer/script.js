WEBDOCK.component().register(function (exports) {
    var api, maps, router, rootElement, mapHandle;
    var state = {
        items: [], categories: [], amenities: [],
        filters: {keyword: "", categoryId: "", sort: "featured", page: 0, pageSize: 20},
        pagination: {}, loading: false, loadingMore: false, error: "",
        viewMode: "list", selectedId: null, resultTitle: "Places worth the journey",
        mapConfig: {enabled:false}, mapError: ""
    };
    var viewState = state;
    exports.vue = {
        data: state,
        methods: {search: search, loadMore: loadMore, setView: setView, navigate: navigate, openDestination: openDestination, locationLabel: locationLabel, number: number, pinStyle: pinStyle, selectMarker: selectMarker, markerLabel: markerLabel},
        computed: {
            mappableItems: function () {
                return viewState.items.filter(function (item) { return item.latitude !== undefined && item.longitude !== undefined; });
            },
            canLoadMore: function () {
                return hasMoreResults();
            }
        },
        onReady: function (scope, element) {
            viewState = scope || state;
            rootElement = element;
            api = exports.getComponent("api");
            maps = resolveMaps(exports);
            router = exports.getShellComponent("soss-routes");
            if (!api || !api.services) { viewState.error = "Destination services are unavailable."; return; }
            viewState.viewMode = window.location.hash.indexOf("/map") >= 0 ? "map" : "list";
            Promise.all([api.services.GetCategories({}),api.services.GetMapConfiguration({})]).then(function (responses) {
                replaceItems(viewState.categories, responses[0] && responses[0].success && Array.isArray(responses[0].result) ? responses[0].result : []);
                viewState.mapConfig = responses[1] && responses[1].success ? (responses[1].result || {enabled:false}) : {enabled:false};
                search();
            }).catch(function () { viewState.error = "Map and category settings could not be loaded."; search(); });
        }
    };
    function search() {
        if (viewState.loading) { return; }
        viewState.loading = true; viewState.error = ""; viewState.filters.page = 0;
        destinationRequest(requestFilters()).then(function (response) {
            viewState.loading = false;
            if (!response.success) { viewState.error = serviceMessage(response, "Search could not be completed."); return; }
            var result = response.result || {};
            replaceItems(viewState.items, result.items || []); viewState.pagination = result.pagination || {};
            viewState.resultTitle = viewState.filters.keyword ? "Results for “" + viewState.filters.keyword + "”" : "Places worth the journey";
            viewState.selectedId = viewState.items.length ? viewState.items[0].id : null;
            scheduleMap();
        }).error(function () { viewState.loading = false; viewState.error = "Search could not be completed."; });
    }
    function loadMore() {
        if (viewState.loadingMore || !hasMoreResults()) { return; }
        viewState.loadingMore = true; viewState.filters.page = Number(viewState.pagination.page || 0) + 1;
        destinationRequest(requestFilters()).then(function (response) {
            viewState.loadingMore = false;
            if (response.success) {
                var result = response.result || {};
                appendUniqueItems(viewState.items, result.items || []); viewState.pagination = result.pagination || {};
                scheduleMap();
            } else { viewState.error = serviceMessage(response, "More places could not be loaded."); }
        }).error(function () { viewState.loadingMore = false; viewState.error = "More places could not be loaded."; });
    }
    function replaceItems(target,items) { target.splice.apply(target,[0,target.length].concat(Array.isArray(items) ? items : [])); }
    function appendUniqueItems(target, items) {
        var known = {};
        target.forEach(function (item) { known[String(item.id)] = true; });
        (Array.isArray(items) ? items : []).forEach(function (item) {
            var key = String(item.id);
            if (!known[key]) { known[key] = true; target.push(item); }
        });
    }
    function hasMoreResults() {
        var pagination = viewState.pagination || {};
        var total = Number(pagination.total);
        return pagination.hasMore === true || pagination.hasMore === 1 || pagination.hasMore === "1" ||
            (isFinite(total) && total > viewState.items.length);
    }
    function destinationRequest(filters) {
        if (viewState.viewMode === "map" && typeof api.services.GetMapResults === "function") {
            return api.services.GetMapResults(filters);
        }
        return api.services.SearchDestinations(filters);
    }
    function requestFilters() { var copy = JSON.parse(JSON.stringify(viewState.filters)); if (!copy.categoryId) { delete copy.categoryId; } return copy; }
    function setView(mode) {
        if (mode === viewState.viewMode || viewState.loading || viewState.loadingMore) {
            if (mode === "map") { scheduleMap(); }
            return;
        }
        viewState.viewMode = mode;
        search();
    }
    function selectMarker(item) {
        viewState.selectedId = item.id;
        if (mapHandle && mapHandle.map && item.latitude !== undefined) { mapHandle.map.panTo({lat:Number(item.latitude),lng:Number(item.longitude)}); }
    }
    function markerLabel(item) { return String(item.name || "?").charAt(0).toUpperCase(); }
    function pinStyle(item) {
        var latitude = Math.max(5.7, Math.min(10, Number(item.latitude)));
        var longitude = Math.max(79.4, Math.min(82, Number(item.longitude)));
        return {top: ((10 - latitude) / 4.3 * 86 + 7) + "%", left: ((longitude - 79.4) / 2.6 * 86 + 7) + "%"};
    }
    function openDestination(item) { navigate("/place?id=" + encodeURIComponent(item.id)); }
    function navigate(path) { if (router && router.appNavigate) { router.appNavigate(path); } else { window.location.hash = "#/app/travel-destinations" + path; } }
    function locationLabel(item) { return item.nearest_town || item.district || item.province || "Sri Lanka"; }
    function number(value) { return Number(value || 0).toFixed(1); }
    function serviceMessage(response, fallback) { return response && response.result && (response.result.message || response.result.error) ? (response.result.message || response.result.error) : fallback; }
    function scheduleMap() {
        if (viewState.viewMode !== "map" || !viewState.mapConfig || !viewState.mapConfig.enabled) { return; }
        var render = function () {
            requestAnimationFrame(function () { requestAnimationFrame(renderGoogleMap); });
        };
        if (typeof Vue !== "undefined" && Vue.nextTick) { Vue.nextTick(render); }
        else { setTimeout(render,0); }
    }
    function renderGoogleMap() {
        var container = find("[data-google-explorer-map]");
        if (!container) { return; }
        maps = maps || resolveMaps(exports);
        if (!maps || typeof maps.createMap !== "function") { viewState.mapError = "The Google Maps runtime is unavailable. Refresh the page and try again."; return; }
        viewState.mapError = "";
        maps.createMap(container,viewState.mapConfig,{points:viewState.items,onMarkerClick:function(item){selectMarker(item);}})
            .then(function(result){mapHandle=result;})
            .catch(function(error){viewState.mapError=error.message||"Google Maps could not be loaded.";});
    }
    function find(selector) { if (rootElement && rootElement.find) { return rootElement.find(selector)[0]; } return document.querySelector(selector); }
    function resolveMaps(componentExports) {
        var registered = componentExports.getComponent("google-map-runtime");
        return window.TravelDestinationGoogleMaps || (registered && registered.runtime) || registered;
    }
});
