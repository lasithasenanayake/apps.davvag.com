WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var state = {
        connectors: [],
        agents: [],
        flows: [],
        currentFlow: null,
        activeConnector: "whatsapp",
        activePanel: "flow"
    };

    function find(selector) {
        return root.find(selector);
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value || {}));
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            return {
                success: false,
                message: response && response.result && response.result.message ? response.result.message : "DAVVAG service call failed."
            };
        }

        if (!response.result) {
            return {
                success: false,
                message: "DAVVAG service returned an empty response."
            };
        }

        return response.result;
    }

    function setStatus(message, tone) {
        var status = find("[data-flow-status]");
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function setBusy(isBusy) {
        find("button, input, textarea, select").prop("disabled", isBusy);
    }

    function splitLines(value) {
        var lines = (value || "").split(/\r?\n/);
        var out = [];
        for (var i = 0; i < lines.length; i++) {
            var item = $.trim(lines[i]);
            if (item) {
                out.push(item);
            }
        }
        return out;
    }

    function connectorDefaults() {
        var items = [];
        for (var i = 0; i < state.connectors.length; i++) {
            items.push({
                code: state.connectors[i].code,
                enabled: true,
                status: "draft",
                settings: {}
            });
        }
        return items;
    }

    function emptyFlow() {
        return {
            flowCode: "",
            name: "",
            agentCode: "",
            status: "draft",
            triggers: ["new message", "support request"],
            escalationTarget: "",
            notes: "",
            connectors: connectorDefaults()
        };
    }

    function ensureFlow(flow) {
        var next = clone(flow || emptyFlow());
        next.flowCode = next.flowCode || "";
        next.name = next.name || "";
        next.agentCode = next.agentCode || "";
        next.status = next.status || "draft";
        next.triggers = $.isArray(next.triggers) ? next.triggers : [];
        next.connectors = $.isArray(next.connectors) ? next.connectors : [];

        for (var i = 0; i < state.connectors.length; i++) {
            var code = state.connectors[i].code;
            if (!getAssignment(code, next)) {
                next.connectors.push({
                    code: code,
                    enabled: true,
                    status: "draft",
                    settings: {}
                });
            }
        }

        return next;
    }

    function getConnectorDef(code) {
        for (var i = 0; i < state.connectors.length; i++) {
            if (state.connectors[i].code === code) {
                return state.connectors[i];
            }
        }
        return null;
    }

    function getAssignment(code, flow) {
        var source = flow || state.currentFlow;
        if (!source || !$.isArray(source.connectors)) {
            return null;
        }

        for (var i = 0; i < source.connectors.length; i++) {
            if (source.connectors[i].code === code) {
                source.connectors[i].settings = source.connectors[i].settings || {};
                source.connectors[i].status = source.connectors[i].status || "draft";
                return source.connectors[i];
            }
        }

        return null;
    }

    function getAgent(agentCode) {
        for (var i = 0; i < state.agents.length; i++) {
            if (state.agents[i].agentCode === agentCode) {
                return state.agents[i];
            }
        }
        return null;
    }

    function enabledConnectors() {
        var out = [];
        if (!state.currentFlow) {
            return out;
        }

        for (var i = 0; i < state.currentFlow.connectors.length; i++) {
            if (state.currentFlow.connectors[i].enabled === true) {
                out.push(state.currentFlow.connectors[i]);
            }
        }
        return out;
    }

    function syncFromForm() {
        if (!state.currentFlow) {
            state.currentFlow = emptyFlow();
        }

        state.currentFlow.flowCode = $.trim(find("[data-flow-code]").val());
        state.currentFlow.name = $.trim(find("[data-flow-name]").val());
        state.currentFlow.agentCode = find("[data-flow-agent]").val();
        state.currentFlow.status = find("[data-flow-state]").val();
        state.currentFlow.triggers = splitLines(find("[data-flow-triggers]").val());
        state.currentFlow.escalationTarget = $.trim(find("[data-flow-escalation]").val());
        state.currentFlow.notes = $.trim(find("[data-flow-notes]").val());
        return state.currentFlow;
    }

    function populateForm() {
        var flow = ensureFlow(state.currentFlow);
        state.currentFlow = flow;
        find("[data-flow-code]").val(flow.flowCode || "");
        find("[data-flow-name]").val(flow.name || "");
        find("[data-flow-agent]").val(flow.agentCode || "");
        find("[data-flow-state]").val(flow.status || "draft");
        find("[data-flow-triggers]").val((flow.triggers || []).join("\n"));
        find("[data-flow-escalation]").val(flow.escalationTarget || "");
        find("[data-flow-notes]").val(flow.notes || "");
    }

    function renderFlows() {
        var list = find("[data-flow-list]");
        list.empty();
        find("[data-flow-count]").text(state.flows.length);

        if (!state.flows.length) {
            list.append($("<div>").addClass("agent-flow__empty").text("No saved flows."));
            return;
        }

        for (var i = 0; i < state.flows.length; i++) {
            var flow = state.flows[i];
            var item = $("<button>").attr("type", "button").addClass("agent-flow__list-item");
            item.attr("data-select-flow", flow.flowCode);
            if (state.currentFlow && flow.flowCode === state.currentFlow.flowCode) {
                item.addClass("is-active");
            }
            item.append($("<strong>").text(flow.name || flow.flowCode));
            item.append($("<span>").text(flow.flowCode || "unsaved"));
            item.append($("<small>").text((flow.status || "draft") + " / " + connectorSummary(flow)));
            list.append(item);
        }
    }

    function renderAgents() {
        var list = find("[data-agent-list]");
        var select = find("[data-flow-agent]");
        list.empty();
        select.empty().append($("<option>").attr("value", "").text("No agent"));
        find("[data-agent-count]").text(state.agents.length);

        if (!state.agents.length) {
            list.append($("<div>").addClass("agent-flow__empty").text("No saved agents found."));
            return;
        }

        for (var i = 0; i < state.agents.length; i++) {
            var agent = state.agents[i];
            select.append($("<option>").attr("value", agent.agentCode).text(agent.name + " (" + agent.agentCode + ")"));

            var item = $("<button>").attr("type", "button").addClass("agent-flow__agent-item");
            item.attr("data-select-agent", agent.agentCode);
            if (state.currentFlow && state.currentFlow.agentCode === agent.agentCode) {
                item.addClass("is-active");
            }
            item.append($("<strong>").text(agent.name));
            item.append($("<span>").text(agent.agentCode));
            item.append($("<small>").text((agent.capabilities || []).slice(0, 2).join(" | ")));
            list.append(item);
        }

        if (state.currentFlow) {
            select.val(state.currentFlow.agentCode || "");
        }
    }

    function connectorSummary(flow) {
        var count = 0;
        var connectors = flow && $.isArray(flow.connectors) ? flow.connectors : [];
        for (var i = 0; i < connectors.length; i++) {
            if (connectors[i].enabled === true) {
                count++;
            }
        }
        return count + " connectors";
    }

    function renderCanvas() {
        var canvas = find("[data-flow-canvas]");
        var board = $("<div>").addClass("agent-flow__board");
        var active = enabledConnectors();
        var agent = state.currentFlow ? getAgent(state.currentFlow.agentCode) : null;

        var inboundLane = $("<div>").addClass("agent-flow__lane agent-flow__lane--connectors");
        if (!active.length) {
            inboundLane.append(node("No connector", "Inbound channels", "disabled", "agent-flow__node--router"));
        } else {
            for (var i = 0; i < active.length; i++) {
                var def = getConnectorDef(active[i].code);
                inboundLane.append(node(def ? def.label : active[i].code, "Inbound", active[i].status || "draft", "agent-flow__node--connector"));
            }
        }

        var agentLane = $("<div>").addClass("agent-flow__lane agent-flow__lane--agent");
        agentLane.append(node(agent ? agent.name : "No agent selected", "AI agent", agent ? agent.agentCode : "unassigned", "agent-flow__node--agent"));

        var outputLane = $("<div>").addClass("agent-flow__lane agent-flow__lane--outputs");
        outputLane.append(node("Response composer", "Route response to source channel", state.currentFlow ? state.currentFlow.status : "draft", "agent-flow__node--output"));
        outputLane.append(node("Escalation", "Human handoff", state.currentFlow && state.currentFlow.escalationTarget ? state.currentFlow.escalationTarget : "not set", "agent-flow__node--output"));

        board.append(inboundLane);
        board.append($("<div>").addClass("agent-flow__edge agent-flow__edge--one"));
        board.append(agentLane);
        board.append($("<div>").addClass("agent-flow__edge agent-flow__edge--two"));
        board.append(outputLane);
        canvas.empty().append(board);
    }

    function node(title, subtitle, badge, extraClass) {
        return $("<div>")
            .addClass("agent-flow__node " + (extraClass || ""))
            .append($("<small>").text(subtitle))
            .append($("<strong>").text(title))
            .append($("<span>").text(badge));
    }

    function renderConnectorStrip() {
        var strip = find("[data-connector-strip]");
        strip.empty();
        for (var i = 0; i < state.connectors.length; i++) {
            var def = state.connectors[i];
            var assignment = getAssignment(def.code);
            var item = $("<button>").attr("type", "button").addClass("agent-flow__channel");
            item.attr("data-open-connector", def.code);
            if (state.activeConnector === def.code) {
                item.addClass("is-active");
            }
            item.append($("<strong>").text(def.label));
            item.append($("<span>").text(assignment && assignment.enabled ? assignment.status || "draft" : "disabled"));
            strip.append(item);
        }
    }

    function renderConnectorList() {
        var list = find("[data-connector-list]");
        list.empty();
        for (var i = 0; i < state.connectors.length; i++) {
            var def = state.connectors[i];
            var assignment = getAssignment(def.code);
            var row = $("<div>").addClass("agent-flow__connector-card").attr("data-open-connector", def.code);
            if (state.activeConnector === def.code) {
                row.addClass("is-active");
            }
            row.append($("<input>").attr("type", "checkbox").attr("data-connector-enabled", def.code).prop("checked", assignment && assignment.enabled === true));
            var text = $("<div>");
            text.append($("<strong>").text(def.label));
            text.append($("<span>").text(def.category + " / " + def.deliveryMode));
            row.append(text);
            row.append($("<span>").addClass("agent-flow__badge").text(assignment && assignment.enabled ? assignment.status || "draft" : "off"));
            list.append(row);
        }
    }

    function renderWebhookList() {
        var list = find("[data-webhook-list]");
        list.empty();

        var head = $("<div>").addClass("agent-flow__rail-head");
        head.append($("<h2>").text("Webhook URLs"));
        head.append($("<span>").text(state.currentFlow && state.currentFlow.flowCode ? "ready" : "save flow first"));
        list.append(head);

        if (!state.currentFlow || !state.currentFlow.flowCode) {
            list.append($("<div>").addClass("agent-flow__webhook-empty").text("Save this flow to generate connector webhook URLs."));
            return;
        }

        for (var i = 0; i < state.connectors.length; i++) {
            var def = state.connectors[i];
            var assignment = getAssignment(def.code);
            var item = $("<div>").addClass("agent-flow__webhook-row");
            if (!assignment || assignment.enabled !== true) {
                item.addClass("is-disabled");
            }
            item.append($("<span>").addClass("agent-flow__webhook-label").text(def.label));
            item.append($("<input>").attr("type", "text").attr("readonly", "readonly").val(webhookUrlFor(def.code) || "Save flow to generate URL"));
            item.append($("<button>").attr("type", "button").addClass("agent-flow__button").attr("data-copy-webhook", def.code).text("Copy"));
            list.append(item);
        }
    }

    function webhookUrlFor(connectorCode) {
        var assignment = getAssignment(connectorCode);
        if (assignment && assignment.webhookUrl) {
            return assignment.webhookUrl;
        }
        if (state.currentFlow && state.currentFlow.webhookUrls && state.currentFlow.webhookUrls[connectorCode]) {
            return state.currentFlow.webhookUrls[connectorCode];
        }
        if (!state.currentFlow || !state.currentFlow.flowCode) {
            return "";
        }

        var base = window.location.href.split("#")[0];
        if (base.charAt(base.length - 1) !== "/") {
            base += "/";
        }
        return base + "components/davvag-agent-flow/flow-api/service/Webhook/" + encodeURIComponent(state.currentFlow.flowCode) + "/" + encodeURIComponent(connectorCode);
    }

    function renderConnectorEditor() {
        var editor = find("[data-connector-editor]");
        var def = getConnectorDef(state.activeConnector) || state.connectors[0];
        if (!def) {
            editor.empty();
            return;
        }
        state.activeConnector = def.code;

        var assignment = getAssignment(def.code);
        var header = $("<div>").addClass("agent-flow__rail-head");
        header.append($("<h2>").text(def.label));
        header.append($("<span>").text(def.deliveryMode));
        editor.empty().append(header);

        var webhook = $("<label>").addClass("agent-flow__field");
        webhook.append($("<span>").text("Webhook URL"));
        var webhookControl = $("<div>").addClass("agent-flow__copy-field");
        webhookControl.append($("<input>").attr("type", "text").attr("readonly", "readonly").val(webhookUrlFor(def.code) || "Save flow to generate URL"));
        webhookControl.append($("<button>").attr("type", "button").addClass("agent-flow__button").attr("data-copy-webhook", def.code).text("Copy"));
        webhook.append(webhookControl);
        editor.append(webhook);

        var status = $("<label>").addClass("agent-flow__field");
        status.append($("<span>").text("Connection status"));
        var select = $("<select>").attr("data-connector-status", def.code);
        select.append($("<option>").attr("value", "draft").text("Draft"));
        select.append($("<option>").attr("value", "ready").text("Ready"));
        select.append($("<option>").attr("value", "paused").text("Paused"));
        select.val(assignment ? assignment.status || "draft" : "draft");
        status.append(select);
        editor.append(status);

        for (var i = 0; i < def.fields.length; i++) {
            var field = def.fields[i];
            var value = assignment && assignment.settings ? assignment.settings[field.key] || "" : "";
            var label = $("<label>").addClass("agent-flow__field");
            label.append($("<span>").text(field.label));
            var input;
            if (field.type === "textarea") {
                input = $("<textarea>").attr("rows", "3");
            } else {
                input = $("<input>").attr("type", field.secret ? "password" : field.type || "text");
            }
            input.attr("data-setting-key", field.key);
            input.attr("placeholder", field.placeholder || "");
            input.val(value);
            label.append(input);
            editor.append(label);
        }
    }

    function renderTestConnectorOptions() {
        var select = find("[data-test-connector]");
        select.empty();
        for (var i = 0; i < state.connectors.length; i++) {
            var def = state.connectors[i];
            var assignment = getAssignment(def.code);
            if (assignment && assignment.enabled === true) {
                select.append($("<option>").attr("value", def.code).text(def.label));
            }
        }
        if (!select.children().length) {
            select.append($("<option>").attr("value", "").text("No enabled connector"));
        }
    }

    function renderJson() {
        find("[data-flow-json]").text(JSON.stringify(syncFromForm(), null, 2));
    }

    function renderWorkspace() {
        renderFlows();
        renderAgents();
        renderCanvas();
        renderConnectorStrip();
        renderConnectorList();
        renderWebhookList();
        renderConnectorEditor();
        renderTestConnectorOptions();
        renderJson();
    }

    function renderAll() {
        populateForm();
        renderWorkspace();
    }

    function loadBootstrap() {
        if (!api) {
            setStatus("The flow-api service is not loaded.", "error");
            return;
        }

        setBusy(true);
        api.services.Bootstrap()
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load workspace.", "error");
                    return;
                }
                state.connectors = result.connectors || [];
                state.agents = result.agents || [];
                state.flows = result.flows || [];
                state.currentFlow = state.flows.length ? ensureFlow(state.flows[0]) : emptyFlow();
                if (state.connectors.length) {
                    state.activeConnector = state.connectors[0].code;
                }
                renderAll();
                setStatus("Workspace ready with " + state.connectors.length + " connectors.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load workspace.", "error");
            });
    }

    function newFlow() {
        state.currentFlow = emptyFlow();
        if (state.connectors.length) {
            state.activeConnector = state.connectors[0].code;
        }
        renderAll();
        setStatus("New flow draft.", "muted");
    }

    function selectFlow(flowCode) {
        for (var i = 0; i < state.flows.length; i++) {
            if (state.flows[i].flowCode === flowCode) {
                state.currentFlow = ensureFlow(state.flows[i]);
                renderAll();
                setStatus("Flow loaded.", "muted");
                return;
            }
        }
    }

    function saveFlow() {
        if (!api) {
            setStatus("The flow-api service is not loaded.", "error");
            return;
        }

        var flow = syncFromForm();
        setBusy(true);
        setStatus("Saving flow...", "muted");
        api.services.SaveFlow(flow)
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Flow was not saved.", "error");
                    return;
                }
                state.flows = result.flows || [];
                state.currentFlow = ensureFlow(result.flow || flow);
                renderAll();
                setStatus("Flow saved. Connector webhook URLs are ready to copy.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Flow was not saved.", "error");
            });
    }

    function deleteFlow() {
        if (!state.currentFlow || !state.currentFlow.flowCode) {
            setStatus("Select a saved flow before deleting.", "error");
            return;
        }
        if (!confirm("Delete flow " + state.currentFlow.flowCode + "?")) {
            return;
        }

        setBusy(true);
        api.services.DeleteFlow({ flowCode: state.currentFlow.flowCode })
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Flow was not deleted.", "error");
                    return;
                }
                state.flows = result.flows || [];
                state.currentFlow = state.flows.length ? ensureFlow(state.flows[0]) : emptyFlow();
                renderAll();
                setStatus("Flow deleted.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Flow was not deleted.", "error");
            });
    }

    function runSimulation() {
        var flow = syncFromForm();
        setBusy(true);
        find("[data-run-output]").text("Running...");
        api.services.Simulate({
            flow: flow,
            flowCode: flow.flowCode,
            connectorCode: find("[data-test-connector]").val(),
            sender: find("[data-test-sender]").val(),
            message: find("[data-test-message]").val()
        })
            .then(function(response) {
                setBusy(false);
                var result = serviceResult(response);
                if (result.success === false) {
                    find("[data-run-output]").text(result.message || "Simulation failed.");
                    setStatus(result.message || "Simulation failed.", "error");
                    return;
                }
                find("[data-run-output]").text(JSON.stringify(result.run, null, 2));
                setStatus("Simulation route built.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                find("[data-run-output]").text(result.message || "Simulation failed.");
                setStatus(result.message || "Simulation failed.", "error");
            });
    }

    function updateConnectorSetting(key, value) {
        var assignment = getAssignment(state.activeConnector);
        if (!assignment) {
            return;
        }
        assignment.settings = assignment.settings || {};
        assignment.settings[key] = value;
        renderJson();
    }

    function copyJson() {
        var text = find("[data-flow-json]").text();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                setStatus("Flow JSON copied.", "success");
            });
            return;
        }

        var temp = $("<textarea>").val(text).appendTo(root);
        temp[0].select();
        document.execCommand("copy");
        temp.remove();
        setStatus("Flow JSON copied.", "success");
    }

    function copyWebhook(connectorCode) {
        var url = webhookUrlFor(connectorCode);
        if (!url) {
            setStatus("Save this flow before copying connector webhooks.", "error");
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function() {
                setStatus("Webhook URL copied.", "success");
            });
            return;
        }

        var temp = $("<textarea>").val(url).appendTo(root);
        temp[0].select();
        document.execCommand("copy");
        temp.remove();
        setStatus("Webhook URL copied.", "success");
    }

    function bindEvents() {
        find("[data-new-flow]").on("click", newFlow);
        find("[data-save-flow]").on("click", saveFlow);
        find("[data-delete-flow]").on("click", deleteFlow);
        find("[data-run-test]").on("click", runSimulation);
        find("[data-copy-json]").on("click", copyJson);
        find("[data-panel='connectors']").on("click", "[data-copy-webhook]", function(event) {
            event.preventDefault();
            event.stopPropagation();
            copyWebhook($(this).attr("data-copy-webhook"));
        });

        find("[data-flow-code], [data-flow-name], [data-flow-state], [data-flow-triggers], [data-flow-escalation], [data-flow-notes]").on("input change", function() {
            syncFromForm();
            renderCanvas();
            renderWebhookList();
            renderConnectorEditor();
            renderJson();
        });

        find("[data-flow-agent]").on("change", function() {
            syncFromForm();
            renderAgents();
            renderCanvas();
            renderJson();
        });

        find("[data-panel-tab]").on("click", function() {
            state.activePanel = $(this).attr("data-panel-tab");
            find("[data-panel-tab]").removeClass("is-active");
            $(this).addClass("is-active");
            find("[data-panel]").removeClass("is-active");
            find('[data-panel="' + state.activePanel + '"]').addClass("is-active");
        });

        find("[data-flow-list]").on("click", "[data-select-flow]", function() {
            selectFlow($(this).attr("data-select-flow"));
        });

        find("[data-agent-list]").on("click", "[data-select-agent]", function() {
            state.currentFlow.agentCode = $(this).attr("data-select-agent");
            find("[data-flow-agent]").val(state.currentFlow.agentCode);
            renderAgents();
            renderCanvas();
            renderJson();
        });

        find("[data-connector-strip], [data-connector-list]").on("click", "[data-open-connector]", function(event) {
            var code = $(this).attr("data-open-connector");
            state.activeConnector = code;
            renderConnectorStrip();
            renderConnectorList();
            renderWebhookList();
            renderConnectorEditor();
            if ($(event.target).is("input")) {
                return;
            }
        });

        find("[data-connector-list]").on("change", "[data-connector-enabled]", function() {
            var code = $(this).attr("data-connector-enabled");
            var assignment = getAssignment(code);
            if (assignment) {
                assignment.enabled = $(this).is(":checked");
            }
            renderCanvas();
            renderConnectorStrip();
            renderConnectorList();
            renderWebhookList();
            renderTestConnectorOptions();
            renderJson();
        });

        find("[data-connector-editor]").on("change", "[data-connector-status]", function() {
            var assignment = getAssignment($(this).attr("data-connector-status"));
            if (assignment) {
                assignment.status = $(this).val();
            }
            renderConnectorStrip();
            renderConnectorList();
            renderCanvas();
            renderWebhookList();
            renderJson();
        });

        find("[data-connector-editor]").on("input change", "[data-setting-key]", function() {
            updateConnectorSetting($(this).attr("data-setting-key"), $(this).val());
        });
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("flow-api");
        bindEvents();
        loadBootstrap();
    };
});
