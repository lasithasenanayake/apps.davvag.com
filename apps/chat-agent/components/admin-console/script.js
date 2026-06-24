WEBDOCK.component().register(function(exports) {
    var api;
    var refreshTimer = null;

    var state = {
        sessions: [],
        selected: null,
        messages: [],
        busy: false
    };

    exports.onReady = function() {
        api = exports.getComponent("api");
        bindEvents();
        loadSessions();
        refreshTimer = window.setInterval(function() {
            if (!state.busy) {
                loadSessions(true);
            }
        }, 10000);
    };

    function bindEvents() {
        find("[data-refresh-sessions]").on("click", function() {
            loadSessions(false);
        });
        find("[data-session-search], [data-session-status]").on("change keyup", function() {
            loadSessions(false);
        });
        find("[data-session-list]").on("click", "[data-session-key]", function() {
            selectSession($(this).attr("data-session-key"));
        });
        find("[data-admin-reply-form]").on("submit", sendHumanReply);
        find("[data-close-session]").on("click", function() {
            updateSelected({ status: "closed", clearReview: true });
        });
        find("[data-assign-session]").on("click", function() {
            updateSelected({ assignToMe: true });
        });
    }

    function loadSessions(silent) {
        if (!api) {
            setStatus("Chat service is not loaded.", "error");
            return;
        }
        if (!silent) {
            setStatus("Loading sessions...", "muted");
        }
        api.services.ListSessions({
            search: $.trim(find("[data-session-search]").val()),
            status: find("[data-session-status]").val()
        })
            .then(function(response) {
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
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load sessions.", "error");
            });
    }

    function selectSession(sessionKey) {
        var session = findSession(sessionKey);
        if (!session) {
            return;
        }
        state.selected = session;
        renderSessions();
        api.services.ListMessages({ sessionKey: sessionKey })
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load messages.", "error");
                    return;
                }
                state.selected = result.session || state.selected;
                state.messages = result.messages || [];
                renderConversation();
                api.services.MarkSessionRead({ sessionKey: sessionKey }).then(function() {
                    loadSessions(true);
                });
            })
            .error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load messages.", "error");
            });
    }

    function sendHumanReply(event) {
        event.preventDefault();
        if (!state.selected || state.busy) {
            return;
        }
        var message = $.trim(find("[data-admin-reply]").val());
        if (!message) {
            setStatus("Reply message is required.", "error");
            return;
        }
        state.busy = true;
        api.services.HumanReply({
            sessionKey: state.selected.sessionKey,
            message: message
        })
            .then(function(response) {
                state.busy = false;
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Reply was not sent.", "error");
                    return;
                }
                find("[data-admin-reply]").val("");
                state.selected = result.session || state.selected;
                state.messages = result.messages || [];
                renderConversation();
                loadSessions(true);
                setStatus("Reply sent.", "success");
            })
            .error(function(response) {
                state.busy = false;
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Reply was not sent.", "error");
            });
    }

    function updateSelected(update) {
        if (!state.selected) {
            return;
        }
        update.sessionKey = state.selected.sessionKey;
        api.services.UpdateSession(update)
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Session update failed.", "error");
                    return;
                }
                state.selected = result.session || state.selected;
                renderConversation();
                loadSessions(true);
            });
    }

    function renderSessions() {
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
        if (!state.selected) {
            find("[data-conversation-header]").html('<div><h2>Select a session</h2><p>Messages appear here.</p></div>');
            find("[data-admin-thread]").html("");
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
            var type = message.senderType || "system";
            html.push(
                '<article class="chat-agent__bubble chat-agent__bubble--' + escapeAttr(type) + '">' +
                    '<div class="chat-agent__bubble-meta">' +
                        '<span>' + escapeHtml(displaySender(message)) + '</span>' +
                        '<time>' + escapeHtml(message.createdAt || "") + '</time>' +
                    '</div>' +
                    '<div class="chat-agent__bubble-body">' + nl2br(escapeHtml(message.body || "")) + '</div>' +
                '</article>'
            );
        }
        thread.html(html.join(""));
        thread.scrollTop(thread[0].scrollHeight);
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
        var count = parseInt(session.humanUnreadCount || 0, 10);
        return count > 0 ? ' <strong>' + count + '</strong>' : "";
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

    function setStatus(message, tone) {
        var node = find("[data-admin-status]");
        node.removeClass("is-error is-muted is-success");
        if (tone) {
            node.addClass("is-" + tone);
        }
        node.text(message || "");
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            return {
                success: false,
                message: response && response.result && response.result.message ? response.result.message : "DAVVAG service call failed."
            };
        }
        return response.result || { success: false, message: "DAVVAG service returned an empty response." };
    }

    function find(selector) {
        return $(exports.element).find(selector);
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
        return String(value).replace(/[^a-z0-9@._:-]/gi, "-").toLowerCase();
    }

    function nl2br(value) {
        return value.replace(/\n/g, "<br>");
    }
});
