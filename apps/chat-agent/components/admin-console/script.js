WEBDOCK.component().register(function(exports) {
    var api;
    var refreshTimer = null;
    var filterTimer = null;
    var apiRequested = false;
    var initialized = false;
    var renderElement = null;
    var vueInstance = null;

    var state = {
        sessions: [],
        selected: null,
        messages: [],
        filters: {
            search: "",
            status: "all"
        },
        replyText: "",
        loading: false,
        busy: false
    };

    state.status = {
        message: "Loading sessions",
        tone: "muted"
    };

    exports.vue = {
        data: state,
        computed: {
            statusClass: function() {
                return state.status.tone ? "is-" + state.status.tone : "";
            }
        },
        methods: {
            refreshSessions: refreshSessions,
            loadSessions: loadSessions,
            filterChanged: filterChanged,
            selectSession: selectSession,
            sendHumanReply: sendHumanReply,
            closeSelected: closeSelected,
            assignSelected: assignSelected,
            displaySender: displaySender,
            bubbleClass: bubbleClass,
            sessionClass: sessionClass,
            unreadCount: unreadCount
        },
        onReady: function(data, element) {
            if (element) {
                renderElement = element;
            }
            initialize();
        }
    };

    exports.chatAgentAdminOwnMount = true;
    exports.onReady = function(element) {
        renderElement = element || renderElement;
        if (mountVue(renderElement)) {
            return;
        }
        initialize();
    };

    function mountVue(element) {
        if (typeof Vue === "undefined") {
            return false;
        }

        var node = elementNode(element);
        if (!node) {
            return false;
        }

        if (node.__vue__) {
            vueInstance = node.__vue__;
            initialize();
            return true;
        }

        var target = $(node);
        if (!target.attr("id")) {
            target.attr("id", "chat_agent_admin_" + new Date().getTime());
        }

        exports.vue.el = "#" + target.attr("id");
        vueInstance = new Vue(exports.vue);
        if (exports.vue.onReady) {
            exports.vue.onReady(exports.vue.data, target);
        }
        return true;
    }

    function elementNode(element) {
        if (!element) {
            return null;
        }
        if (element.jquery) {
            return element.length ? element[0] : null;
        }
        return element.nodeType ? element : null;
    }

    function initialize() {
        if (initialized) {
            return;
        }

        api = exports.getComponent("api");
        if (!api && exports.getAppComponent && !apiRequested) {
            apiRequested = true;
            exports.getAppComponent(exports.getAppId(), "api", function(component) {
                apiRequested = false;
                api = component;
                initialize();
            });
        }
        if (!api) {
            setStatus("Loading chat service...", "muted");
            window.setTimeout(initialize, 300);
            return;
        }

        initialized = true;
        if (!vueInstance) {
            bindEvents();
        }
        readInputs();
        renderConversation();
        loadSessions(false);
        refreshTimer = window.setInterval(function() {
            if (!state.busy && !state.loading) {
                loadSessions(true);
                if (state.selected) {
                    selectSession(state.selected.sessionKey, true);
                }
            }
        }, 10000);
    }

    function bindEvents() {
        var root = componentRoot();
        root.off(".chatAgentAdmin");
        root.on("click.chatAgentAdmin", "[data-refresh-sessions]", refreshSessions);
        root.on("input.chatAgentAdmin change.chatAgentAdmin keyup.chatAgentAdmin", "[data-session-search], [data-session-status]", filterChanged);
        root.on("click.chatAgentAdmin", "[data-session-key]", function() {
            selectSession($(this).attr("data-session-key"));
        });
        root.on("submit.chatAgentAdmin", "[data-admin-reply-form]", sendHumanReply);
        root.on("click.chatAgentAdmin", "[data-close-session]", closeSelected);
        root.on("click.chatAgentAdmin", "[data-assign-session]", assignSelected);
        root.on("input.chatAgentAdmin change.chatAgentAdmin", "[data-admin-reply]", function() {
            readInputs();
        });
    }

    function refreshSessions(event) {
        preventEvent(event);
        loadSessions(false);
    }

    function filterChanged(event) {
        preventEvent(event);
        readInputs();
        window.clearTimeout(filterTimer);
        filterTimer = window.setTimeout(function() {
            loadSessions(false);
        }, 250);
    }

    function closeSelected(event) {
        preventEvent(event);
        updateSelected({ status: "closed", clearReview: true });
    }

    function assignSelected(event) {
        preventEvent(event);
        updateSelected({ assignToMe: true });
    }

    function loadSessions(silent) {
        if (silent && silent.preventDefault) {
            preventEvent(silent);
            silent = false;
        }
        readInputs();
        if (!api) {
            setStatus("Chat service is not loaded.", "error");
            return;
        }
        state.loading = true;
        renderFallbackControls();
        if (!silent) {
            setStatus("Loading sessions...", "muted");
        }
        callApi("ListSessions", {
            search: $.trim(state.filters.search || ""),
            status: state.filters.status || "all"
        })
            .then(function(response) {
                state.loading = false;
                renderFallbackControls();
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load sessions.", "error");
                    return;
                }
                state.sessions = result.sessions || [];
                renderSessions();
                if (state.selected) {
                    refreshSelectedFromList();
                }
                setStatus(state.sessions.length + " sessions", "success");
            })
            .error(function(response) {
                state.loading = false;
                renderFallbackControls();
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load sessions.", "error");
            });
    }

    function selectSession(sessionKey, silent) {
        var session = findSession(sessionKey);
        if (!session) {
            return;
        }
        state.selected = session;
        renderSessions();
        if (!silent) {
            setStatus("Loading messages...", "muted");
        }
        callApi("ListMessages", { sessionKey: sessionKey })
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load messages.", "error");
                    return;
                }
                state.selected = result.session || state.selected;
                state.messages = result.messages || [];
                renderConversation();
                callApi("MarkSessionRead", { sessionKey: sessionKey }).then(function() {
                    loadSessions(true);
                });
            })
            .error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load messages.", "error");
            });
    }

    function sendHumanReply(event) {
        preventEvent(event);
        readInputs();
        if (!state.selected || state.busy) {
            return;
        }
        var message = $.trim(state.replyText || "");
        if (!message) {
            setStatus("Reply message is required.", "error");
            return;
        }
        state.busy = true;
        renderFallbackControls();
        callApi("HumanReply", {
            sessionKey: state.selected.sessionKey,
            message: message
        })
            .then(function(response) {
                state.busy = false;
                renderFallbackControls();
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Reply was not sent.", "error");
                    return;
                }
                state.replyText = "";
                find("[data-admin-reply]").val("");
                state.selected = result.session || state.selected;
                state.messages = result.messages || [];
                renderConversation();
                loadSessions(true);
                setStatus("Reply sent.", "success");
            })
            .error(function(response) {
                state.busy = false;
                renderFallbackControls();
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Reply was not sent.", "error");
            });
    }

    function updateSelected(update) {
        if (!state.selected || state.busy) {
            return;
        }
        update.sessionKey = state.selected.sessionKey;
        state.busy = true;
        renderFallbackControls();
        callApi("UpdateSession", update)
            .then(function(response) {
                state.busy = false;
                renderFallbackControls();
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Session update failed.", "error");
                    return;
                }
                state.selected = result.session || state.selected;
                renderConversation();
                loadSessions(true);
                setStatus("Session updated.", "success");
            })
            .error(function(response) {
                state.busy = false;
                renderFallbackControls();
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Session update failed.", "error");
            });
    }

    function renderSessions() {
        if (vueInstance) {
            return;
        }
        var list = find("[data-session-list]");
        if (!state.sessions.length) {
            list.html('<div class="chat-agent__empty">No sessions found.</div>');
            return;
        }
        var html = [];
        for (var i = 0; i < state.sessions.length; i++) {
            var session = state.sessions[i];
            var selected = state.selected && state.selected.sessionKey === session.sessionKey;
            var highlighted = session.highlight || session.needsHumanReview === "true" || parseInt(session.humanUnreadCount || 0, 10) > 0;
            html.push(
                '<button type="button" class="chat-agent__session-row' +
                    (selected ? " is-selected" : "") +
                    (highlighted ? " is-highlighted" : "") +
                    '" data-session-key="' + escapeAttr(session.sessionKey || "") + '">' +
                    '<span class="chat-agent__session-name">' + escapeHtml(session.visitorName || "Visitor") + '</span>' +
                    '<span class="chat-agent__session-status">' + escapeHtml(session.status || "open") + '</span>' +
                    '<span class="chat-agent__session-preview">' + escapeHtml(session.lastMessagePreview || "") + '</span>' +
                    '<span class="chat-agent__session-meta">' + escapeHtml(session.lastMessageAt || "") + unreadBadge(session) + '</span>' +
                '</button>'
            );
        }
        list.html(html.join(""));
    }

    function renderConversation() {
        if (vueInstance) {
            scrollThread();
            return;
        }
        if (!state.selected) {
            find("[data-conversation-header]").html('<div><h2>Select a session</h2><p>Messages appear here.</p></div>');
            find("[data-admin-thread]").html('<div class="chat-agent__empty">No session selected.</div>');
            return;
        }
        find("[data-conversation-header]").html(
            '<div>' +
                '<h2>' + escapeHtml(state.selected.visitorName || "Visitor") + '</h2>' +
                '<p>' + escapeHtml(state.selected.sessionKey || "") + '</p>' +
            '</div>' +
            '<div class="chat-agent__conversation-meta">' +
                '<span>' + escapeHtml(state.selected.status || "open") + '</span>' +
                '<span>' + escapeHtml(state.selected.agentCode || "no-agent") + '</span>' +
            '</div>'
        );
        renderMessages();
    }

    function renderMessages() {
        var thread = find("[data-admin-thread]");
        if (!state.messages.length) {
            thread.html('<div class="chat-agent__empty">No messages yet.</div>');
            return;
        }
        var html = [];
        for (var i = 0; i < state.messages.length; i++) {
            var message = state.messages[i];
            html.push(
                '<article class="chat-agent__bubble chat-agent__bubble--' + bubbleClass(message) + '">' +
                    '<div class="chat-agent__bubble-meta">' +
                        '<span>' + escapeHtml(displaySender(message)) + '</span>' +
                        '<time>' + escapeHtml(message.createdAt || "") + '</time>' +
                    '</div>' +
                    '<div class="chat-agent__bubble-body">' + nl2br(escapeHtml(message.body || "")) + '</div>' +
                '</article>'
            );
        }
        thread.html(html.join(""));
        scrollThread();
    }

    function refreshSelectedFromList() {
        var updated = findSession(state.selected.sessionKey);
        if (updated) {
            state.selected = updated;
            renderConversation();
        }
    }

    function findSession(sessionKey) {
        for (var i = 0; i < state.sessions.length; i++) {
            if (state.sessions[i].sessionKey === sessionKey) {
                return state.sessions[i];
            }
        }
        return null;
    }

    function unreadBadge(session) {
        var count = unreadCount(session);
        return count > 0 ? ' <strong>' + count + '</strong>' : "";
    }

    function unreadCount(session) {
        return parseInt(session && session.humanUnreadCount ? session.humanUnreadCount : 0, 10);
    }

    function sessionClass(session) {
        var classes = [];
        if (state.selected && state.selected.sessionKey === session.sessionKey) {
            classes.push("is-selected");
        }
        if (isHighlighted(session)) {
            classes.push("is-highlighted");
        }
        return classes.join(" ");
    }

    function isHighlighted(session) {
        return !!(session && (session.highlight || session.needsHumanReview === "true" || unreadCount(session) > 0));
    }

    function displaySender(message) {
        if (message.senderType === "visitor") {
            return message.senderName || "Visitor";
        }
        if (message.senderType === "ai_agent") {
            return "AI Agent";
        }
        if (message.senderType === "human") {
            return message.senderName || "Human Agent";
        }
        return message.senderName || "System";
    }

    function bubbleClass(message) {
        return escapeClass(message && message.senderType ? message.senderType : "system");
    }

    function callApi(method, data) {
        if (api && api.services && typeof api.services[method] === "function") {
            return api.services[method](data || {});
        }
        return ajaxServiceCall(method, data || {});
    }

    function ajaxServiceCall(method, data) {
        var appId = api && api.getAppId ? api.getAppId() : "chat-agent";
        var componentId = api && api.getId ? api.getId() : "api";
        var request = $.ajax({
            url: "components/" + appId + "/" + componentId + "/service/" + method,
            type: "POST",
            xhrFields: { withCredentials: true },
            contentType: "application/json",
            data: JSON.stringify(data || {})
        });

        return {
            then: function(callback) {
                request.done(callback);
                return this;
            },
            error: function(callback) {
                request.fail(callback);
                return this;
            }
        };
    }

    function readInputs() {
        var root = componentRoot();
        var search = root.find("[data-session-search]");
        var status = root.find("[data-session-status]");
        var reply = root.find("[data-admin-reply]");
        if (search.length) {
            state.filters.search = search.val() || "";
        }
        if (status.length) {
            state.filters.status = status.val() || "all";
        }
        if (reply.length && !vueInstance) {
            state.replyText = reply.val() || "";
        }
    }

    function renderFallbackControls() {
        if (vueInstance) {
            return;
        }
        var root = componentRoot();
        root.find("[data-session-search]").val(state.filters.search || "").prop("disabled", !!state.busy);
        root.find("[data-session-status]").val(state.filters.status || "all").prop("disabled", !!state.busy);
        root.find("[data-refresh-sessions]").prop("disabled", !!state.busy || !!state.loading);
        root.find("[data-admin-reply]").val(state.replyText || "").prop("disabled", !!state.busy || !state.selected);
        root.find("[data-assign-session], [data-close-session], [data-admin-reply-form] button[type='submit']").prop("disabled", !!state.busy || !state.selected);
    }

    function scrollThread() {
        window.setTimeout(function() {
            var thread = find("[data-admin-thread]");
            if (thread.length) {
                thread.scrollTop(thread[0].scrollHeight);
            }
        }, 0);
    }

    function setStatus(message, tone) {
        state.status.message = message || "";
        state.status.tone = tone || "";
        var node = find("[data-admin-status]");
        node.removeClass("is-error is-muted is-success");
        if (tone) {
            node.addClass("is-" + tone);
        }
        node.text(message || "");
        renderFallbackControls();
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            var message = "DAVVAG service call failed.";
            if (response) {
                if (response.result && response.result.message) {
                    message = response.result.message;
                } else if (typeof response.result === "string") {
                    message = response.result;
                } else if (response.message) {
                    message = response.message;
                }
            }
            return { success: false, message: message };
        }
        return response.result || { success: false, message: "DAVVAG service returned an empty response." };
    }

    function find(selector) {
        return componentRoot().find(selector);
    }

    function componentRoot() {
        return $(renderElement || exports.element || []);
    }

    function preventEvent(event) {
        if (event && event.preventDefault) {
            event.preventDefault();
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function escapeAttr(value) {
        return escapeHtml(value);
    }

    function nl2br(value) {
        return value.replace(/\n/g, "<br>");
    }

    function escapeClass(value) {
        return String(value).replace(/[^a-z0-9_-]/gi, "-").toLowerCase();
    }
});
