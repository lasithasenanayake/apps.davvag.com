WEBDOCK.component().register(function(exports) {
    var api;
    var pollTimer = null;

    var state = {
        session: null,
        messages: [],
        busy: false
    };

    exports.onReady = function() {
        api = exports.getComponent("api");
        restoreInputs();
        bindEvents();
        loadSession(false);
        pollTimer = window.setInterval(function() {
            if (!state.busy) {
                pollSession();
            }
        }, 8000);
    };

    function bindEvents() {
        find("[data-chat-form]").on("submit", sendMessage);
        find("[data-new-session]").on("click", function() {
            loadSession(true);
        });
        find("[data-visitor-name], [data-visitor-email], [data-agent-code]").on("change keyup", saveInputs);
    }

    function loadSession(newSession) {
        if (!api) {
            setStatus("Chat service is not loaded.", "error");
            return;
        }
        setBusy(true);
        api.services.Bootstrap(payload({ newSession: !!newSession }))
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to start chat.", "error");
                    return;
                }
                state.session = result.session || null;
                state.messages = result.messages || [];
                render();
                setStatus("", "");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to start chat.", "error");
            });
    }

    function pollSession() {
        if (!api || !state.session) {
            return;
        }
        api.services.PollSession(payload({}))
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    return;
                }
                state.session = result.session || state.session;
                state.messages = result.messages || state.messages;
                render();
            });
    }

    function sendMessage(event) {
        event.preventDefault();
        if (!api || state.busy) {
            return;
        }

        var message = $.trim(find("[data-chat-message]").val());
        if (!message) {
            setStatus("Type a message before sending.", "error");
            return;
        }

        setBusy(true);
        setStatus("Sending...", "muted");
        api.services.SendMessage(payload({ message: message }))
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Message was not sent.", "error");
                    return;
                }
                find("[data-chat-message]").val("");
                state.session = result.session || state.session;
                state.messages = result.messages || [];
                render();
                if (result.agent && result.agent.success === false) {
                    setStatus(result.agent.message || "A human agent will review this chat.", "muted");
                } else {
                    setStatus("", "");
                }
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Message was not sent.", "error");
            });
    }

    function payload(extra) {
        var data = {
            visitorName: $.trim(find("[data-visitor-name]").val()),
            visitorEmail: $.trim(find("[data-visitor-email]").val()),
            agentCode: $.trim(find("[data-agent-code]").val()) || "chat-agent"
        };
        return $.extend(data, extra || {});
    }

    function render() {
        var label = state.session && state.session.sessionKey ? state.session.sessionKey : "";
        find("[data-chat-session-label]").text(label ? "Session " + label : "Starting session");
        renderMessages();
    }

    function renderMessages() {
        var thread = find("[data-chat-thread]");
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

    function displaySender(message) {
        if (message.senderType === "visitor") {
            return message.senderName || "You";
        }
        if (message.senderType === "ai_agent") {
            return "AI Agent";
        }
        if (message.senderType === "human") {
            return message.senderName || "Human Agent";
        }
        return message.senderName || "System";
    }

    function setBusy(isBusy) {
        state.busy = isBusy;
        find("button, input, textarea").prop("disabled", isBusy);
    }

    function setStatus(message, tone) {
        var node = find("[data-chat-status]");
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

    function restoreInputs() {
        find("[data-visitor-name]").val(window.localStorage.getItem("chatAgentVisitorName") || "");
        find("[data-visitor-email]").val(window.localStorage.getItem("chatAgentVisitorEmail") || "");
        find("[data-agent-code]").val(window.localStorage.getItem("chatAgentAgentCode") || "chat-agent");
    }

    function saveInputs() {
        window.localStorage.setItem("chatAgentVisitorName", find("[data-visitor-name]").val());
        window.localStorage.setItem("chatAgentVisitorEmail", find("[data-visitor-email]").val());
        window.localStorage.setItem("chatAgentAgentCode", find("[data-agent-code]").val());
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
        return String(value).replace(/[^a-z0-9_-]/gi, "-").toLowerCase();
    }

    function nl2br(value) {
        return value.replace(/\n/g, "<br>");
    }
});
