WEBDOCK.component().register(function (exports) {
    var api;
    var auth;
    var state = {
        loading: false,
        saving: false,
        connecting: false,
        workingId: "",
        error: "",
        info: "",
        configuration: null,
        channels: [],
        showClientSecret: false,
        showEncryptionKey: false,
        form: {
            googleClientId: "",
            googleClientSecret: "",
            oauthRedirectUri: "",
            encryptionKey: "",
            privacyPolicyUrl: "",
            termsUrl: ""
        }
    };

    exports.vue = {
        data: state,
        methods: {
            load: load,
            saveConfiguration: saveConfiguration,
            generateEncryptionKey: generateEncryptionKey,
            copyRedirectUri: copyRedirectUri,
            connectChannel: connectChannel,
            disconnect: disconnect,
            deleteData: deleteData,
            openChannels: function () { navigate("channels"); }
        },
        onReady: function () { initialize(); }
    };
    exports.onReady = function () {};

    function initialize() {
        api = exports.getComponent("api");
        auth = exports.getComponent("youtube-auth");
        if (!api || !auth) {
            state.error = "Required services are not loaded.";
            return;
        }
        window.addEventListener("message", oauthMessage);
        load();
    }

    function load() {
        state.loading = true;
        clearMessages();
        api.services.ListChannels({}).then(function (response) {
            state.loading = false;
            if (response.success) {
                state.channels = response.result.channels || [];
                applyConfiguration(response.result.configuration || null);
            } else {
                state.error = msg(response, "Settings could not be loaded.");
            }
        }).error(function () {
            state.loading = false;
            state.error = "Settings could not be loaded.";
        });
    }

    function applyConfiguration(configuration) {
        state.configuration = configuration;
        var values = configuration && configuration.values ? configuration.values : {};
        state.form.googleClientId = values.googleClientId || "";
        state.form.googleClientSecret = "";
        state.form.oauthRedirectUri = values.oauthRedirectUri || "";
        state.form.encryptionKey = "";
        state.form.privacyPolicyUrl = values.privacyPolicyUrl || "";
        state.form.termsUrl = values.termsUrl || "";
    }

    function saveConfiguration() {
        if (state.saving || !state.configuration || !state.configuration.canManageConfiguration) { return; }
        clearMessages();
        state.saving = true;
        api.services.SaveConfiguration({
            googleClientId: state.form.googleClientId,
            googleClientSecret: state.form.googleClientSecret,
            encryptionKey: state.form.encryptionKey,
            privacyPolicyUrl: state.form.privacyPolicyUrl,
            termsUrl: state.form.termsUrl
        }).then(function (response) {
            state.saving = false;
            if (response.success) {
                applyConfiguration(response.result.configuration);
                state.info = response.result.message || "Configuration saved.";
            } else {
                state.error = msg(response, "Configuration could not be saved.");
            }
        }).error(function () {
            state.saving = false;
            state.error = "Configuration could not be saved.";
        });
    }

    function generateEncryptionKey() {
        if (!window.crypto || !window.crypto.getRandomValues) {
            state.error = "This browser cannot generate a secure encryption key.";
            return;
        }
        var bytes = new Uint8Array(48);
        window.crypto.getRandomValues(bytes);
        var binary = "";
        for (var index = 0; index < bytes.length; index++) {
            binary += String.fromCharCode(bytes[index]);
        }
        state.form.encryptionKey = window.btoa(binary);
        state.showEncryptionKey = true;
        state.info = "A new encryption key was generated. Save the configuration to apply it.";
    }

    function copyRedirectUri() {
        var value = state.form.oauthRedirectUri || "";
        if (!value) { return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                state.info = "OAuth redirect URI copied.";
            }).catch(function () { legacyCopy(value); });
            return;
        }
        legacyCopy(value);
    }

    function legacyCopy(value) {
        var element = document.createElement("textarea");
        element.value = value;
        element.setAttribute("readonly", "readonly");
        element.style.position = "fixed";
        element.style.opacity = "0";
        document.body.appendChild(element);
        element.select();
        document.execCommand("copy");
        document.body.removeChild(element);
        state.info = "OAuth redirect URI copied.";
    }

    function connectChannel() {
        if (state.connecting) { return; }
        clearMessages();
        var popup = window.open("about:blank", "ytgGoogleOAuth", "width=720,height=780");
        if (!popup) {
            state.error = "OAuth popup was blocked.";
            return;
        }
        state.connecting = true;
        auth.services.StartConnect({}).then(function (response) {
            state.connecting = false;
            if (response.success) {
                popup.location.href = response.result.authUrl;
            } else {
                popup.close();
                state.error = msg(response, "OAuth could not be started.");
            }
        }).error(function () {
            state.connecting = false;
            popup.close();
            state.error = "OAuth could not be started.";
        });
    }

    function oauthMessage(event) {
        if (event && event.origin === window.location.origin && event.data && event.data.type === "ytg-oauth-complete") {
            if (event.data.success) {
                state.info = "Channel connected.";
                load();
            } else {
                state.error = "Channel connection failed.";
            }
        }
    }

    function disconnect(channel) {
        if (!channel || state.workingId || !window.confirm("Disconnect " + channel.title + "? Stored analytics will remain until separately deleted.")) { return; }
        state.workingId = channel.channelId;
        auth.services.DisconnectChannel({channelId: channel.channelId}).then(function (response) {
            state.workingId = "";
            if (response.success) {
                state.info = response.result.message;
                load();
            } else {
                state.error = msg(response, "Disconnect failed.");
            }
        }).error(function () {
            state.workingId = "";
            state.error = "Disconnect failed.";
        });
    }

    function deleteData(channel) {
        if (!channel || state.workingId) { return; }
        var confirmation = window.prompt("This removes locally stored YouTube data but does not affect YouTube. Type DELETE to continue.", "");
        if (confirmation !== "DELETE") { return; }
        state.workingId = channel.channelId;
        auth.services.DeleteChannelData({channelId: channel.channelId, confirmation: confirmation}).then(function (response) {
            state.workingId = "";
            if (response.success) {
                state.info = response.result.message;
                load();
            } else {
                state.error = msg(response, "Stored-data deletion failed.");
            }
        }).error(function () {
            state.workingId = "";
            state.error = "Stored-data deletion failed.";
        });
    }

    function clearMessages() {
        state.error = "";
        state.info = "";
    }

    function navigate(path) {
        var routes = exports.getShellComponent("soss-routes");
        if (routes && routes.appNavigate) {
            routes.appNavigate("../" + path);
        } else {
            window.location.hash = "#/app/youtube-growth-agent/" + path;
        }
    }

    function msg(response, fallback) {
        return response && response.result && response.result.message ? response.result.message : fallback;
    }
});
