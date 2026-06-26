WEBDOCK.component().register(function(exports) {
    var api;
    var pollTimer = null;
    var initialized = false;
    var renderElement = null;
    var vueInstance = null;
    var fallbackMode = false;
    var localMessageCounter = 0;
    var knownMessageKeys = {};
    var messageHistoryReady = false;
    var audioContext = null;
    var notificationSoundReady = false;

    var bindData = {
        session: null,
        messages: [],
        profile: null,
        profileReady: false,
        authenticated: false,
        profileForm: {
            name: "",
            email: "",
            phone: "",
            details: ""
        },
        messageText: "",
        defaultAgentCode: "chat-agent",
        busy: false,
        status: {
            message: "",
            tone: ""
        }
    };

    var vueData = {
        data: bindData,
        computed: {
            sessionLabel: function() {
                return bindData.session && bindData.session.sessionKey
                    ? "Session " + bindData.session.sessionKey
                    : "Starting session";
            },
            statusClass: function() {
                return bindData.status.tone ? "is-" + bindData.status.tone : "";
            }
        },
        methods: {
            saveProfile: saveProfile,
            sendMessage: sendMessage,
            newChat: newChat,
            displaySender: displaySender,
            bubbleClass: bubbleClass,
            bubbleClasses: bubbleClasses,
            formatMessage: formatMessage,
            profileValue: profileValue,
            profileImageUrl: profileImageUrl,
            profileInitial: profileInitial,
            saveInputs: saveInputs
        },
        onReady: function(data, element) {
            if (element) {
                renderElement = element;
            }
            bindSoundUnlock();
            initialize();
        }
    };

    exports.vue = vueData;
    exports.chatAgentOwnMount = true;
    exports.onReady = function(element) {
        renderElement = element || renderElement;
        if (mountVue(renderElement)) {
            return;
        }
        fallbackMode = true;
        bindFallbackEvents();
        bindSoundUnlock();
        renderFallback();
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
            fallbackMode = false;
            bindSoundUnlock();
            initialize();
            return true;
        }

        var target = $(node);
        if (!target.attr("id")) {
            target.attr("id", "chat_agent_window_" + new Date().getTime());
        }

        exports.vue.el = "#" + target.attr("id");
        vueInstance = new Vue(exports.vue);
        fallbackMode = false;
        bindSoundUnlock();
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
        if (!api) {
            setStatus("Loading chat service...", "muted");
            window.setTimeout(initialize, 300);
            return;
        }

        initialized = true;
        restoreInputs();
        bindProfileAvatarFallback();
        renderFallback();
        loadSession(false);
        pollTimer = window.setInterval(function() {
            if (!bindData.busy && bindData.profileReady) {
                pollSession();
            }
        }, 8000);
    }

    function loadSession(newSession) {
        if (!api) {
            setStatus("Chat service is not loaded.", "error");
            return;
        }

        setBusy(true);
        callApi("Bootstrap", payload({ newSession: !!newSession }))
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to start chat.", "error");
                    return;
                }
                applyServiceState(result);
                setStatus(bindData.profileReady ? "" : profileRequiredMessage(), "muted");
            })
            .error(function(response) {
                setBusy(false);
                setStatus(errorMessage(response, "Unable to start chat."), "error");
            });
    }

    function newChat(event) {
        preventEvent(event);
        if (!bindData.profileReady) {
            setStatus(profileRequiredMessage(), "error");
            return;
        }
        messageHistoryReady = false;
        knownMessageKeys = {};
        loadSession(true);
    }

    function saveProfile(event) {
        preventEvent(event);
        readFallbackInputs();
        if (!api || bindData.busy) {
            return;
        }
        if (bindData.authenticated) {
            setStatus("Signed-in users chat with their registered profile.", "muted");
            return;
        }

        var validation = validateProfile();
        if (validation !== "") {
            setStatus(validation, "error");
            return;
        }

        saveInputs();
        setBusy(true);
        setStatus("Saving profile...", "muted");
        callApi("ResolveProfile", payload({}))
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Profile was not saved.", "error");
                    return;
                }
                applyServiceState(result);
                bindData.profileReady = !!(bindData.profile && bindData.profile.id);
                saveInputs();
                setStatus("Profile ready. You can send your message now.", "success");
            })
            .error(function(response) {
                setBusy(false);
                setStatus(errorMessage(response, "Profile was not saved."), "error");
            });
    }

    function pollSession() {
        if (!api || !bindData.session) {
            return;
        }

        callApi("PollSession", payload({}))
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    return;
                }
                applyServiceState(result);
            });
    }

    function sendMessage(event) {
        preventEvent(event);
        readFallbackInputs();
        if (!api || bindData.busy) {
            return;
        }
        if (!bindData.profileReady) {
            setStatus(profileRequiredMessage(), "error");
            return;
        }

        var message = $.trim(bindData.messageText || "");
        if (!message) {
            setStatus("Type a message before sending.", "error");
            return;
        }

        unlockNotificationSound();
        saveInputs();
        var requestData = payload({ message: message });
        bindData.messageText = "";
        appendLocalMessage(localVisitorMessage(message));
        appendLocalMessage(localAgentWaitingMessage());
        setBusy(true);
        setStatus("Waiting for AI agent...", "muted");
        scrollThread();
        callApi("SendMessage", requestData)
            .then(function(response) {
                removeLocalWaitingMessages();
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Message was not sent.", "error");
                    return;
                }
                applyServiceState(result);
                if (result.agent && result.agent.success === false) {
                    setStatus(result.agent.message || "A human agent will review this chat.", "muted");
                } else {
                    setStatus("", "");
                }
            })
            .error(function(response) {
                removeLocalWaitingMessages();
                setBusy(false);
                setStatus(errorMessage(response, "Message was not sent."), "error");
            });
    }

    function applyServiceState(result) {
        var shouldFocusLatest = false;
        if (result.defaultAgentCode) {
            bindData.defaultAgentCode = result.defaultAgentCode;
        }
        if (result.identity) {
            bindData.authenticated = result.identity.type === "authenticated";
            if (bindData.authenticated) {
                bindData.profile = {
                    id: result.identity.profileId || "",
                    name: result.identity.name || "",
                    email: result.identity.email || "",
                    contactno: result.identity.phone || ""
                };
                bindData.profileReady = !!result.identity.profileId;
            } else if (!bindData.profile && result.identity.profileId) {
                bindData.profile = {
                    id: result.identity.profileId,
                    name: result.identity.name || "",
                    email: result.identity.email || "",
                    contactno: result.identity.phone || ""
                };
                bindData.profileReady = true;
            }
        }
        if (result.profile) {
            bindData.profile = result.profile;
            bindData.profileReady = !!result.profile.id;
            writeProfileToForm(result.profile);
        }
        if (result.session) {
            bindData.session = result.session;
            if (!bindData.profile && result.session.visitorId) {
                bindData.profile = {
                    id: result.session.visitorId,
                    name: result.session.visitorName || "",
                    email: result.session.visitorEmail || "",
                    contactno: result.session.visitorPhone || "",
                    details: result.session.visitorDetails || ""
                };
            }
            if (result.session.visitorId) {
                bindData.profileReady = true;
            }
            writeSessionToForm(result.session);
        }
        if (result.messages) {
            shouldFocusLatest = notifyForNewMessages(result.messages);
            bindData.messages = result.messages;
        }
        renderFallback();
        if (result.messages || shouldFocusLatest) {
            scrollThread();
        }
    }

    function payload(extra) {
        readFallbackInputs();
        var data = {
            profileId: bindData.authenticated ? (bindData.profile && bindData.profile.id ? bindData.profile.id : "") : (bindData.profile && bindData.profile.id ? bindData.profile.id : storageGet("chatAgentProfileId", "")),
            visitorName: $.trim(bindData.profileForm.name || ""),
            visitorEmail: $.trim(bindData.profileForm.email || ""),
            visitorPhone: $.trim(bindData.profileForm.phone || ""),
            visitorDetails: $.trim(bindData.profileForm.details || ""),
            agentCode: bindData.defaultAgentCode || "chat-agent"
        };
        return $.extend(data, extra || {});
    }

    function callApi(method, data) {
        if (api && api.services && typeof api.services[method] === "function") {
            return api.services[method](data);
        }
        return ajaxServiceCall(method, data);
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

    function validateProfile() {
        if ($.trim(bindData.profileForm.name || "") === "") {
            return "Name is required.";
        }
        if ($.trim(bindData.profileForm.email || "") === "") {
            return "Email is required.";
        }
        if ($.trim(bindData.profileForm.phone || "") === "") {
            return "Phone is required.";
        }
        return "";
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

    function bubbleClass(message) {
        return escapeClass(message && message.senderType ? message.senderType : "system");
    }

    function bubbleClasses(message) {
        var classes = ["chat-agent__bubble--" + bubbleClass(message)];
        if (message && message.pending) {
            classes.push("is-pending");
        }
        return classes.join(" ");
    }

    function formatMessage(message) {
        var body = message && message.body ? String(message.body) : "";
        if (body === "") {
            return "";
        }

        var lines = escapeHtml(body).split(/\r?\n/);
        var html = [];
        $.each(lines, function(index, line) {
            var trimmed = $.trim(line);
            var heading = line.match(/^(#{1,3})\s+(.+)$/);

            if (trimmed === "") {
                html.push('<div class="chat-agent__message-space"></div>');
                return;
            }
            if (/^-{3,}$/.test(trimmed)) {
                html.push('<hr class="chat-agent__message-rule">');
                return;
            }
            if (heading) {
                html.push('<h3 class="chat-agent__message-heading">' + formatInline(heading[2]) + '</h3>');
                return;
            }

            html.push('<p>' + formatInline(line) + '</p>');
        });
        return html.join("");
    }

    function formatInline(value) {
        return value.replace(/\*\*([^*]+)\*\*/g, "<strong>$1</strong>");
    }

    function appendLocalMessage(message) {
        bindData.messages = (bindData.messages || []).concat([message]);
        renderFallback();
        scrollThread();
    }

    function removeLocalWaitingMessages() {
        bindData.messages = $.grep(bindData.messages || [], function(message) {
            return !(message && message.localOnly && message.pending);
        });
        renderFallback();
    }

    function localVisitorMessage(body) {
        localMessageCounter++;
        return {
            localId: "visitor-local-" + localMessageCounter,
            messageId: "visitor-local-" + localMessageCounter,
            senderType: "visitor",
            senderName: profileValue("name") || "You",
            body: body,
            direction: "inbound",
            status: "sending",
            agentCode: bindData.defaultAgentCode || "",
            createdAt: "Sending"
        };
    }

    function localAgentWaitingMessage() {
        localMessageCounter++;
        return {
            localId: "agent-waiting-" + localMessageCounter,
            messageId: "agent-waiting-" + localMessageCounter,
            senderType: "ai_agent",
            senderName: "AI Agent",
            body: "AI Agent is responding...",
            direction: "outbound",
            status: "pending",
            agentCode: bindData.defaultAgentCode || "",
            createdAt: "",
            pending: true,
            localOnly: true
        };
    }

    function profileValue(key) {
        if (bindData.profile && bindData.profile[key]) {
            return bindData.profile[key];
        }
        if (bindData.session) {
            if (key === "id") {
                return bindData.session.visitorId || "";
            }
            if (key === "name") {
                return bindData.session.visitorName || "";
            }
            if (key === "email") {
                return bindData.session.visitorEmail || "";
            }
            if (key === "contactno" || key === "phone") {
                return bindData.session.visitorPhone || "";
            }
        }
        return "";
    }

    function profileImageUrl() {
        var profileId = profileValue("id");
        return profileId ? "components/dock/soss-uploader/service/get/profile/" + profileId : "";
    }

    function profileInitial() {
        var name = $.trim(profileValue("name") || "Visitor");
        return name ? name.charAt(0).toUpperCase() : "V";
    }

    function profileRequiredMessage() {
        return bindData.authenticated
            ? "Your signed-in user does not have a registered profile."
            : "Register your profile to start chatting.";
    }

    function setBusy(isBusy) {
        bindData.busy = isBusy;
        renderFallback();
    }

    function setStatus(message, tone) {
        bindData.status.message = message || "";
        bindData.status.tone = tone || "";
        renderFallback();
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
            return {
                success: false,
                message: message
            };
        }
        return response.result || { success: false, message: "DAVVAG service returned an empty response." };
    }

    function errorMessage(response, fallback) {
        var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
        return result.message || fallback;
    }

    function restoreInputs() {
        bindData.profileForm.name = storageGet("chatAgentVisitorName", "");
        bindData.profileForm.email = storageGet("chatAgentVisitorEmail", "");
        bindData.profileForm.phone = storageGet("chatAgentVisitorPhone", "");
        bindData.profileForm.details = storageGet("chatAgentVisitorDetails", "");

        var profileId = storageGet("chatAgentProfileId", "");
        if (profileId !== "") {
            bindData.profile = {
                id: profileId,
                name: bindData.profileForm.name,
                email: bindData.profileForm.email,
                contactno: bindData.profileForm.phone,
                details: bindData.profileForm.details
            };
            bindData.profileReady = true;
        }
    }

    function saveInputs() {
        readFallbackInputs();
        if (bindData.authenticated) {
            return;
        }
        storageSet("chatAgentVisitorName", bindData.profileForm.name || "");
        storageSet("chatAgentVisitorEmail", bindData.profileForm.email || "");
        storageSet("chatAgentVisitorPhone", bindData.profileForm.phone || "");
        storageSet("chatAgentVisitorDetails", bindData.profileForm.details || "");
        if (bindData.profile && bindData.profile.id) {
            storageSet("chatAgentProfileId", bindData.profile.id);
        }
    }

    function writeProfileToForm(profile) {
        if (!profile) {
            return;
        }
        if (profile.name) {
            bindData.profileForm.name = profile.name;
        }
        if (profile.email) {
            bindData.profileForm.email = profile.email;
        }
        if (profile.contactno) {
            bindData.profileForm.phone = profile.contactno;
        }
        if (profile.details) {
            bindData.profileForm.details = profile.details;
        }
        saveInputs();
    }

    function writeSessionToForm(session) {
        if (!session || bindData.profileReady) {
            return;
        }
        if (!bindData.profileForm.name && session.visitorName) {
            bindData.profileForm.name = session.visitorName;
        }
        if (!bindData.profileForm.email && session.visitorEmail) {
            bindData.profileForm.email = session.visitorEmail;
        }
        if (!bindData.profileForm.phone && session.visitorPhone) {
            bindData.profileForm.phone = session.visitorPhone;
        }
        if (!bindData.profileForm.details && session.visitorDetails) {
            bindData.profileForm.details = session.visitorDetails;
        }
    }

    function scrollThread() {
        if (vueInstance && typeof vueInstance.$nextTick === "function") {
            vueInstance.$nextTick(focusLatestMessage);
        }
        window.setTimeout(focusLatestMessage, 0);
        window.setTimeout(focusLatestMessage, 80);
        window.setTimeout(focusLatestMessage, 240);
    }

    function focusLatestMessage() {
        var thread = componentRoot().find("[data-chat-thread]");
        if (!thread.length) {
            return;
        }

        var latest = thread.find("[data-chat-message-item]").last();
        if (!latest.length) {
            thread.scrollTop(thread[0].scrollHeight);
            return;
        }

        thread.scrollTop(thread[0].scrollHeight);
        latest.attr("tabindex", "-1");
        try {
            latest[0].focus({ preventScroll: true });
        } catch (ignore) {
            latest[0].focus();
        }
        if (latest[0].scrollIntoView) {
            latest[0].scrollIntoView({ block: "end", inline: "nearest", behavior: "smooth" });
        }
        thread.scrollTop(thread[0].scrollHeight);
    }

    function notifyForNewMessages(messages) {
        var shouldPlay = false;
        var hasNewMessages = false;
        var wasReady = messageHistoryReady;
        $.each(messages || [], function(index, message) {
            var key = messageKey(message);
            if (!key) {
                return;
            }
            if (!knownMessageKeys[key]) {
                knownMessageKeys[key] = true;
                hasNewMessages = true;
                if (messageHistoryReady && isIncomingMessage(message)) {
                    shouldPlay = true;
                }
            }
        });
        messageHistoryReady = true;
        if (shouldPlay) {
            playNotificationSound();
        }
        return hasNewMessages || !wasReady;
    }

    function messageKey(message) {
        if (!message) {
            return "";
        }
        return String(message.messageId || message.id || message.localId || [
            message.senderType || "",
            message.createdAt || "",
            message.body || ""
        ].join("|"));
    }

    function isIncomingMessage(message) {
        return !!message && !message.localOnly && (message.senderType === "ai_agent" || message.senderType === "human");
    }

    function bindSoundUnlock() {
        var root = componentRoot();
        if (!root.length) {
            return;
        }
        root.off(".chatAgentSound");
        root.one("pointerdown.chatAgentSound keydown.chatAgentSound", unlockNotificationSound);
    }

    function unlockNotificationSound() {
        if (notificationSoundReady) {
            return;
        }
        var AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) {
            return;
        }
        try {
            audioContext = audioContext || new AudioContextClass();
            if (audioContext.state === "suspended" && audioContext.resume) {
                audioContext.resume();
            }
            notificationSoundReady = true;
        } catch (ignore) {
        }
    }

    function playNotificationSound() {
        unlockNotificationSound();
        if (!audioContext) {
            return;
        }
        try {
            var start = audioContext.currentTime;
            var gain = audioContext.createGain();
            var first = audioContext.createOscillator();
            var second = audioContext.createOscillator();
            gain.connect(audioContext.destination);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(0.08, start + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.24);
            first.type = "sine";
            second.type = "sine";
            first.frequency.setValueAtTime(740, start);
            second.frequency.setValueAtTime(980, start + 0.08);
            first.connect(gain);
            second.connect(gain);
            first.start(start);
            first.stop(start + 0.12);
            second.start(start + 0.08);
            second.stop(start + 0.24);
        } catch (ignore) {
        }
    }

    function bindFallbackEvents() {
        var root = componentRoot();
        if (!root.length) {
            return;
        }
        root.off(".chatAgentVisitor");
        root.on("submit.chatAgentVisitor", "[data-profile-form]", saveProfile);
        root.on("submit.chatAgentVisitor", "[data-chat-form]", sendMessage);
        root.on("click.chatAgentVisitor", "[data-new-session]", newChat);
        root.on("input.chatAgentVisitor change.chatAgentVisitor", "[data-visitor-name],[data-visitor-email],[data-visitor-phone],[data-visitor-details],[data-chat-message]", function() {
            readFallbackInputs();
            saveInputs();
        });
    }

    function bindProfileAvatarFallback() {
        var root = componentRoot();
        if (!root.length) {
            return;
        }
        root.off("error.chatAgentAvatar", "[data-profile-avatar]");
        root.on("error.chatAgentAvatar", "[data-profile-avatar]", function() {
            $(this).hide();
        });
    }

    function readFallbackInputs() {
        if (!fallbackMode) {
            return;
        }
        var root = componentRoot();
        if (!root.length) {
            return;
        }
        bindData.profileForm.name = root.find("[data-visitor-name]").val() || "";
        bindData.profileForm.email = root.find("[data-visitor-email]").val() || "";
        bindData.profileForm.phone = root.find("[data-visitor-phone]").val() || "";
        bindData.profileForm.details = root.find("[data-visitor-details]").val() || "";
        bindData.messageText = root.find("[data-chat-message]").val() || "";
    }

    function renderFallback() {
        if (!fallbackMode || vueInstance) {
            return;
        }

        var root = componentRoot();
        if (!root.length) {
            return;
        }

        root.find("[data-chat-session-label]").text(sessionLabelText());
        root.find("[data-new-session]").prop("disabled", !!bindData.busy || !bindData.profileReady);

        var status = root.find("[data-chat-status]");
        status.removeClass("is-error is-success is-muted");
        if (bindData.status.tone) {
            status.addClass("is-" + bindData.status.tone);
        }
        status.text(bindData.status.message || "");

        renderFallbackMessages(root);
        root.find("[data-profile-form]").toggle(!bindData.profileReady && !bindData.authenticated);
        root.find("[data-profile-summary]").toggle(!!bindData.profileReady);
        root.find("[data-chat-form]").toggle(!!bindData.profileReady);

        root.find("[data-visitor-name]").val(bindData.profileForm.name || "");
        root.find("[data-visitor-email]").val(bindData.profileForm.email || "");
        root.find("[data-visitor-phone]").val(bindData.profileForm.phone || "");
        root.find("[data-visitor-details]").val(bindData.profileForm.details || "");
        root.find("[data-chat-message]").val(bindData.messageText || "");

        root.find("[data-profile-id]").text("Profile #" + (profileValue("id") || ""));
        root.find("[data-profile-name]").text(profileValue("name") || "Visitor");
        root.find("[data-profile-email]").text(profileValue("email") || "");
        root.find("[data-profile-phone]").text(profileValue("contactno") || profileValue("phone") || "");
        root.find("[data-profile-initial]").text(profileInitial());
        var avatar = root.find("[data-profile-avatar]");
        var avatarUrl = profileImageUrl();
        if (avatarUrl) {
            avatar.attr("src", avatarUrl).show();
        } else {
            avatar.removeAttr("src").hide();
        }

        root.find("[data-save-profile],[data-send-message],[data-visitor-name],[data-visitor-email],[data-visitor-phone],[data-visitor-details],[data-chat-message]").prop("disabled", !!bindData.busy);
    }

    function renderFallbackMessages(root) {
        var thread = root.find("[data-chat-thread]");
        if (!thread.length) {
            return;
        }

        if (!bindData.messages || bindData.messages.length === 0) {
            thread.html('<div class="chat-agent__empty">No messages yet.</div>');
            return;
        }

        var html = [];
        $.each(bindData.messages, function(index, message) {
            html.push(
                '<article class="chat-agent__bubble ' + bubbleClasses(message) + '" tabindex="-1" data-chat-message-item>' +
                    '<div class="chat-agent__bubble-meta">' +
                        '<span>' + escapeHtml(displaySender(message)) + '</span>' +
                        '<time>' + escapeHtml(message && message.createdAt ? message.createdAt : "") + '</time>' +
                    '</div>' +
                    '<div class="chat-agent__bubble-body chat-agent__bubble-body--formatted">' + formatMessage(message) + '</div>' +
                '</article>'
            );
        });
        thread.html(html.join(""));
    }

    function sessionLabelText() {
        return bindData.session && bindData.session.sessionKey
            ? "Session " + bindData.session.sessionKey
            : "Profile not saved";
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
        return $("<div>").text(value === null || typeof value === "undefined" ? "" : String(value)).html();
    }

    function storageGet(key, fallback) {
        try {
            return window.localStorage.getItem(key) || fallback;
        } catch (ignore) {
            return fallback;
        }
    }

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (ignore) {
        }
    }

    function escapeClass(value) {
        return String(value).replace(/[^a-z0-9_-]/gi, "-").toLowerCase();
    }
});
