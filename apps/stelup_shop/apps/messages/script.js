WEBDOCK.component().register(function (exports) {
    var loader, service, requestId = 0, disposed = false;
    var bindData = {messages: [], search: "", active: null, loading: true, loadingChat: false, error: "", chatError: ""};

    function disposeChat() {
        var dock = document.getElementById("messagedock");
        if (dock && dock.__vue__ && !dock.__vue__._isDestroyed) {
            dock.__vue__.$destroy();
        }
        if (dock) { $(dock).empty(); }
    }

    function refresh() {
        bindData.loading = true;
        bindData.error = "";
        service.services.MessageInbox().then(function (result) {
            if (disposed) { return; }
            bindData.loading = false;
            if (result.success && Array.isArray(result.result)) {
                bindData.messages = result.result;
            } else {
                bindData.error = "Unable to load conversations. Please try again.";
            }
        }).error(function () {
            if (disposed) { return; }
            bindData.loading = false;
            bindData.error = "Unable to load conversations. Please try again.";
        });
    }

    function openConversation(conversation) {
        var token = ++requestId;
        disposeChat();
        bindData.active = conversation;
        bindData.loadingChat = true;
        bindData.chatError = "";
        Vue.nextTick(function () {
            if (disposed || token !== requestId) { return; }
            loader.downloadAPP("stelup_shop", "messaging", "messagedock", function () {
                if (token === requestId) { bindData.loadingChat = false; }
            }, function () {
                if (token === requestId) {
                    bindData.loadingChat = false;
                    bindData.chatError = "Unable to open this conversation. Please try again.";
                }
            }, function () {}, {pid: conversation.m_from, name: conversation.name, stretch: true}, {
                isCurrent: function () { return !disposed && token === requestId; }
            });
        });
    }

    exports.vue = {
        data: bindData,
        onReady: function () {
            service = exports.getComponent("p_svr");
            exports.getAppComponent("davvag-tools", "davvag-app-downloader", function (component) {
                if (disposed) { return; }
                loader = component;
                loader.initialize();
                refresh();
            });
        },
        beforeDestroy: function () {
            disposed = true;
            requestId++;
            disposeChat();
        },
        computed: {
            filteredMessages: function () {
                var query = this.search.trim().toLowerCase();
                return this.messages.filter(function (message) {
                    return !query || String(message.name || "").toLowerCase().indexOf(query) !== -1;
                });
            }
        },
        methods: {
            refresh: refresh,
            openConversation: openConversation,
            retryConversation: function () { if (bindData.active) { openConversation(bindData.active); } },
            back: function () {
                requestId++;
                disposeChat();
                bindData.active = null;
                bindData.loadingChat = false;
                bindData.chatError = "";
            }
        }
    };
});
