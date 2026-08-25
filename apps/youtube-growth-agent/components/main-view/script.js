WEBDOCK.component().register(function (exports) {
    var api;
    var auth;
    var scope;
    var state = {
        loading: false,
        connecting: false,
        channels: [],
        configuration: null,
        error: "",
        info: ""
    };

    exports.vue = {
        data: state,
        methods: {
            refresh: load,
            connectChannel: connectChannel,
            openChannel: openChannel,
            openSettings: function () { navigate("settings"); },
            formatNumber: formatNumber,
            formatDate: formatDate,
            healthClass: healthClass
        },
        onReady: function (s) {
            scope = s;
            initialize();
        }
    };

    exports.onReady = function () {
    };

    function initialize() {
        api = exports.getComponent("api");
        auth = exports.getComponent("youtube-auth");
        if (!api || !auth) {
            state.error = "YouTube Growth Agent services are not loaded.";
            return;
        }
        window.addEventListener("message", oauthMessage);
        load();
    }

    function load() {
        if (state.loading) { return; }
        state.loading = true;
        state.error = "";
        api.services.ListChannels({}).then(function (response) {
            state.loading = false;
            if (response.success) {
                state.channels = response.result.channels || [];
                state.configuration = response.result.configuration || null;
            } else {
                state.error = responseMessage(response, "Channels could not be loaded.");
            }
        }).error(function () {
            state.loading = false;
            state.error = "Channels could not be loaded.";
        });
    }

    function connectChannel() {
        if (state.connecting || !auth) { return; }
        var popup = window.open("about:blank", "ytgGoogleOAuth", "width=720,height=780");
        if (!popup) {
            state.error = "OAuth popup was blocked. Allow popups for this site and try again.";
            return;
        }
        state.connecting = true;
        state.error = "";
        auth.services.StartConnect({}).then(function (response) {
            state.connecting = false;
            if (response.success && response.result.authUrl) {
                popup.location.href = response.result.authUrl;
                popup.focus();
                state.info = "Complete read-only YouTube authorization in the new window.";
            } else {
                popup.close();
                state.error = responseMessage(response, "YouTube authorization could not be started.");
            }
        }).error(function () {
            state.connecting = false;
            popup.close();
            state.error = "YouTube authorization could not be started.";
        });
    }

    function oauthMessage(event) {
        if (!event || event.origin !== window.location.origin || !event.data || event.data.type !== "ytg-oauth-complete") {
            return;
        }
        if (event.data.success) {
            state.info = "Channel connected. Select it and run the initial sync.";
            load();
        } else {
            state.error = "YouTube authorization did not complete.";
        }
    }

    function openChannel(channel) {
        if (!channel || !channel.channelId) { return; }
        window.localStorage.setItem("ytg.selectedChannelId", channel.channelId);
        navigate("dashboard?channelId=" + encodeURIComponent(channel.channelId));
    }

    function navigate(path) {
        var routes = exports.getShellComponent("soss-routes");
        if (routes && routes.appNavigate) {
            routes.appNavigate("../" + path);
        } else {
            window.location.hash = "#/app/youtube-growth-agent/" + path;
        }
    }

    function formatNumber(value) {
        return Number(value || 0).toLocaleString();
    }

    function formatDate(value) {
        if (!value) { return "Not yet"; }
        var date = new Date(String(value).replace(" ", "T"));
        return isNaN(date.getTime()) ? value : date.toLocaleString();
    }

    function healthClass(value) {
        value = String(value || "").toLowerCase();
        if (value.indexOf("connected") >= 0 && value.indexOf("disconnected") < 0) { return "good"; }
        if (value.indexOf("error") >= 0 || value.indexOf("required") >= 0) { return "red"; }
        return "warn";
    }

    function responseMessage(response, fallback) {
        if (response && response.result) {
            if (typeof response.result === "string") { return response.result; }
            if (response.result.message) { return response.result.message; }
        }
        return fallback;
    }
});
