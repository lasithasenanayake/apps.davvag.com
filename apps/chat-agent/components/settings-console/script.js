WEBDOCK.component().register(function(exports) {
    var api;
    var apiRequested = false;
    var initialized = false;
    var renderElement = null;
    var vueInstance = null;

    var state = {
        agents: [],
        settings: {},
        form: {
            defaultAgentCode: ""
        },
        loading: false,
        saving: false,
        status: {
            message: "Loading settings",
            tone: "muted"
        }
    };

    exports.vue = {
        data: state,
        computed: {
            statusClass: function() {
                return state.status.tone ? "is-" + state.status.tone : "";
            },
            selectedAgent: function() {
                return findAgent(state.form.defaultAgentCode);
            }
        },
        methods: {
            loadSettings: loadSettings,
            saveSettings: saveSettings,
            selectAgent: selectAgent,
            agentLabel: agentLabel
        },
        onReady: function(data, element) {
            if (element) {
                renderElement = element;
            }
            initialize();
        }
    };

    exports.chatAgentSettingsOwnMount = true;
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
            target.attr("id", "chat_agent_settings_" + new Date().getTime());
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
        renderFallback();
        loadSettings();
    }

    function bindEvents() {
        var root = componentRoot();
        root.off(".chatAgentSettings");
        root.on("click.chatAgentSettings", "[data-refresh-settings]", loadSettings);
        root.on("submit.chatAgentSettings", "[data-settings-form]", saveSettings);
        root.on("change.chatAgentSettings", "[data-default-agent]", function() {
            readInputs();
            renderFallback();
        });
        root.on("click.chatAgentSettings", "[data-agent-code]", function() {
            selectAgent($(this).attr("data-agent-code"));
        });
    }

    function loadSettings(event) {
        preventEvent(event);
        if (!api) {
            setStatus("Chat service is not loaded.", "error");
            return;
        }

        state.loading = true;
        renderFallback();
        setStatus("Loading settings...", "muted");

        callApi("Settings", {})
            .then(function(response) {
                state.loading = false;
                var result = serviceResult(response);
                if (result.success === false) {
                    renderFallback();
                    setStatus(result.message || "Unable to load settings.", "error");
                    return;
                }

                state.settings = result.settings || {};
                state.agents = normalizeAgents(result.agents || []);
                state.form.defaultAgentCode = result.defaultAgentCode || state.settings.defaultAgentCode || "";
                renderFallback();

                if (result.agentLoadMessage) {
                    setStatus(result.agentLoadMessage, "error");
                    return;
                }
                setStatus(state.agents.length + " saved AI agents", "success");
            })
            .error(function(response) {
                state.loading = false;
                renderFallback();
                setStatus(errorMessage(response, "Unable to load settings."), "error");
            });
    }

    function saveSettings(event) {
        preventEvent(event);
        readInputs();

        if (!api) {
            setStatus("Chat service is not loaded.", "error");
            return;
        }
        if (!state.form.defaultAgentCode) {
            setStatus("Select an AI agent before saving.", "error");
            return;
        }

        state.saving = true;
        renderFallback();
        setStatus("Saving settings...", "muted");

        callApi("SaveSettings", {
            defaultAgentCode: state.form.defaultAgentCode
        })
            .then(function(response) {
                state.saving = false;
                var result = serviceResult(response);
                if (result.success === false) {
                    renderFallback();
                    setStatus(result.message || "Unable to save settings.", "error");
                    return;
                }

                state.settings = result.settings || state.settings;
                state.agents = normalizeAgents(result.agents || state.agents);
                state.form.defaultAgentCode = result.defaultAgentCode || state.form.defaultAgentCode;
                renderFallback();
                setStatus("Settings saved.", "success");
            })
            .error(function(response) {
                state.saving = false;
                renderFallback();
                setStatus(errorMessage(response, "Unable to save settings."), "error");
            });
    }

    function selectAgent(agentCode) {
        state.form.defaultAgentCode = agentCode || "";
        renderFallback();
    }

    function agentLabel(agent) {
        var name = value(agent, "name", "") || value(agent, "agentCode", "");
        var code = value(agent, "agentCode", "");
        return code && name !== code ? name + " (" + code + ")" : name;
    }

    function normalizeAgents(agents) {
        var out = [];
        for (var i = 0; i < agents.length; i++) {
            var agent = agents[i] || {};
            var agentCode = value(agent, "agentCode", "");
            if (agentCode) {
                out.push(agent);
            }
        }
        return out;
    }

    function findAgent(agentCode) {
        for (var i = 0; i < state.agents.length; i++) {
            if (value(state.agents[i], "agentCode", "") === agentCode) {
                return state.agents[i];
            }
        }
        return null;
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
        var selected = find("[data-default-agent]");
        if (selected.length && !vueInstance) {
            state.form.defaultAgentCode = selected.val() || "";
        }
    }

    function renderFallback() {
        if (vueInstance) {
            return;
        }

        var root = componentRoot();
        var disabled = !!state.loading || !!state.saving;
        var select = root.find("[data-default-agent]");
        var options = ['<option value="">Select saved AI agent</option>'];
        for (var i = 0; i < state.agents.length; i++) {
            var agent = state.agents[i];
            var code = value(agent, "agentCode", "");
            options.push(
                '<option value="' + escapeAttr(code) + '">' +
                    escapeHtml(agentLabel(agent)) +
                '</option>'
            );
        }
        select.html(options.join("")).val(state.form.defaultAgentCode || "").prop("disabled", disabled);

        var selectedAgent = findAgent(state.form.defaultAgentCode);
        var currentName = selectedAgent ? value(selectedAgent, "name", "") : (state.form.defaultAgentCode || "Not selected");
        root.find("[data-current-agent]").html(
            '<span>Current</span>' +
            '<strong>' + escapeHtml(currentName || state.form.defaultAgentCode || "Not selected") + '</strong>' +
            '<span>' + escapeHtml(state.form.defaultAgentCode || "") + '</span>'
        );

        root.find("[data-refresh-settings]").prop("disabled", disabled);
        root.find("[data-save-settings]").prop("disabled", disabled || !state.form.defaultAgentCode);
        renderAgents();
    }

    function renderAgents() {
        if (vueInstance) {
            return;
        }

        var list = find("[data-agent-list]");
        if (!state.agents.length) {
            list.html('<div class="chat-agent__empty">No saved AI agents found.</div>');
            return;
        }

        var html = [];
        for (var i = 0; i < state.agents.length; i++) {
            var agent = state.agents[i];
            var code = value(agent, "agentCode", "");
            html.push(
                '<button type="button" class="chat-agent__agent-row' +
                    (code === state.form.defaultAgentCode ? " is-selected" : "") +
                    '" data-agent-code="' + escapeAttr(code) + '">' +
                    '<strong>' + escapeHtml(value(agent, "name", "") || code) + '</strong>' +
                    '<span>' + escapeHtml(code) + '</span>' +
                    '<p>' + escapeHtml(value(agent, "description", "")) + '</p>' +
                '</button>'
            );
        }
        list.html(html.join(""));
    }

    function setStatus(message, tone) {
        state.status.message = message || "";
        state.status.tone = tone || "";
        var node = find("[data-settings-status]");
        node.removeClass("is-error is-muted is-success");
        if (tone) {
            node.addClass("is-" + tone);
        }
        node.text(message || "");
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

    function errorMessage(response, fallback) {
        var payload = response && response.responseJSON ? response.responseJSON : response;
        var result = serviceResult(payload);
        return result.message || fallback;
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

    function value(object, key, fallback) {
        if (object && typeof object[key] !== "undefined" && object[key] !== null) {
            return object[key];
        }
        return fallback;
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
});
