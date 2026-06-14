WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var nodeWidth = 236;
    var nodeHeight = 106;
    var reservedKeys = {
        name: true,
        start_up_node: true,
        inputData: true,
        __designer: true
    };

    var state = {
        workflows: [],
        templates: [],
        namespaces: [],
        workflow: null,
        filename: "new-flow",
        namespace: "",
        selectedNodeId: "",
        linking: null,
        templateSearch: "",
        appServiceFilter: "",
        windowMaximized: false,
        runInput: "{}",
        runResult: "No run yet.",
        runSuccess: null
    };

    function find(selector) {
        return root.find(selector);
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function escapeHtml(value) {
        return String(value === undefined || value === null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function serviceResult(response) {
        if (!response || response.success !== true) {
            return {
                success: false,
                message: "DAVVAG service call failed."
            };
        }

        if (!response.result) {
            return {
                success: false,
                message: "DAVVAG service returned an empty response."
            };
        }

        if (response.result.success === false) {
            return response.result;
        }

        return response.result;
    }

    function setStatus(message, tone) {
        var status = find("[data-flow-status]");
        status.removeClass("is-success is-error");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function setBusy(isBusy) {
        find("button, input, select, textarea").prop("disabled", isBusy);
    }

    function blankWorkflow() {
        var workflow = {
            name: "New Workflow",
            start_up_node: "start",
            inputData: {},
            __designer: {
                nodes: {
                    start: {
                        x: 120,
                        y: 120
                    }
                }
            }
        };

        workflow.start = {
            urntype: "create_object",
            method: {
                type: "create_object",
                name: "BuildObject",
                return: true,
                returnobj: "result"
            },
            variables: [
                {
                    name: "message",
                    value: "Done"
                }
            ]
        };

        return workflow;
    }

    function setWorkflow(workflow, filename, namespace) {
        state.workflow = workflow || blankWorkflow();
        state.filename = normalizeFilename(filename || "new-flow");
        state.namespace = namespace || "";
        state.runInput = JSON.stringify(sampleInputFromSchema(state.workflow.inputData || {}), null, 2);
        state.runResult = "No run yet.";
        state.runSuccess = null;
        ensureDesigner();
        ensureNodePositions();

        var keys = nodeKeys();
        if (!state.selectedNodeId || !state.workflow[state.selectedNodeId]) {
            state.selectedNodeId = state.workflow.start_up_node && state.workflow[state.workflow.start_up_node]
                ? state.workflow.start_up_node
                : (keys.length ? keys[0] : "");
        }

        renderAll();
    }

    function normalizeFilename(value) {
        value = String(value || "").replace(/\.json$/i, "");
        value = value.replace(/[^A-Za-z0-9_.-]+/g, "-").replace(/^-+|-+$/g, "");
        return value || "new-flow";
    }

    function normalizeNodeId(value) {
        value = String(value || "").trim().replace(/[^A-Za-z0-9_.-]+/g, "-");
        value = value.replace(/^-+|-+$/g, "");
        return value || "node";
    }

    function uniqueNodeId(baseId) {
        var base = normalizeNodeId(baseId);
        var id = base;
        var index = 2;
        while (state.workflow[id]) {
            id = base + "-" + index;
            index++;
        }
        return id;
    }

    function nodeKeys() {
        var keys = [];
        if (!state.workflow) {
            return keys;
        }

        Object.keys(state.workflow).forEach(function(key) {
            if (reservedKeys[key]) {
                return;
            }
            if (state.workflow[key] && typeof state.workflow[key] === "object" && state.workflow[key].urntype) {
                keys.push(key);
            }
        });
        return keys;
    }

    function ensureDesigner() {
        if (!state.workflow.__designer || typeof state.workflow.__designer !== "object") {
            state.workflow.__designer = {};
        }
        if (!state.workflow.__designer.nodes || typeof state.workflow.__designer.nodes !== "object") {
            state.workflow.__designer.nodes = {};
        }
    }

    function ensureNodePositions() {
        ensureDesigner();
        var keys = nodeKeys();
        keys.forEach(function(key, index) {
            if (!state.workflow.__designer.nodes[key]) {
                state.workflow.__designer.nodes[key] = {
                    x: 110 + (index % 4) * 300,
                    y: 110 + Math.floor(index / 4) * 180
                };
            }
        });
    }

    function nodePosition(nodeId) {
        ensureDesigner();
        if (!state.workflow.__designer.nodes[nodeId]) {
            state.workflow.__designer.nodes[nodeId] = {
                x: 120,
                y: 120
            };
        }
        return state.workflow.__designer.nodes[nodeId];
    }

    function nodeLabel(node) {
        if (!node) {
            return "";
        }
        if (node.urntype === "service") {
            return [node.appCode, node.componentCode, node.method && node.method.name].filter(Boolean).join(" / ");
        }
        if (node.urntype === "class") {
            return [node.class, node.method && node.method.name].filter(Boolean).join("::");
        }
        if (node.urntype === "create_object") {
            return node.method && node.method.name ? node.method.name : "Create Object";
        }
        return node.urntype;
    }

    function nodeIcon(node) {
        if (!node) {
            return "?";
        }
        if (node.urntype === "service") {
            return "S";
        }
        if (node.urntype === "class") {
            return "C";
        }
        if (node.urntype === "create_object") {
            return "{}";
        }
        return "?";
    }

    function currentPath() {
        return "davvag-flow/" + (state.namespace ? state.namespace + "/" : "") + state.filename + ".json";
    }

    function loadDesignerData() {
        if (!api) {
            setStatus("flow-designer-api is not loaded.", "error");
            return;
        }

        setStatus("Loading workflows...");
        api.services.DesignerData()
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load designer data.", "error");
                    return;
                }
                state.workflows = result.workflows || [];
                state.templates = result.toolbox || [];
                state.namespaces = result.namespaces || [];
                renderWorkflowList();
                renderTemplates();
                renderNamespaceList();
                setStatus("Designer ready.", "success");
            })
            .error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load designer data.", "error");
            });
    }

    function refreshWorkflows() {
        if (!api) {
            return;
        }
        api.services.ListWorkflows()
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to refresh workflows.", "error");
                    return;
                }
                state.workflows = result.workflows || [];
                state.namespaces = result.namespaces || [];
                renderWorkflowList();
                renderNamespaceList();
                setStatus("Workflow list refreshed.", "success");
            })
            .error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to refresh workflows.", "error");
            });
    }

    function loadWorkflow(index) {
        var item = state.workflows[index];
        if (!item || !api) {
            return;
        }

        setStatus("Loading " + item.path + "...");
        api.services.LoadWorkflow({
            namespace: item.namespace,
            filename: item.filename
        })
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load workflow.", "error");
                    return;
                }

                state.selectedNodeId = "";
                setWorkflow(result.workflow, result.flowid || result.filename, result.namespace || "");
                setStatus("Loaded " + item.path + ".", "success");
            })
            .error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load workflow.", "error");
            });
    }

    function saveWorkflow() {
        if (!api) {
            setStatus("flow-designer-api is not loaded.", "error");
            return;
        }

        state.filename = normalizeFilename(state.filename);
        ensureDesigner();
        cleanEmptyEdges();
        setBusy(true);
        setStatus("Saving " + currentPath() + "...");

        api.services.SaveWorkflow({
            namespace: state.namespace,
            filename: state.filename,
            workflow: state.workflow
        })
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    setStatus(result.message || "Unable to save workflow.", "error");
                    return;
                }

                state.workflows = result.workflows || state.workflows;
                state.namespaces = result.namespaces || state.namespaces;
                renderAll();
                setStatus("Saved " + currentPath() + ".", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to save workflow.", "error");
            });
    }

    function deleteWorkflow() {
        if (!api) {
            return;
        }
        if (!confirm("Delete " + currentPath() + "?")) {
            return;
        }

        setBusy(true);
        api.services.DeleteWorkflow({
            namespace: state.namespace,
            filename: state.filename
        })
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    setStatus(result.message || "Unable to delete workflow.", "error");
                    return;
                }

                state.workflows = result.workflows || [];
                state.namespaces = result.namespaces || [];
                newWorkflow();
                renderWorkflowList();
                renderNamespaceList();
                setStatus("Workflow deleted.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to delete workflow.", "error");
            });
    }

    function newWorkflow() {
        var base = "new-flow";
        var filename = base;
        var index = 2;
        while (workflowExists("", filename)) {
            filename = base + "-" + index;
            index++;
        }

        state.selectedNodeId = "start";
        state.linking = null;
        setWorkflow(blankWorkflow(), filename, "");
        setStatus("New workflow initialized.");
    }

    function workflowExists(namespace, filename) {
        filename = normalizeFilename(filename);
        for (var i = 0; i < state.workflows.length; i++) {
            if ((state.workflows[i].namespace || "") === namespace && normalizeFilename(state.workflows[i].filename) === filename) {
                return true;
            }
        }
        return false;
    }

    function renderAll() {
        renderFlowFields();
        renderWorkflowList();
        renderNamespaceList();
        renderTemplates();
        renderCanvas();
        renderInspector();
        renderJson();
        renderRunPanel();
        updateLinkState();
    }

    function renderFlowFields() {
        find('[data-flow-field="namespace"]').val(state.namespace);
        find('[data-flow-field="filename"]').val(state.filename);
        find('[data-flow-field="name"]').val(state.workflow.name || "");

        var startup = find('[data-flow-field="start_up_node"]');
        var keys = nodeKeys();
        startup.empty();
        keys.forEach(function(key) {
            startup.append($("<option>").attr("value", key).text(key));
        });
        startup.val(state.workflow.start_up_node || "");

        find("[data-flow-input-json]").val(JSON.stringify(state.workflow.inputData || {}, null, 2));
        find("[data-current-workflow]").text(state.workflow.name || state.filename);
        find("[data-current-path]").text(currentPath());
    }

    function renderNamespaceList() {
        var list = find("[data-namespace-list]");
        list.empty();
        state.namespaces.forEach(function(namespace) {
            if (namespace !== "") {
                list.append($("<option>").attr("value", namespace));
            }
        });
    }

    function renderWorkflowList() {
        var list = find("[data-workflow-list]");
        list.empty();
        find("[data-workflow-count]").text(state.workflows.length + " files");

        if (!state.workflows.length) {
            list.append($("<div>").addClass("flow-designer__empty").text("No workflow files found."));
            return;
        }

        state.workflows.forEach(function(item, index) {
            var button = $("<button>")
                .attr("type", "button")
                .attr("data-workflow-index", index)
                .addClass("flow-designer__workflow-item");

            if ((item.namespace || "") === state.namespace && normalizeFilename(item.filename) === state.filename) {
                button.addClass("is-active");
            }

            button.append($("<strong>").text(item.name || item.flowid));
            button.append($("<span>").text(item.path + " | " + item.nodeCount + " nodes"));
            list.append(button);
        });
    }

    function renderTemplates() {
        var list = find("[data-template-list]");
        var search = state.templateSearch.toLowerCase();
        var groups = {};
        var count = 0;
        list.empty();
        renderAppServiceFilter();

        state.templates.forEach(function(template) {
            var appCode = templateAppCode(template);
            var haystack = (template.category + " " + template.label + " " + template.urntype + " " + appCode).toLowerCase();
            if (search && haystack.indexOf(search) === -1) {
                return;
            }
            if (template.category === "App Services" && state.appServiceFilter && appCode !== state.appServiceFilter) {
                return;
            }
            if (!groups[template.category]) {
                groups[template.category] = [];
            }
            groups[template.category].push(template);
            count++;
        });

        Object.keys(groups).sort().forEach(function(category) {
            var group = $("<div>").addClass("flow-designer__toolbox-group");
            group.append($("<h3>").text(category));
            groups[category].forEach(function(template) {
                var button = $("<button>")
                    .attr("type", "button")
                    .attr("draggable", "true")
                    .attr("data-template-id", template.id)
                    .attr("data-urntype", template.urntype)
                    .addClass("flow-designer__template");
                button.append($("<strong>").text(template.label));
                button.append($("<span>").text(template.urntype + (templateAppCode(template) ? " | " + templateAppCode(template) : "")));
                group.append(button);
            });
            list.append(group);
        });

        if (!count) {
            list.append($("<div>").addClass("flow-designer__empty").text("No matching nodes."));
        }
        find("[data-template-count]").text(count + " nodes");
    }

    function renderAppServiceFilter() {
        var select = find("[data-app-service-filter]");
        var appCodes = [];
        state.templates.forEach(function(template) {
            if (template.category !== "App Services") {
                return;
            }
            var appCode = templateAppCode(template);
            if (appCode && appCodes.indexOf(appCode) === -1) {
                appCodes.push(appCode);
            }
        });

        appCodes.sort(function(left, right) {
            return left.toLowerCase() < right.toLowerCase() ? -1 : 1;
        });

        if (state.appServiceFilter && appCodes.indexOf(state.appServiceFilter) === -1) {
            state.appServiceFilter = "";
        }

        select.empty();
        select.append($("<option>").attr("value", "").text("All apps"));
        appCodes.forEach(function(appCode) {
            select.append($("<option>").attr("value", appCode).text(appCode));
        });
        select.val(state.appServiceFilter);
    }

    function templateAppCode(template) {
        if (!template || !template.node || template.urntype !== "service") {
            return "";
        }
        return template.node.appCode || "";
    }

    function renderCanvas() {
        ensureNodePositions();
        var layer = find("[data-node-layer]");
        layer.empty();

        nodeKeys().forEach(function(nodeId) {
            var node = state.workflow[nodeId];
            var pos = nodePosition(nodeId);
            var nodeEl = $("<div>")
                .addClass("flow-node flow-node--" + node.urntype)
                .attr("data-node-id", nodeId)
                .css({
                    left: pos.x + "px",
                    top: pos.y + "px"
                });

            if (nodeId === state.selectedNodeId) {
                nodeEl.addClass("is-selected");
            }
            if (state.linking && state.linking.source === nodeId) {
                nodeEl.addClass("is-link-source");
            }

            var body = $("<div>").addClass("flow-node__body").attr("data-drag-handle", "true");
            body.append($("<div>").addClass("flow-node__icon").text(nodeIcon(node)));

            var text = $("<div>").addClass("flow-node__text");
            text.append($("<strong>").text(nodeId));
            text.append($("<span>").text(nodeLabel(node)));
            body.append(text);

            nodeEl.append(body);
            nodeEl.append($("<button>").attr({
                type: "button",
                title: "Success link",
                "data-edge-source": "success"
            }).addClass("flow-node__handle flow-node__handle--success").text("S"));
            nodeEl.append($("<button>").attr({
                type: "button",
                title: "Fail link",
                "data-edge-source": "fail"
            }).addClass("flow-node__handle flow-node__handle--fail").text("F"));
            layer.append(nodeEl);
        });

        window.setTimeout(drawConnections, 0);
    }

    function drawConnections() {
        var svg = find("[data-connections]");
        svg.empty();
        svg.attr({
            width: 2400,
            height: 1500,
            viewBox: "0 0 2400 1500"
        });

        var defs = document.createElementNS("http://www.w3.org/2000/svg", "defs");
        var marker = document.createElementNS("http://www.w3.org/2000/svg", "marker");
        marker.setAttribute("id", "flow-arrow");
        marker.setAttribute("viewBox", "0 0 10 10");
        marker.setAttribute("refX", "9");
        marker.setAttribute("refY", "5");
        marker.setAttribute("markerWidth", "6");
        marker.setAttribute("markerHeight", "6");
        marker.setAttribute("orient", "auto-start-reverse");
        var arrow = document.createElementNS("http://www.w3.org/2000/svg", "path");
        arrow.setAttribute("d", "M 0 0 L 10 5 L 0 10 z");
        arrow.setAttribute("fill", "#b8beca");
        marker.appendChild(arrow);
        defs.appendChild(marker);
        svg[0].appendChild(defs);

        nodeKeys().forEach(function(sourceId) {
            var node = state.workflow[sourceId];
            if (node.success) {
                appendEdge(svg[0], sourceId, node.success, "success");
            }
            if (node.fail) {
                appendEdge(svg[0], sourceId, node.fail, "fail");
            }
        });
    }

    function appendEdge(svg, sourceId, targetId, edgeType) {
        if (!state.workflow[targetId]) {
            return;
        }

        var source = nodePosition(sourceId);
        var target = nodePosition(targetId);
        var sx = edgeType === "fail" ? source.x + nodeWidth / 2 : source.x + nodeWidth;
        var sy = edgeType === "fail" ? source.y + nodeHeight : source.y + 48;
        var tx = target.x;
        var ty = target.y + 48;
        var dx = Math.max(90, Math.abs(tx - sx) / 2);
        var path = document.createElementNS("http://www.w3.org/2000/svg", "path");

        path.setAttribute("d", "M " + sx + " " + sy + " C " + (sx + dx) + " " + sy + ", " + (tx - dx) + " " + ty + ", " + tx + " " + ty);
        path.setAttribute("class", "flow-designer__edge flow-designer__edge--" + edgeType);
        path.setAttribute("marker-end", "url(#flow-arrow)");
        svg.appendChild(path);
    }

    function renderInspector() {
        var target = find("[data-node-inspector]");
        var nodeId = state.selectedNodeId;
        var node = state.workflow && nodeId ? state.workflow[nodeId] : null;
        find("[data-selected-node-label]").text(node ? nodeId : "No node");

        if (!node) {
            target.html('<div class="flow-designer__empty">Select a node.</div>');
            return;
        }

        normalizeNodeShape(node);

        var html = '';
        html += '<form class="flow-designer__inspector-form" data-node-form>';
        html += '<div class="flow-designer__inspector-grid">';
        html += fieldHtml("Node id", '<input type="text" data-node-id-input value="' + escapeHtml(nodeId) + '">');
        html += fieldHtml("Type", urnTypeSelect(node.urntype));
        html += fieldHtml("Success", edgeSelect("success", node.success || ""));
        html += fieldHtml("Fail", edgeSelect("fail", node.fail || ""));
        html += '</div>';

        if (node.urntype === "class") {
            html += '<div class="flow-designer__inspector-grid">';
            html += fieldHtml("File", '<input type="text" data-node-path="file" value="' + escapeHtml(node.file || "") + '">');
            html += fieldHtml("Class", '<input type="text" data-node-path="class" value="' + escapeHtml(node.class || "") + '">');
            html += fieldHtml("Method", '<input type="text" data-node-path="method.name" value="' + escapeHtml(node.method.name || "") + '">');
            html += fieldHtml("Return object", '<input type="text" data-node-path="method.returnobj" value="' + escapeHtml(node.method.returnobj || "") + '">');
            html += '</div>';
            html += toggleHtml("Store return", "method.return", node.method.return !== false);
            html += jsonEditorHtml("Params JSON", "method.params", node.method.params || []);
        } else if (node.urntype === "service") {
            html += '<div class="flow-designer__inspector-grid">';
            html += fieldHtml("App code", '<input type="text" data-node-path="appCode" value="' + escapeHtml(node.appCode || "") + '">');
            html += fieldHtml("Component", '<input type="text" data-node-path="componentCode" value="' + escapeHtml(node.componentCode || "") + '">');
            html += fieldHtml("Method type", methodTypeSelect(node.method.type || "post"));
            html += fieldHtml("Method", '<input type="text" data-node-path="method.name" value="' + escapeHtml(node.method.name || "") + '">');
            html += fieldHtml("Return object", '<input type="text" data-node-path="method.returnobj" value="' + escapeHtml(node.method.returnobj || "") + '">');
            html += '</div>';
            html += toggleHtml("Store return", "method.return", node.method.return !== false);
            html += jsonEditorHtml("Params JSON", "method.params", node.method.params || []);
        } else if (node.urntype === "create_object") {
            html += '<div class="flow-designer__inspector-grid">';
            html += fieldHtml("Method", '<input type="text" data-node-path="method.name" value="' + escapeHtml(node.method.name || "") + '">');
            html += fieldHtml("Return object", '<input type="text" data-node-path="method.returnobj" value="' + escapeHtml(node.method.returnobj || "") + '">');
            html += '</div>';
            html += toggleHtml("Store return", "method.return", node.method.return !== false);
            html += jsonEditorHtml("Variables JSON", "variables", node.variables || []);
        }

        html += jsonEditorHtml("Raw node JSON", "__raw", node);
        html += '<div class="flow-designer__inspector-actions">';
        html += '<button type="button" class="flow-designer__button flow-designer__button--danger" data-delete-node>Delete Node</button>';
        html += '<button type="button" class="flow-designer__button" data-duplicate-node>Duplicate</button>';
        html += '</div>';
        html += '</form>';

        target.html(html);
    }

    function fieldHtml(label, controlHtml) {
        return '<label class="flow-designer__field"><span>' + escapeHtml(label) + '</span>' + controlHtml + '</label>';
    }

    function toggleHtml(label, path, checked) {
        return '<label class="flow-designer__toggle"><input type="checkbox" data-node-checkbox="' + escapeHtml(path) + '"' + (checked ? " checked" : "") + '><span>' + escapeHtml(label) + '</span></label>';
    }

    function jsonEditorHtml(label, path, value) {
        return '<label class="flow-designer__field flow-designer__field--full"><span>' + escapeHtml(label) + '</span><textarea rows="7" data-json-path="' + escapeHtml(path) + '" spellcheck="false">' + escapeHtml(JSON.stringify(value, null, 2)) + '</textarea></label><div class="flow-designer__inspector-actions"><button type="button" class="flow-designer__mini-button" data-apply-json="' + escapeHtml(path) + '">Apply</button></div>';
    }

    function urnTypeSelect(value) {
        return '<select data-node-urntype><option value="class"' + selected(value, "class") + '>class</option><option value="service"' + selected(value, "service") + '>service</option><option value="create_object"' + selected(value, "create_object") + '>create_object</option></select>';
    }

    function methodTypeSelect(value) {
        return '<select data-node-path="method.type"><option value="get"' + selected(value, "get") + '>get</option><option value="post"' + selected(value, "post") + '>post</option><option value="put"' + selected(value, "put") + '>put</option><option value="delete"' + selected(value, "delete") + '>delete</option></select>';
    }

    function edgeSelect(edgeType, value) {
        var html = '<select data-edge-select="' + edgeType + '"><option value="">None</option>';
        nodeKeys().forEach(function(key) {
            if (key !== state.selectedNodeId) {
                html += '<option value="' + escapeHtml(key) + '"' + selected(value, key) + '>' + escapeHtml(key) + '</option>';
            }
        });
        html += '</select>';
        return html;
    }

    function selected(current, value) {
        return String(current || "") === String(value || "") ? " selected" : "";
    }

    function normalizeNodeShape(node) {
        if (!node.method || typeof node.method !== "object") {
            node.method = {};
        }
        if (!node.method.params || !Array.isArray(node.method.params)) {
            node.method.params = [];
        }
        if (node.urntype === "service" && !node.method.type) {
            node.method.type = "post";
        }
        if (node.urntype === "create_object") {
            node.method.type = "create_object";
            if (!Array.isArray(node.variables)) {
                node.variables = [];
            }
        }
        if (node.method.return === undefined) {
            node.method.return = true;
        }
    }

    function renderJson() {
        find("[data-workflow-json]").text(JSON.stringify(state.workflow || {}, null, 2));
    }

    function renderRunPanel() {
        var input = find("[data-run-input-json]");
        if (!input.is(":focus")) {
            input.val(state.runInput);
        }

        var result = find("[data-run-result]");
        result.removeClass("is-success is-error");
        if (state.runSuccess === true) {
            result.addClass("is-success");
        } else if (state.runSuccess === false) {
            result.addClass("is-error");
        }
        result.text(state.runResult || "No run yet.");
    }

    function sampleInputFromSchema(schema) {
        var sample = {};
        if (Array.isArray(schema)) {
            schema.forEach(function(field) {
                if (field && field.name) {
                    sample[field.name] = sampleValue(field);
                }
            });
            return sample;
        }

        if (!schema || typeof schema !== "object") {
            return sample;
        }

        Object.keys(schema).forEach(function(key) {
            sample[key] = sampleValue(schema[key]);
        });
        return sample;
    }

    function sampleValue(field) {
        if (field === null || field === undefined || typeof field !== "object") {
            return "";
        }
        if (field.default !== undefined) {
            return field.default;
        }
        if (field.value !== undefined && typeof field.value !== "object") {
            return field.value;
        }

        var datatype = String(field.datatype || field.type || "string").toLowerCase();
        if (datatype === "int" || datatype === "integer") {
            return 0;
        }
        if (datatype === "float" || datatype === "double" || datatype === "decimal" || datatype === "number") {
            return 0;
        }
        if (datatype === "bool" || datatype === "boolean") {
            return false;
        }
        if (datatype === "array" || datatype === "list") {
            return [];
        }
        if (datatype === "object") {
            return {};
        }
        return "";
    }

    function applyFlowField(field, value) {
        if (field === "namespace") {
            state.namespace = value.trim();
        } else if (field === "filename") {
            state.filename = normalizeFilename(value);
        } else if (field === "name") {
            state.workflow.name = value;
        } else if (field === "start_up_node") {
            state.workflow.start_up_node = value;
        }
        find("[data-current-workflow]").text(state.workflow.name || state.filename);
        find("[data-current-path]").text(currentPath());
        renderWorkflowList();
        renderJson();
    }

    function applyInputDataJson(textarea) {
        var parsed = parseJson(textarea.val());
        if (parsed.error) {
            textarea.addClass("is-invalid");
            setStatus("Input data JSON is invalid.", "error");
            return;
        }
        textarea.removeClass("is-invalid");
        state.workflow.inputData = parsed.value;
        renderJson();
        state.runInput = JSON.stringify(sampleInputFromSchema(state.workflow.inputData || {}), null, 2);
        renderRunPanel();
        setStatus("Input data updated.");
    }

    function applyJsonPath(path, textarea) {
        var parsed = parseJson(textarea.val());
        if (parsed.error) {
            textarea.addClass("is-invalid");
            setStatus("JSON is invalid.", "error");
            return;
        }

        textarea.removeClass("is-invalid");
        if (path === "__raw") {
            if (!parsed.value || typeof parsed.value !== "object" || !parsed.value.urntype) {
                setStatus("Raw node JSON must contain urntype.", "error");
                return;
            }
            state.workflow[state.selectedNodeId] = parsed.value;
        } else {
            setPath(state.workflow[state.selectedNodeId], path, parsed.value);
        }
        normalizeNodeShape(state.workflow[state.selectedNodeId]);
        renderAll();
        setStatus("Node JSON updated.", "success");
    }

    function parseJson(text) {
        try {
            return {
                value: JSON.parse(text)
            };
        } catch (error) {
            return {
                error: error
            };
        }
    }

    function setPath(obj, path, value) {
        var parts = path.split(".");
        var target = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            if (!target[parts[i]] || typeof target[parts[i]] !== "object") {
                target[parts[i]] = {};
            }
            target = target[parts[i]];
        }
        target[parts[parts.length - 1]] = value;
    }

    function changeNodeId(newId) {
        var oldId = state.selectedNodeId;
        newId = normalizeNodeId(newId);
        if (!oldId || oldId === newId) {
            renderInspector();
            return;
        }
        if (state.workflow[newId]) {
            setStatus("Node id already exists.", "error");
            renderInspector();
            return;
        }

        state.workflow[newId] = state.workflow[oldId];
        delete state.workflow[oldId];
        state.workflow.__designer.nodes[newId] = state.workflow.__designer.nodes[oldId] || {
            x: 120,
            y: 120
        };
        delete state.workflow.__designer.nodes[oldId];

        nodeKeys().forEach(function(key) {
            var node = state.workflow[key];
            if (node.success === oldId) {
                node.success = newId;
            }
            if (node.fail === oldId) {
                node.fail = newId;
            }
        });

        if (state.workflow.start_up_node === oldId) {
            state.workflow.start_up_node = newId;
        }
        state.selectedNodeId = newId;
        renderAll();
        setStatus("Node id updated.", "success");
    }

    function changeNodeType(type) {
        var node = state.workflow[state.selectedNodeId];
        node.urntype = type;
        if (type === "class") {
            node.file = node.file || "test.php";
            node.class = node.class || "test";
            node.method = node.method || {};
            node.method.name = node.method.name || "getMessage";
            delete node.appCode;
            delete node.componentCode;
        } else if (type === "service") {
            node.appCode = node.appCode || "";
            node.componentCode = node.componentCode || "";
            node.method = node.method || {};
            node.method.type = node.method.type || "post";
            delete node.file;
            delete node.class;
        } else if (type === "create_object") {
            node.method = node.method || {};
            node.method.type = "create_object";
            node.method.name = node.method.name || "BuildObject";
            node.variables = node.variables || [];
            delete node.file;
            delete node.class;
            delete node.appCode;
            delete node.componentCode;
        }
        normalizeNodeShape(node);
        renderAll();
        setStatus("Node type updated.", "success");
    }

    function addNodeFromTemplate(templateId, x, y) {
        var template = templateById(templateId);
        if (!template) {
            return;
        }

        var node = clone(template.node);
        normalizeNodeShape(node);
        var id = uniqueNodeId(template.label.toLowerCase());
        state.workflow[id] = node;
        state.workflow.__designer.nodes[id] = {
            x: Math.max(40, Math.round(x || 140)),
            y: Math.max(40, Math.round(y || 140))
        };

        if (!state.workflow.start_up_node || !state.workflow[state.workflow.start_up_node]) {
            state.workflow.start_up_node = id;
        }

        state.selectedNodeId = id;
        renderAll();
        setStatus("Node added: " + id + ".", "success");
    }

    function duplicateNode() {
        if (!state.selectedNodeId) {
            return;
        }

        var source = state.workflow[state.selectedNodeId];
        var pos = nodePosition(state.selectedNodeId);
        var id = uniqueNodeId(state.selectedNodeId + "-copy");
        state.workflow[id] = clone(source);
        state.workflow.__designer.nodes[id] = {
            x: pos.x + 34,
            y: pos.y + 34
        };
        delete state.workflow[id].success;
        delete state.workflow[id].fail;
        state.selectedNodeId = id;
        renderAll();
        setStatus("Node duplicated.", "success");
    }

    function templateById(templateId) {
        for (var i = 0; i < state.templates.length; i++) {
            if (state.templates[i].id === templateId) {
                return state.templates[i];
            }
        }
        return null;
    }

    function deleteSelectedNode() {
        var nodeId = state.selectedNodeId;
        if (!nodeId || !state.workflow[nodeId]) {
            return;
        }

        delete state.workflow[nodeId];
        if (state.workflow.__designer && state.workflow.__designer.nodes) {
            delete state.workflow.__designer.nodes[nodeId];
        }

        nodeKeys().forEach(function(key) {
            var node = state.workflow[key];
            if (node.success === nodeId) {
                delete node.success;
            }
            if (node.fail === nodeId) {
                delete node.fail;
            }
        });

        var keys = nodeKeys();
        if (state.workflow.start_up_node === nodeId) {
            state.workflow.start_up_node = keys.length ? keys[0] : "";
        }
        state.selectedNodeId = keys.length ? keys[0] : "";
        renderAll();
        setStatus("Node deleted.", "success");
    }

    function cleanEmptyEdges() {
        nodeKeys().forEach(function(key) {
            var node = state.workflow[key];
            if (!node.success) {
                delete node.success;
            }
            if (!node.fail) {
                delete node.fail;
            }
        });
    }

    function startLink(sourceId, edgeType) {
        state.linking = {
            source: sourceId,
            edgeType: edgeType
        };
        state.selectedNodeId = sourceId;
        renderAll();
    }

    function completeLink(targetId) {
        if (!state.linking || !targetId || targetId === state.linking.source) {
            return false;
        }

        state.workflow[state.linking.source][state.linking.edgeType] = targetId;
        state.selectedNodeId = state.linking.source;
        state.linking = null;
        renderAll();
        setStatus("Link updated.", "success");
        return true;
    }

    function updateLinkState() {
        var text = "No link selected";
        if (state.linking) {
            text = state.linking.source + " " + state.linking.edgeType + " -> select target";
        }
        find("[data-link-state]").text(text);
    }

    function canvasPoint(event) {
        var surface = find("[data-surface]")[0];
        var rect = surface.getBoundingClientRect();
        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top
        };
    }

    function copyJson() {
        var text = JSON.stringify(state.workflow || {}, null, 2);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                setStatus("Workflow JSON copied.", "success");
            });
            return;
        }

        var temp = $("<textarea>").val(text).appendTo(root);
        temp[0].select();
        document.execCommand("copy");
        temp.remove();
        setStatus("Workflow JSON copied.", "success");
    }

    function useInputSchema() {
        state.runInput = JSON.stringify(sampleInputFromSchema(state.workflow.inputData || {}), null, 2);
        renderRunPanel();
        setStatus("Input JSON prepared from workflow inputData.", "success");
    }

    function runWorkflow() {
        if (!api) {
            setStatus("flow-designer-api is not loaded.", "error");
            return;
        }

        var textarea = find("[data-run-input-json]");
        var parsed = parseJson(textarea.val());
        if (parsed.error || !parsed.value || typeof parsed.value !== "object" || Array.isArray(parsed.value)) {
            textarea.addClass("is-invalid");
            setStatus("Test input must be a JSON object.", "error");
            return;
        }

        textarea.removeClass("is-invalid");
        state.runInput = JSON.stringify(parsed.value, null, 2);
        cleanEmptyEdges();
        setBusy(true);
        setStatus("Running " + currentPath() + "...");

        api.services.RunWorkflow({
            namespace: state.namespace,
            filename: state.filename,
            workflow: state.workflow,
            inputData: parsed.value
        })
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    state.runSuccess = false;
                    state.runResult = JSON.stringify(result, null, 2);
                    renderRunPanel();
                    setStatus(result.message || "Workflow test failed.", "error");
                    return;
                }

                state.runSuccess = result.runSuccess === true;
                state.runResult = JSON.stringify(state.runSuccess ? result.result : {
                    runSuccess: false,
                    error: result.error,
                    result: result.result
                }, null, 2);
                renderRunPanel();
                setStatus(state.runSuccess ? "Workflow test completed." : "Workflow test returned an error.", state.runSuccess ? "success" : "error");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                state.runSuccess = false;
                state.runResult = JSON.stringify(result, null, 2);
                renderRunPanel();
                setStatus(result.message || "Workflow test request failed.", "error");
            });
    }

    function copyRunResult() {
        var text = state.runResult || "";
        if (!text || text === "No run yet.") {
            setStatus("Run a workflow before copying the result.", "error");
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                setStatus("Run result copied.", "success");
            });
            return;
        }

        var temp = $("<textarea>").val(text).appendTo(root);
        temp[0].select();
        document.execCommand("copy");
        temp.remove();
        setStatus("Run result copied.", "success");
    }

    function toggleWindowMaximize() {
        setWindowMaximized(!state.windowMaximized);
    }

    function setWindowMaximized(isMaximized) {
        state.windowMaximized = !!isMaximized;
        root.toggleClass("is-window-maximized", state.windowMaximized);
        $("body").toggleClass("flow-designer-window-open", state.windowMaximized);

        find("[data-toggle-maximize]")
            .attr("aria-pressed", state.windowMaximized ? "true" : "false")
            .attr("aria-label", state.windowMaximized ? "Restore" : "Maximize")
            .attr("title", state.windowMaximized ? "Restore" : "Maximize");

        window.setTimeout(drawConnections, 0);
    }

    function bindEvents() {
        find("[data-new-workflow]").on("click", newWorkflow);
        find("[data-save-workflow]").on("click", saveWorkflow);
        find("[data-delete-workflow]").on("click", deleteWorkflow);
        find("[data-refresh-workflows]").on("click", refreshWorkflows);
        find("[data-copy-json]").on("click", copyJson);
        find("[data-run-workflow]").on("click", runWorkflow);
        find("[data-use-input-schema]").on("click", useInputSchema);
        find("[data-copy-run-result]").on("click", copyRunResult);
        find("[data-toggle-maximize]").on("click", toggleWindowMaximize);

        find("[data-run-input-json]").on("input", function() {
            state.runInput = $(this).val();
        });

        find("[data-flow-field]").on("input change", function() {
            applyFlowField($(this).data("flow-field"), $(this).val());
        });

        find("[data-flow-input-json]").on("blur", function() {
            applyInputDataJson($(this));
        });

        find("[data-workflow-list]").on("click", "[data-workflow-index]", function() {
            loadWorkflow(parseInt($(this).attr("data-workflow-index"), 10));
        });

        find("[data-template-search]").on("input", function() {
            state.templateSearch = $(this).val();
            renderTemplates();
        });

        find("[data-app-service-filter]").on("change", function() {
            state.appServiceFilter = $(this).val();
            renderTemplates();
        });

        find("[data-template-list]").on("dragstart", "[data-template-id]", function(event) {
            event.originalEvent.dataTransfer.setData("text/plain", $(this).attr("data-template-id"));
            event.originalEvent.dataTransfer.effectAllowed = "copy";
        });

        find("[data-template-list]").on("click", "[data-template-id]", function() {
            addNodeFromTemplate($(this).attr("data-template-id"), 160, 160);
        });

        find("[data-canvas]").on("dragover", function(event) {
            event.preventDefault();
            event.originalEvent.dataTransfer.dropEffect = "copy";
        });

        find("[data-canvas]").on("drop", function(event) {
            event.preventDefault();
            var templateId = event.originalEvent.dataTransfer.getData("text/plain");
            var point = canvasPoint(event.originalEvent);
            addNodeFromTemplate(templateId, point.x - nodeWidth / 2, point.y - nodeHeight / 2);
        });

        find("[data-node-layer]").on("click", "[data-node-id]", function(event) {
            var nodeId = $(this).attr("data-node-id");
            if ($(event.target).is("[data-edge-source]")) {
                return;
            }
            if (completeLink(nodeId)) {
                return;
            }
            state.selectedNodeId = nodeId;
            renderAll();
        });

        find("[data-node-layer]").on("click", "[data-edge-source]", function(event) {
            event.stopPropagation();
            var nodeId = $(this).closest("[data-node-id]").attr("data-node-id");
            startLink(nodeId, $(this).attr("data-edge-source"));
        });

        find("[data-node-layer]").on("mousedown", "[data-drag-handle]", function(event) {
            var nodeEl = $(this).closest("[data-node-id]");
            var nodeId = nodeEl.attr("data-node-id");
            var start = {
                x: event.clientX,
                y: event.clientY
            };
            var pos = clone(nodePosition(nodeId));
            state.selectedNodeId = nodeId;
            renderInspector();
            renderCanvas();

            $(document).on("mousemove.flowDesigner", function(moveEvent) {
                var next = nodePosition(nodeId);
                next.x = Math.max(20, pos.x + moveEvent.clientX - start.x);
                next.y = Math.max(20, pos.y + moveEvent.clientY - start.y);
                find('[data-node-id="' + cssEscape(nodeId) + '"]').css({
                    left: next.x + "px",
                    top: next.y + "px"
                });
                drawConnections();
                renderJson();
            });

            $(document).on("mouseup.flowDesigner", function() {
                $(document).off(".flowDesigner");
            });
        });

        find("[data-node-inspector]").on("change", "[data-node-id-input]", function() {
            changeNodeId($(this).val());
        });

        find("[data-node-inspector]").on("change", "[data-node-urntype]", function() {
            changeNodeType($(this).val());
        });

        find("[data-node-inspector]").on("input change", "[data-node-path]", function() {
            var node = state.workflow[state.selectedNodeId];
            setPath(node, $(this).data("node-path"), $(this).val());
            normalizeNodeShape(node);
            renderCanvas();
            renderJson();
        });

        find("[data-node-inspector]").on("change", "[data-node-checkbox]", function() {
            var node = state.workflow[state.selectedNodeId];
            setPath(node, $(this).data("node-checkbox"), $(this).is(":checked"));
            renderJson();
        });

        find("[data-node-inspector]").on("change", "[data-edge-select]", function() {
            var node = state.workflow[state.selectedNodeId];
            var edge = $(this).data("edge-select");
            var value = $(this).val();
            if (value) {
                node[edge] = value;
            } else {
                delete node[edge];
            }
            renderCanvas();
            renderJson();
        });

        find("[data-node-inspector]").on("click", "[data-apply-json]", function() {
            var path = $(this).data("apply-json");
            var textarea = find('[data-json-path="' + path + '"]');
            applyJsonPath(path, textarea);
        });

        find("[data-node-inspector]").on("click", "[data-delete-node]", deleteSelectedNode);
        find("[data-node-inspector]").on("click", "[data-duplicate-node]", duplicateNode);

        $(document).on("keydown", function(event) {
            if ($(event.target).is("input, textarea, select")) {
                return;
            }
            if (event.key === "Delete" || event.key === "Backspace") {
                deleteSelectedNode();
            }
            if (event.key === "Escape" && state.linking) {
                state.linking = null;
                renderAll();
                return;
            }
            if (event.key === "Escape" && state.windowMaximized) {
                setWindowMaximized(false);
            }
        });
    }

    function cssEscape(value) {
        if (window.CSS && window.CSS.escape) {
            return window.CSS.escape(value);
        }
        return String(value).replace(/"/g, '\\"');
    }

    exports.onReady = function(element) {
        root = element && element.jquery ? element : $(element);
        api = exports.getComponent("flow-designer-api");
        setWorkflow(blankWorkflow(), "new-flow", "");
        bindEvents();
        setWindowMaximized(false);
        loadDesignerData();
    };
});
