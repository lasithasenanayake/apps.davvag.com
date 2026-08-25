WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var profileCropper;
    var profileUploader;
    var pendingProfileFile;
    var state = {
        provider: "openai",
        providerDrafts: {},
        modelFilter: "",
        testAttachments: [],
        testSessionId: "creator-console-" + Date.now().toString(36) + "-" + Math.random().toString(36).slice(2, 10),
        format: "json",
        agents: [],
        selectedAgentCode: "",
        skillBuilder: {
            open: false,
            skills: [],
            selectedIndex: -1,
            lastSource: "textarea"
        },
        output: {
            json: "{}",
            yaml: ""
        }
    };

    // Populated exclusively by creator-api/Providers; the browser keeps no model catalog.
    var providers = {};

    function find(selector) {
        return root.find(selector);
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
        var status = find("[data-creator-status]");
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function setBusy(isBusy) {
        find("button, input, textarea, select").prop("disabled", isBusy);
        if (!isBusy) {
            find("[data-streaming]").prop("disabled", true).prop("checked", false);
            find('[data-modality][data-supported="false"]').prop("disabled", true).prop("checked", false);
            var model = selectedModel();
            if (model && $.inArray("temperature", model.supportedParameters || []) === -1) find("[data-temperature]").prop("disabled", true);
        }
    }

    function profileImageUrl(profileId) {
        return profileId > 0 ? "components/dock/soss-uploader/service/get/profile/" + profileId : "";
    }

    function setProfileImage(src) {
        var image = find("[data-profile-image]");
        var fallback = find("[data-profile-fallback]");
        if (src) {
            image.attr("src", src).show();
            fallback.hide();
        } else {
            image.removeAttr("src").hide();
            fallback.show();
        }
    }

    function agentIdentity(agent) {
        var config = agent && agent.configuration ? agent.configuration : {};
        var configAgent = config.agent || {};
        var profile = configAgent.profile || {};
        var user = configAgent.user || config.systemUser || {};
        return {
            profileId: parseInt(agent && agent.profileId ? agent.profileId : (profile.profileId || configAgent.profileId || 0), 10) || 0,
            userId: agent && agent.userId ? agent.userId : (user.userid || configAgent.userId || ""),
            email: profile.email || user.email || "",
            phone: profile.phone || "",
            image: profile.image || configAgent.profileImage || ""
        };
    }

    function applyAgentIdentity(agent) {
        var identity = agentIdentity(agent || {});
        find("[data-profile-id]").val(identity.profileId || "");
        find("[data-user-id]").val(identity.userId || "");
        find("[data-agent-email]").val(identity.email || "");
        find("[data-agent-phone]").val(identity.phone || "");
        setProfileImage(identity.image || profileImageUrl(identity.profileId));
    }

    function providerFields(provider) {
        if (provider === "ollama") return ["endpoint", "cliCommand"];
        if (provider === "lmstudio") return ["endpoint"];
        if (provider === "other") return ["apiKey", "endpoint", "customMethod", "authHeader"];
        return ["apiKey"];
    }

    function connectionDraft() {
        return { apiKey: find("[data-api-key]").val(), endpoint: find("[data-endpoint]").val(), cliCommand: find("[data-cli-command]").val(), customMethod: find("[data-custom-method]").val(), authHeader: find("[data-auth-header]").val() };
    }

    function applyConnectionDraft(meta) {
        var draft = state.providerDrafts[state.provider] || {};
        find("[data-api-key]").val(draft.apiKey || "");
        find("[data-endpoint]").val(draft.endpoint || meta.defaultEndpoint || "");
        find("[data-cli-command]").val(draft.cliCommand || "");
        find("[data-custom-method]").val(draft.customMethod || "POST");
        find("[data-auth-header]").val(draft.authHeader || "");
    }

    function renderProviderButtons() {
        var container = find("[data-provider-options]").empty();
        $.each(providers, function(code, meta) {
            container.append($("<button>").attr("type", "button").attr("data-provider", code).addClass("agent-creator__provider").text(meta.label || code));
        });
    }

    function modelList() {
        var meta = providers[state.provider] || {};
        return $.isArray(meta.fallbackModels) ? meta.fallbackModels : [];
    }

    function selectedModel() {
        var id = find("[data-model]").val();
        if (id === "__custom__") id = $.trim(find("[data-custom-model]").val() || "");
        var list = modelList();
        for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i];
        if (id) {
            var custom = { id: id, name: id, lifecycle: "custom", inputModalities: ["text"], outputModalities: ["text"], supportedParameters: ["temperature", "maxTokens"], pricing: { status: "unknown" } };
            if (state.provider === "other") {
                try { $.extend(true, custom, JSON.parse(find("[data-custom-model-metadata]").val() || "{}")); } catch (ignore) {}
                custom.inputModalities = ["text"]; custom.outputModalities = ["text"];
            }
            return custom;
        }
        return null;
    }

    function selectedModelId() {
        return find("[data-model]").val() === "__custom__" ? $.trim(find("[data-custom-model]").val() || "") : (find("[data-model]").val() || "");
    }

    function renderModels(preferredId) {
        var select = find("[data-model]").empty();
        var filter = $.trim(state.modelFilter.toLowerCase());
        $.each(modelList(), function(_, model) {
            var haystack = [model.name, model.id, model.description, model.recommendedUse, (model.inputModalities || []).join(" ")].join(" ").toLowerCase();
            if (filter && haystack.indexOf(filter) === -1) return;
            var label = (model.name || model.id) + " — " + model.id + (model.recommendedUse ? " [" + model.recommendedUse + "]" : "");
            select.append($("<option>").attr("value", model.id).text(label));
        });
        select.append($("<option>").attr("value", "__custom__").text("Custom model ID (advanced)"));
        if (preferredId && select.find('option[value="' + preferredId.replace(/"/g, "") + '"]').length) select.val(preferredId);
        else select.prop("selectedIndex", 0);
        updateModelExperience();
    }

    function renderModalities(model) {
        $.each({ input: "[data-input-modalities]", output: "[data-output-modalities]" }, function(direction, selector) {
            var supported = model && $.isArray(model[direction + "Modalities"]) ? model[direction + "Modalities"] : ["text"];
            var box = find(selector).empty();
            $.each(["text", "image", "audio", "video", "document"], function(_, modality) {
                var enabled = $.inArray(modality, supported) !== -1;
                var input = $("<input>").attr({ type: "checkbox", "data-modality": direction, "data-supported": enabled ? "true" : "false", value: modality }).prop("disabled", !enabled).prop("checked", enabled && modality === "text");
                var label = $("<label>").addClass("agent-creator__modality").toggleClass("is-disabled", !enabled).append(input).append($("<span>").text(modality + (enabled ? "" : " — unsupported")));
                box.append(label);
            });
        });
    }

    function renderModelInfo(model) {
        var panel = find("[data-model-info]").empty();
        if (!model) { panel.text("Select a model to see capabilities, limits, and pricing."); return; }
        var pricing = model.pricing || {};
        panel.append($("<strong>").text((model.name || model.id) + " · " + (model.lifecycle || "unknown")));
        panel.append($("<p>").text(model.description || "No catalog description."));
        panel.append($("<p>").text("Input: " + (model.inputModalities || ["text"]).join(", ") + " · Output: " + (model.outputModalities || ["text"]).join(", ")));
        panel.append($("<p>").text("Context: " + (model.contextWindow ? Number(model.contextWindow).toLocaleString() + " tokens" : "provider/model dependent") + " · Max output: " + (model.maxOutputTokens ? Number(model.maxOutputTokens).toLocaleString() : "unknown")));
        if (pricing.status === "local") panel.append($("<p>").text("No per-token provider API fee. Local hardware, hosting, and electricity costs are not included."));
        else if (pricing.inputPerMillionTokens != null && pricing.outputPerMillionTokens != null) panel.append($("<p>").text("USD $" + pricing.inputPerMillionTokens + " input / $" + pricing.outputPerMillionTokens + " output per 1M tokens" + (pricing.cachedInputPerMillionTokens != null ? " · $" + pricing.cachedInputPerMillionTokens + " cached input" : "")));
        else panel.append($("<p>").text("Pricing unavailable; configure and verify custom pricing outside this catalog."));
        if (pricing.officialUrl) panel.append($("<a>").attr({ href: pricing.officialUrl, target: "_blank", rel: "noopener noreferrer" }).text("Official pricing · verified " + (pricing.lastVerified || "unknown")));
    }

    function updateModelExperience() {
        var custom = find("[data-model]").val() === "__custom__";
        find("[data-custom-model-field]").toggleClass("is-hidden", !custom);
        var model = selectedModel();
        renderModelInfo(model); renderModalities(model);
        var limit = model && model.maxOutputTokens ? model.maxOutputTokens : 200000;
        find("[data-max-tokens]").attr("max", limit).val(Math.min(parseInt(find("[data-max-tokens]").val(), 10) || 2048, limit));
        var supportsTemperature = !model || $.inArray("temperature", model.supportedParameters || []) !== -1;
        find("[data-temperature]").prop("disabled", !supportsTemperature);
        calculateEstimate();
    }

    function rateToPico(value) {
        var parts = String(value == null ? "0" : value).split(".");
        return BigInt(parts[0] || "0") * 1000000n + BigInt(((parts[1] || "") + "000000").slice(0, 6));
    }

    function calculateEstimate() {
        var model = selectedModel(), pricing = model && model.pricing ? model.pricing : {};
        if (pricing.status === "local") { find("[data-cost-estimate]").text("Estimated provider API fee: USD $0. Local operating costs are excluded."); return; }
        if (pricing.inputPerMillionTokens == null || pricing.outputPerMillionTokens == null) { find("[data-cost-estimate]").text("Pricing unavailable."); return; }
        var input = BigInt(Math.max(0, parseInt(find("[data-estimate-input]").val(), 10) || 0));
        var cached = BigInt(Math.max(0, parseInt(find("[data-estimate-cached]").val(), 10) || 0)); if (cached > input) cached = input;
        var output = BigInt(Math.max(0, parseInt(find("[data-estimate-output]").val(), 10) || 0));
        var inputRate = rateToPico(pricing.inputPerMillionTokens), cachedRate = pricing.cachedInputPerMillionTokens == null ? inputRate : rateToPico(pricing.cachedInputPerMillionTokens), outputRate = rateToPico(pricing.outputPerMillionTokens);
        var pico = (input - cached) * inputRate + cached * cachedRate + output * outputRate;
        var whole = pico / 1000000000000n, fraction = String(pico % 1000000000000n).padStart(12, "0").replace(/0+$/, "");
        find("[data-cost-estimate]").text("Estimated cost: USD $" + whole + (fraction ? "." + fraction : "") + " = uncached input + cached input + output. Modality/tool fees not listed by the selected catalog entry are excluded.");
    }

    function setProvider(provider, options) {
        if (!providers[provider]) return;
        if ((!options || !options.skipDraftSave) && providers[state.provider]) state.providerDrafts[state.provider] = connectionDraft();
        state.provider = provider; var meta = providers[provider];
        find("[data-provider]").removeClass("is-active").attr("aria-pressed", "false");
        find('[data-provider="' + provider + '"]').addClass("is-active").attr("aria-pressed", "true");
        find("[data-connection-method]").text(meta.connectionMethod || "Provider API");
        find("[data-field]").addClass("is-hidden");
        $.each(providerFields(provider), function(_, field) { find('[data-field="' + field + '"]').removeClass("is-hidden"); });
        applyConnectionDraft(meta); state.modelFilter = ""; find("[data-model-filter]").val(""); renderModels();
        setStatus((meta.notes || ("Provider mapped to " + meta.connectionMethod + ".")), "muted");
    }

    function collectForm() {
        var inputModalities = [], outputModalities = [];
        find('[data-modality="input"]:checked').each(function() { inputModalities.push($(this).val()); });
        find('[data-modality="output"]:checked').each(function() { outputModalities.push($(this).val()); });
        return {
            agentCode: find("[data-agent-code]").val(),
            agentName: find("[data-agent-name]").val(),
            agentProfileName: find("[data-agent-name]").val(),
            agentEmail: find("[data-agent-email]").val(),
            agentPhone: find("[data-agent-phone]").val(),
            profileId: find("[data-profile-id]").val(),
            userId: find("[data-user-id]").val(),
            description: find("[data-agent-description]").val(),
            capabilities: find("[data-agent-capabilities]").val(),
            skills: find("[data-agent-skills]").val(),
            provider: state.provider,
            model: selectedModelId(),
            customModelMetadata: find("[data-custom-model-metadata]").val(),
            modalities: { input: inputModalities, output: outputModalities },
            apiKey: find("[data-api-key]").val(),
            endpoint: find("[data-endpoint]").val(),
            cliCommand: find("[data-cli-command]").val(),
            customMethod: find("[data-custom-method]").val(),
            authHeader: find("[data-auth-header]").val(),
            systemPrompt: find("[data-system-prompt]").val(),
            temperature: find("[data-temperature]").val(),
            maxTokens: find("[data-max-tokens]").val(),
            streaming: false
        };
    }

    function renderOutput() {
        var text = state.format === "yaml" ? state.output.yaml : state.output.json;
        find("[data-output]").text(text || "");
    }

    function renderAgents() {
        var list = find("[data-agent-list]");
        var select = find("[data-test-agent]");
        list.empty();
        select.empty().append($("<option>").attr("value", "").text("Select an agent"));

        find("[data-agent-count]").text(state.agents.length ? state.agents.length + " saved" : "No agents saved");

        if (!state.agents.length) {
            list.append($("<div>").addClass("agent-creator__empty").text("Saved agents will appear here."));
            find("[data-selected-agent]").text("No agent selected");
            return;
        }

        for (var i = 0; i < state.agents.length; i++) {
            var agent = state.agents[i];
            select.append($("<option>").attr("value", agent.agentCode).text(agent.name + " (" + agent.agentCode + ")"));

            var item = $("<button>").attr("type", "button").addClass("agent-creator__agent");
            if (agent.agentCode === state.selectedAgentCode) {
                item.addClass("is-active");
            }
            item.attr("data-agent-item", agent.agentCode);
            item.append($("<strong>").text(agent.name));
            item.append($("<span>").text(agent.agentCode));
            item.append($("<p>").text(agent.description || ""));
            item.append($("<small>").text((agent.capabilities || []).join(" | ")));
            if (agent.skills && agent.skills.length) {
                item.append($("<small>").text(agent.skills.length + " runtime skills"));
            }
            list.append(item);
        }

        select.val(state.selectedAgentCode);
        updateSelectedAgentLabel();
    }

    function updateSelectedAgentLabel() {
        var agent = getAgent(state.selectedAgentCode);
        find("[data-selected-agent]").text(agent ? agent.name + " selected" : "No agent selected");
    }

    function getAgent(agentCode) {
        for (var i = 0; i < state.agents.length; i++) {
            if (state.agents[i].agentCode === agentCode) {
                return state.agents[i];
            }
        }
        return null;
    }

    function loadProviders() {
        if (!api) return;
        api.services.Providers().then(function(response) {
            var result = serviceResult(response);
            if (result.success === false || !result.providers) {
                setStatus(result.message || "Provider catalog is unavailable.", "error"); return;
            }
            providers = result.providers; renderProviderButtons();
            var first = providers.openai ? "openai" : Object.keys(providers)[0];
            if (first) setProvider(first, { skipDraftSave: true });
        }).error(function(response) {
            var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Provider catalog is unavailable; creation is disabled.", "error");
        });
    }

    function discoverModels() {
        if (!api || !api.services.DiscoverModels || !providers[state.provider]) return;
        setBusy(true); setStatus("Refreshing models from the provider…", "muted");
        api.services.DiscoverModels({ provider: state.provider, apiKey: find("[data-api-key]").val(), endpoint: find("[data-endpoint]").val() }).then(function(response) {
            var result = serviceResult(response); setBusy(false);
            if (result.success === false) { setStatus(result.message || "Model discovery failed; curated models remain available.", "error"); return; }
            providers[state.provider].fallbackModels = result.models || providers[state.provider].fallbackModels;
            renderModels(selectedModelId());
            setStatus(result.warning || "Available generation models refreshed and merged with the curated catalog.", result.discoverySuccess === false ? "muted" : "success");
        }).error(function(response) {
            setBusy(false); var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
            setStatus(result.message || "Model discovery failed; curated models remain available.", "error");
        });
    }

    function loadAgents() {
        if (!api) {
            return;
        }

        api.services.ListAgents()
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load saved agents.", "error");
                    return;
                }
                state.agents = result.agents || [];
                renderAgents();
            })
            .error(function(response) {
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Unable to load saved agents.", "error");
            });
    }

    function generateConfig(event) {
        event.preventDefault();
        if (!api) {
            setStatus("The creator-api service is not loaded.", "error");
            return;
        }

        if (state.skillBuilder.open && !applySkillEditor({ showStatus: false })) {
            setStatus("Fix the skill builder JSON before generating config.", "error");
            return;
        }

        setBusy(true);
        setStatus("Validating provider settings...", "muted");

        api.services.GenerateConfig(collectForm())
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    setStatus(result.message || "Configuration validation failed.", "error");
                    return;
                }

                state.output.json = result.json || "{}";
                state.output.yaml = result.yaml || "";
                renderOutput();
                setStatus("Configuration generated and initialized with the startup prompt.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Configuration generation failed.", "error");
            });
    }

    function saveAgent() {
        if (!api) {
            setStatus("The creator-api service is not loaded.", "error");
            return;
        }

        if (state.skillBuilder.open && !applySkillEditor({ showStatus: false })) {
            setStatus("Fix the skill builder JSON before saving the agent.", "error");
            return;
        }

        setBusy(true);
        setStatus("Saving agent...", "muted");

        api.services.SaveAgent(collectForm())
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setBusy(false);
                    setStatus(result.message || "Agent was not saved.", "error");
                    return;
                }

                state.agents = result.agents || [];
                state.selectedAgentCode = result.agent ? result.agent.agentCode : find("[data-agent-code]").val();
                applyAgentIdentity(result.agent || {});
                if (result.agent && result.agent.configuration) {
                    state.output.json = JSON.stringify(result.agent.configuration, null, 2);
                    state.output.yaml = "";
                    state.format = "json";
                    find("[data-format]").removeClass("is-active");
                    find('[data-format="json"]').addClass("is-active");
                    renderOutput();
                }
                renderAgents();

                var identity = agentIdentity(result.agent || {});
                if (pendingProfileFile && identity.profileId > 0) {
                    setStatus("Uploading profile icon...", "muted");
                    uploadProfileIcon(identity.profileId, function() {
                        setBusy(false);
                        setStatus("Agent saved with profile and sysuser mapping. Workflows can call creator-api/TestAgent with agentCode \"" + state.selectedAgentCode + "\".", "success");
                    });
                    return;
                }

                setBusy(false);
                setStatus("Agent saved with profile and sysuser mapping. Workflows can call creator-api/TestAgent with agentCode \"" + state.selectedAgentCode + "\".", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Agent was not saved.", "error");
            });
    }

    function selectAgent(agentCode) {
        var agent = getAgent(agentCode);
        state.selectedAgentCode = agentCode || "";

        if (agent && agent.configuration) {
            fillFormFromAgent(agent);
            state.output.json = JSON.stringify(agent.configuration, null, 2);
            state.output.yaml = "";
            state.format = "json";
            find("[data-format]").removeClass("is-active");
            find('[data-format="json"]').addClass("is-active");
            renderOutput();
        }

        renderAgents();
        updateTestAgentInfo();
    }

    function fillFormFromAgent(agent) {
        var config = agent && agent.configuration ? agent.configuration : {};
        var provider = config.provider || {};
        var agentConfig = config.agent || {};
        var parameters = config.parameters || {};
        var connection = config.connection || {};
        var modalities = config.modalities || { input: ["text"], output: ["text"] };
        find("[data-agent-code]").val(agent.agentCode);
        find("[data-agent-name]").val(agent.name);
        find("[data-agent-description]").val(agent.description || "");
        find("[data-agent-capabilities]").val((agent.capabilities || []).join("\n"));
        find("[data-agent-skills]").val(JSON.stringify(agent.skills || config.skills || [], null, 2));
        applyAgentIdentity(agent);

        if (providers[provider.type || "openai"]) setProvider(provider.type || "openai", { skipDraftSave: true });
        var known = false;
        find("[data-model] option").each(function() { if ($(this).val() === provider.model) known = true; });
        if (known) find("[data-model]").val(provider.model);
        else { find("[data-model]").val("__custom__"); find("[data-custom-model]").val(provider.model || ""); }
        updateModelExperience();
        $.each(["input", "output"], function(_, direction) {
            find('[data-modality="' + direction + '"]').prop("checked", false);
            $.each(modalities[direction] || ["text"], function(__, modality) { find('[data-modality="' + direction + '"][value="' + modality + '"]:not(:disabled)').prop("checked", true); });
        });
        find("[data-api-key]").val("");
        find("[data-system-prompt]").val(agentConfig.startupPrompt || "");
        find("[data-temperature]").val(parameters.temperature == null ? 0.7 : parameters.temperature);
        find("[data-temperature-value]").text(parameters.temperature == null ? 0.7 : parameters.temperature);
        find("[data-max-tokens]").val(parameters.maxTokens || 2048);
        find("[data-streaming]").prop("checked", false);

        if (connection.endpoint) {
            find("[data-endpoint]").val(connection.endpoint);
        }
        if (connection.runtime && connection.runtime.cliCommand) {
            find("[data-cli-command]").val(connection.runtime.cliCommand);
        }
        if (connection.httpMethod) {
            find("[data-custom-method]").val(connection.httpMethod);
        }
        if (connection.auth && connection.auth.header && connection.auth.header !== "********") {
            find("[data-auth-header]").val(connection.auth.header);
        }
    }

    function agentInputModalities(agent) {
        var config = agent && agent.configuration ? agent.configuration : {};
        return config.modalities && $.isArray(config.modalities.input) ? config.modalities.input : ["text"];
    }

    function updateTestAgentInfo() {
        var agent = getAgent(find("[data-test-agent]").val());
        if (!agent) { find("[data-test-agent-info]").text("Select an agent to see its model and enabled modalities."); return; }
        var config = agent.configuration || {}, provider = config.provider || {}, modalities = config.modalities || { input: ["text"], output: ["text"] };
        find("[data-test-agent-info]").text((provider.type || "unknown") + " / " + (provider.model || "unknown") + " · input: " + (modalities.input || ["text"]).join(", ") + " · output: " + (modalities.output || ["text"]).join(", "));
        state.testAttachments = $.grep(state.testAttachments, function(item) { return $.inArray(item.type, modalities.input || ["text"]) !== -1; });
        renderTestAttachments();
    }

    function typeFromMime(mime) {
        if (mime.indexOf("image/") === 0) return "image";
        if (mime.indexOf("audio/") === 0) return "audio";
        if (mime.indexOf("video/") === 0) return "video";
        return "document";
    }

    function renderTestAttachments() {
        var box = find("[data-test-attachments]").empty();
        if (!state.testAttachments.length) { box.append($("<span>").addClass("agent-creator__empty").text("No attachments selected.")); return; }
        $.each(state.testAttachments, function(index, item) {
            var card = $("<div>").addClass("agent-creator__attachment");
            if (item.type === "image") card.append($("<img>").attr({ src: item.url, alt: "Preview of " + item.name }));
            else if (item.type === "audio") card.append($("<audio>").attr("controls", true).attr("src", item.url));
            else if (item.type === "video") card.append($("<video>").attr("controls", true).attr("src", item.url));
            card.append($("<span>").text(item.name + " · " + item.type + " · " + Math.ceil(item.size / 1024) + " KB"));
            card.append($("<button>").attr({ type: "button", "data-remove-attachment": index }).addClass("agent-creator__button").text("Remove"));
            box.append(card);
        });
    }

    function addTestFiles(files) {
        var agent = getAgent(find("[data-test-agent]").val()), supported = agentInputModalities(agent), total = 0;
        var allowedMimes = ["image/jpeg", "image/png", "image/webp", "image/gif", "audio/mpeg", "audio/wav", "audio/x-wav", "audio/ogg", "audio/mp4", "audio/webm", "video/mp4", "video/webm", "video/quicktime", "application/pdf", "text/plain", "text/csv", "application/json", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"];
        $.each(state.testAttachments, function(_, item) { total += item.size; });
        $.each(Array.prototype.slice.call(files || []), function(_, file) {
            var type = typeFromMime(file.type || "application/octet-stream");
            if ($.inArray(file.type, allowedMimes) === -1) { setStatus((file.name || "File") + " has an unsupported MIME type.", "error"); return; }
            if ($.inArray(type, supported) === -1) { setStatus(type + " input is not enabled for the selected agent.", "error"); return; }
            if (state.testAttachments.length >= 8 || file.size > 10485760 || total + file.size > 20971520) { setStatus("Attachment limits are 8 files, 10 MB each, and 20 MB total.", "error"); return; }
            total += file.size;
            var reader = new FileReader();
            reader.onload = function(event) { state.testAttachments.push({ type: type, url: event.target.result, mimeType: file.type, name: file.name, size: file.size }); renderTestAttachments(); };
            reader.onerror = function() { setStatus("Could not read " + file.name + ".", "error"); };
            reader.readAsDataURL(file);
        });
        find("[data-test-files]").val("");
    }

    function renderRuntimeOutputs(outputs) {
        var box = find("[data-test-outputs]").empty();
        $.each(outputs || [], function(index, item) {
            var link = $("<a>").attr({ href: item.url, target: "_blank", rel: "noopener noreferrer" }).text("Open " + (item.type || "output") + " " + (index + 1));
            box.append($("<div>").addClass("agent-creator__attachment").append(link).append($("<span>").text(item.mimeType || "")));
        });
    }

    function testAgent(event) {
        event.preventDefault();
        var agentCode = find("[data-test-agent]").val();
        var message = find("[data-test-message]").val();

        if (!agentCode) {
            setStatus("Select a saved agent before testing.", "error");
            return;
        }
        if (!$.trim(message) && !state.testAttachments.length) { setStatus("Enter a message or add an attachment.", "error"); return; }

        setBusy(true);
        find("[data-test-response]").text("Waiting for response...");

        api.services.TestAgent({
            agentCode: agentCode,
            message: message,
            content: state.testAttachments,
            sessionId: state.testSessionId,
            profileId: "creator-console"
        })
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    find("[data-test-response]").text(result.message || "Agent test failed.");
                    setStatus(result.message || "Agent test failed.", "error");
                    return;
                }

                find("[data-test-response]").text(JSON.stringify({
                    reply: result.reply || "",
                    outputs: result.outputs || [],
                    session: result.session || null,
                    usage: result.usage || null,
                    billingUsageId: result.billingUsageId || "",
                    skillResults: result.skillResults || []
                }, null, 2));
                renderRuntimeOutputs(result.outputs || []);
                setStatus("Agent response received from " + result.provider + " / " + result.model + ".", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                find("[data-test-response]").text(result.message || "Agent test failed.");
                setStatus(result.message || "Agent test failed.", "error");
            });
    }

    function deleteSelectedAgent() {
        var agentCode = find("[data-test-agent]").val() || state.selectedAgentCode;
        if (!agentCode) {
            setStatus("Select a saved agent to delete.", "error");
            return;
        }
        if (!confirm("Delete saved agent " + agentCode + "?")) {
            return;
        }

        setBusy(true);
        api.services.DeleteAgent({ agentCode: agentCode })
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    setStatus(result.message || "Agent was not deleted.", "error");
                    return;
                }

                state.agents = result.agents || [];
                state.selectedAgentCode = "";
                renderAgents();
                setStatus("Agent deleted.", "success");
            })
            .error(function(response) {
                setBusy(false);
                var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
                setStatus(result.message || "Agent was not deleted.", "error");
            });
    }

    function initializeProfileTools() {
        exports.getAppComponent("davvag-tools", "davvag-img-cropper", function(cropper) {
            cropper.initialize(300, 300);
            profileCropper = cropper;
        });
    }

    function chooseProfileIcon() {
        if (!profileCropper) {
            initializeProfileTools();
            setStatus("Profile image cropper is still loading.", "muted");
            return;
        }

        profileCropper.crope(1, 1, function(result) {
            if (!result || !result.fileData) {
                return;
            }
            pendingProfileFile = result.fileData;
            setProfileImage(result.data || "");
            setStatus("Profile icon selected. Save agent to upload it.", "muted");
        });
    }

    function uploadProfileIcon(profileId, callback) {
        var runUpload = function(uploader) {
            uploader.initialize();
            pendingProfileFile.name = profileId;
            uploader.upload([pendingProfileFile], "profile", null, function() {
                pendingProfileFile = null;
                setProfileImage(profileImageUrl(profileId) + "?v=" + new Date().getTime());
                if (typeof uploader.close === "function") {
                    uploader.close();
                }
                callback();
            });
        };

        if (profileUploader) {
            runUpload(profileUploader);
            return;
        }

        exports.getAppComponent("davvag-tools", "davvag-file-uploader", function(uploader) {
            profileUploader = uploader;
            runUpload(profileUploader);
        });
    }

    function resetForm() {
        find("[data-creator-form]")[0].reset();
        find("[data-temperature-value]").text("0.7");
        state.selectedAgentCode = "";
        state.skillBuilder.skills = [];
        state.skillBuilder.selectedIndex = -1;
        pendingProfileFile = null;
        setProfileImage("");
        state.output = { json: "{}", yaml: "" };
        renderOutput();
        showSkillBuilder(false);
        syncSkillBuilderTextarea();
        state.providerDrafts = {};
        state.testAttachments = [];
        renderTestAttachments();
        if (providers.openai) setProvider("openai", { skipDraftSave: true });
        renderAgents();
    }

    function copyOutput() {
        var text = state.format === "yaml" ? state.output.yaml : state.output.json;
        if (!text || text === "{}") {
            setStatus("Generate or save a configuration before copying.", "error");
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                setStatus("Configuration copied.", "success");
            });
            return;
        }

        var temp = $("<textarea>").val(text).appendTo(root);
        temp[0].select();
        document.execCommand("copy");
        temp.remove();
        setStatus("Configuration copied.", "success");
    }

    function skillBuilderField() {
        return find("[data-agent-skills]");
    }

    function skillBuilderModal() {
        return find("[data-skill-modal]");
    }

    function skillBuilderList() {
        return find("[data-skill-list]");
    }

    function skillBuilderStatus() {
        return find("[data-skill-status]");
    }

    function skillBuilderPreview() {
        return find("[data-skill-preview]");
    }

    function skillBuilderSelected() {
        if (state.skillBuilder.selectedIndex < 0) {
            return null;
        }
        return state.skillBuilder.skills[state.skillBuilder.selectedIndex] || null;
    }

    function deepClone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function skillTemplate(type) {
        if (type === "service_call") {
            return {
                code: "create-order",
                name: "Create order",
                type: "service_call",
                enabled: true,
                runMode: "triggered",
                description: "Create an order when the customer asks to buy or place an order.",
                triggerKeywords: ["order", "buy", "purchase"],
                method: "POST",
                url: "http://localhost/git/davvag-core/components/sales/order-api/service/CreateOrder",
                headers: {
                    "Content-Type": "application/json"
                },
                bodyTemplate: {
                    profileId: "{{profile.profileId}}",
                    customerRef: "{{profile.externalId}}",
                    sessionId: "{{session.sessionId}}",
                    message: "{{message}}"
                },
                timeoutSeconds: 20
            };
        }

        return {
            code: "lookup-products",
            name: "Lookup products",
            type: "data_query",
            enabled: true,
            runMode: "triggered",
            description: "Search tenant JSON data for products, orders, invoices, or stock records.",
            triggerKeywords: ["product", "price", "stock"],
            source: "json_file",
            dataFile: "products.json",
            queryFields: ["name", "sku", "description"],
            limit: 5,
            data: []
        };
    }

    function insertSkillTemplate(type) {
        if (state.skillBuilder.open && state.skillBuilder.selectedIndex >= 0 && !applySkillEditor({ showStatus: false })) {
            return;
        }

        var parsed = parseSkillArray(skillBuilderField().val());
        if (!parsed.success) {
            setStatus(parsed.message, "error");
            return;
        }

        parsed.skills.push(normalizeSkillForEditor(defaultSkill(type)));
        skillBuilderField().val(JSON.stringify(parsed.skills, null, 2));

        if (state.skillBuilder.open) {
            state.skillBuilder.skills = $.map(parsed.skills, function(item) {
                return normalizeSkillForEditor(item);
            });
            state.skillBuilder.selectedIndex = state.skillBuilder.skills.length - 1;
            renderSkillBuilderList();
            renderSkillEditor();
            setSkillBuilderStatus("Skill template added to the visual builder.", "success");
            return;
        }

        setStatus("Skill template added to the skills JSON field.", "success");
    }

    function defaultSkill(type) {
        return deepClone(skillTemplate(type));
    }

    function parseSkillArray(raw) {
        var text = $.trim(raw || "");
        if (!text) {
            return {
                success: true,
                skills: []
            };
        }

        try {
            var decoded = JSON.parse(text);
            if (!$.isArray(decoded)) {
                decoded = [decoded];
            }
            return {
                success: true,
                skills: decoded
            };
        } catch (error) {
            return {
                success: false,
                message: "Skills JSON must be a valid JSON array."
            };
        }
    }

    function normalizeListValue(value) {
        if ($.isArray(value)) {
            return value;
        }
        if (value === null || value === undefined || value === "") {
            return [];
        }
        return String(value).split(/[\r\n,]+/);
    }

    function listFromField(selector) {
        var value = $.trim(find(selector).val() || "");
        var values = normalizeListValue(value);
        var out = [];
        for (var i = 0; i < values.length; i++) {
            var item = $.trim(String(values[i] || ""));
            if (item) {
                out.push(item);
            }
        }
        return out;
    }

    function jsonFromField(selector, fallback) {
        var value = $.trim(find(selector).val() || "");
        if (!value) {
            return fallback;
        }
        return JSON.parse(value);
    }

    function uniqueSkillCode(base, excludeIndex) {
        var raw = $.trim(String(base || ""));
        if (!raw) {
            raw = "skill";
        }
        var code = raw.replace(/[^a-zA-Z0-9_-]+/g, "-").replace(/^-+|-+$/g, "").toLowerCase();
        if (!code) {
            code = "skill";
        }

        var existing = {};
        for (var i = 0; i < state.skillBuilder.skills.length; i++) {
            if (i === excludeIndex) {
                continue;
            }
            var current = state.skillBuilder.skills[i];
            if (current && current.code) {
                existing[current.code] = true;
            }
        }

        if (!existing[code]) {
            return code;
        }

        var index = 2;
        while (existing[code + "-" + index]) {
            index++;
        }
        return code + "-" + index;
    }

    function normalizeSkillForEditor(skill) {
        var copy = deepClone(skill || {});
        copy.code = $.trim(String(copy.code || ""));
        copy.name = $.trim(String(copy.name || copy.code || "Skill"));
        copy.type = copy.type === "service_call" ? "service_call" : "data_query";
        copy.enabled = copy.enabled !== false;
        copy.runMode = copy.runMode === "always" || copy.runMode === "manual" ? copy.runMode : "triggered";
        copy.description = String(copy.description || "");
        copy.triggerKeywords = $.isArray(copy.triggerKeywords) ? copy.triggerKeywords : normalizeListValue(copy.triggerKeywords);
        copy.triggerKeywords = $.map(copy.triggerKeywords, function(item) {
            return $.trim(String(item || ""));
        });
        copy.triggerKeywords = $.grep(copy.triggerKeywords, function(item) {
            return item !== "";
        });

        if (copy.type === "service_call") {
            copy.method = String(copy.method || "POST").toUpperCase();
            if ($.inArray(copy.method, ["GET", "POST", "PUT", "PATCH"]) === -1) {
                copy.method = "POST";
            }
            copy.url = String(copy.url || "");
            copy.headers = copy.headers && typeof copy.headers === "object" && !$.isArray(copy.headers) ? copy.headers : {};
            copy.bodyTemplate = copy.bodyTemplate && typeof copy.bodyTemplate === "object" && !$.isArray(copy.bodyTemplate) ? copy.bodyTemplate : {};
            copy.timeoutSeconds = parseInt(copy.timeoutSeconds, 10) || 20;
        } else {
            copy.source = String(copy.source || "json_file");
            copy.dataFile = String(copy.dataFile || "");
            copy.queryFields = $.isArray(copy.queryFields) ? copy.queryFields : normalizeListValue(copy.queryFields);
            copy.queryFields = $.map(copy.queryFields, function(item) {
                return $.trim(String(item || ""));
            });
            copy.queryFields = $.grep(copy.queryFields, function(item) {
                return item !== "";
            });
            copy.limit = parseInt(copy.limit, 10) || 5;
            copy.data = $.isArray(copy.data) ? copy.data : [];
        }

        return copy;
    }

    function renderSkillBuilderList() {
        var list = skillBuilderList();
        var skills = state.skillBuilder.skills;
        list.empty();

        find("[data-skill-count]").text(skills.length + (skills.length === 1 ? " skill" : " skills"));

        if (!skills.length) {
            list.append($("<div>").addClass("agent-creator__empty").text("No skills loaded yet. Add one to begin."));
            skillBuilderPreview().text("{}");
            return;
        }

        for (var i = 0; i < skills.length; i++) {
            var skill = skills[i];
            var item = $("<button>").attr("type", "button").addClass("agent-creator__skill-item");
            if (i === state.skillBuilder.selectedIndex) {
                item.addClass("is-active");
            }
            item.attr("data-skill-item", i);
            item.append($("<strong>").text(skill.name || skill.code || ("Skill " + (i + 1))));
            item.append($("<span>").text((skill.code || "skill") + " - " + skill.type));
            item.append($("<small>").text((skill.runMode || "triggered") + (skill.enabled === false ? " - disabled" : " - enabled")));
            list.append(item);
        }
    }

    function toggleSkillTypeBlocks(type) {
        find("[data-skill-type-block]").addClass("is-hidden");
        find('[data-skill-type-block="' + type + '"]').removeClass("is-hidden");
    }

    function renderSkillEditor() {
        var skill = skillBuilderSelected();
        if (!skill) {
            find("[data-skill-code]").val("");
            find("[data-skill-name]").val("");
            find("[data-skill-type]").val("data_query");
            find("[data-skill-run-mode]").val("triggered");
            find("[data-skill-enabled]").prop("checked", true);
            find("[data-skill-description]").val("");
            find("[data-skill-trigger-keywords]").val("");
            find("[data-skill-source]").val("json_file");
            find("[data-skill-data-file]").val("");
            find("[data-skill-limit]").val(5);
            find("[data-skill-query-fields]").val("");
            find("[data-skill-inline-data]").val("");
            find("[data-skill-method]").val("POST");
            find("[data-skill-timeout]").val(20);
            find("[data-skill-url]").val("");
            find("[data-skill-headers]").val("");
            find("[data-skill-body-template]").val("");
            toggleSkillTypeBlocks("data_query");
            skillBuilderPreview().text("{}");
            return;
        }

        find("[data-skill-code]").val(skill.code || "");
        find("[data-skill-name]").val(skill.name || "");
        find("[data-skill-type]").val(skill.type || "data_query");
        find("[data-skill-run-mode]").val(skill.runMode || "triggered");
        find("[data-skill-enabled]").prop("checked", skill.enabled !== false);
        find("[data-skill-description]").val(skill.description || "");
        find("[data-skill-trigger-keywords]").val((skill.triggerKeywords || []).join("\n"));
        find("[data-skill-source]").val(skill.source || "json_file");
        find("[data-skill-data-file]").val(skill.dataFile || "");
        find("[data-skill-limit]").val(skill.limit || 5);
        find("[data-skill-query-fields]").val((skill.queryFields || []).join("\n"));
        find("[data-skill-inline-data]").val(skill.data && skill.data.length ? JSON.stringify(skill.data, null, 2) : "");
        find("[data-skill-method]").val(skill.method || "POST");
        find("[data-skill-timeout]").val(skill.timeoutSeconds || 20);
        find("[data-skill-url]").val(skill.url || "");
        find("[data-skill-headers]").val(skill.headers && Object.keys(skill.headers).length ? JSON.stringify(skill.headers, null, 2) : "");
        find("[data-skill-body-template]").val(skill.bodyTemplate && Object.keys(skill.bodyTemplate).length ? JSON.stringify(skill.bodyTemplate, null, 2) : "");
        toggleSkillTypeBlocks(skill.type || "data_query");
        skillBuilderPreview().text(JSON.stringify(skill, null, 2));
    }

    function syncSkillBuilderTextarea() {
        skillBuilderField().val(JSON.stringify(state.skillBuilder.skills, null, 2));
    }

    function setSkillBuilderStatus(message, tone) {
        var status = skillBuilderStatus();
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function collectSkillEditorValues() {
        var current = skillBuilderSelected() || {};
        var type = find("[data-skill-type]").val() || "data_query";
        var skill = {
            code: $.trim(find("[data-skill-code]").val() || ""),
            name: $.trim(find("[data-skill-name]").val() || ""),
            type: type,
            enabled: find("[data-skill-enabled]").is(":checked"),
            runMode: find("[data-skill-run-mode]").val() || "triggered",
            description: $.trim(find("[data-skill-description]").val() || ""),
            triggerKeywords: listFromField("[data-skill-trigger-keywords]")
        };

        var isBlankDraft = !skill.code && !skill.name && !skill.description && !skill.triggerKeywords.length;
        if (state.skillBuilder.selectedIndex < 0 && isBlankDraft) {
            return {
                success: false,
                message: "Add a skill template or fill in the fields before applying."
            };
        }

        skill.code = uniqueSkillCode(skill.code || skill.name, state.skillBuilder.selectedIndex);
        if (!skill.name) {
            skill.name = skill.code;
        }

        if (type === "service_call") {
            try {
                skill.method = String(find("[data-skill-method]").val() || "POST").toUpperCase();
                skill.url = $.trim(find("[data-skill-url]").val() || "");
                skill.timeoutSeconds = parseInt(find("[data-skill-timeout]").val(), 10);
                if (isNaN(skill.timeoutSeconds)) {
                    skill.timeoutSeconds = 20;
                }
                skill.headers = jsonFromField("[data-skill-headers]", {});
                skill.bodyTemplate = jsonFromField("[data-skill-body-template]", {});
            } catch (error) {
                return {
                    success: false,
                    message: "Service call JSON must be valid before applying the skill."
                };
            }
            if ($.inArray(skill.method, ["GET", "POST", "PUT", "PATCH"]) === -1) {
                skill.method = "POST";
            }
            if (skill.timeoutSeconds < 2) {
                skill.timeoutSeconds = 2;
            }
            if (skill.timeoutSeconds > 60) {
                skill.timeoutSeconds = 60;
            }
        } else {
            try {
                skill.source = find("[data-skill-source]").val() || "json_file";
                skill.dataFile = $.trim(find("[data-skill-data-file]").val() || "");
                skill.limit = parseInt(find("[data-skill-limit]").val(), 10);
                if (isNaN(skill.limit)) {
                    skill.limit = 5;
                }
                skill.queryFields = listFromField("[data-skill-query-fields]");
                skill.data = jsonFromField("[data-skill-inline-data]", []);
                if (!$.isArray(skill.data)) {
                    skill.data = [skill.data];
                }
            } catch (error2) {
                return {
                    success: false,
                    message: "Data query JSON must be valid before applying the skill."
                };
            }
            if (skill.limit < 1) {
                skill.limit = 1;
            }
            if (skill.limit > 25) {
                skill.limit = 25;
            }
        }

        skill = normalizeSkillForEditor(skill);
        if (!skill.code) {
            return {
                success: false,
                message: "Skill code is required."
            };
        }

        return {
            success: true,
            skill: skill,
            current: current
        };
    }

    function applySkillEditor(options) {
        var result = collectSkillEditorValues();
        if (!result.success) {
            setSkillBuilderStatus(result.message || "Unable to apply skill.", "error");
            return false;
        }

        if (state.skillBuilder.selectedIndex < 0) {
            state.skillBuilder.skills.push(result.skill);
            state.skillBuilder.selectedIndex = state.skillBuilder.skills.length - 1;
        } else {
            state.skillBuilder.skills[state.skillBuilder.selectedIndex] = result.skill;
        }

        syncSkillBuilderTextarea();
        renderSkillBuilderList();
        renderSkillEditor();
        if (!options || options.showStatus !== false) {
            setSkillBuilderStatus("Skill applied to the JSON field.", "success");
        }
        return true;
    }

    function selectSkillBuilderIndex(index) {
        if (index < 0 || index >= state.skillBuilder.skills.length) {
            return;
        }

        if (!applySkillEditor({ showStatus: false })) {
            return;
        }

        state.skillBuilder.selectedIndex = index;
        renderSkillBuilderList();
        renderSkillEditor();
        setSkillBuilderStatus("Editing " + (skillBuilderSelected().name || skillBuilderSelected().code) + ".", "muted");
    }

    function openSkillBuilder() {
        var parsed = parseSkillArray(skillBuilderField().val());
        if (!parsed.success) {
            state.skillBuilder.skills = [];
            state.skillBuilder.selectedIndex = -1;
            showSkillBuilder(true);
            renderSkillBuilderList();
            renderSkillEditor();
            setSkillBuilderStatus(parsed.message, "error");
            return;
        }

        state.skillBuilder.skills = $.map(parsed.skills, function(item) {
            return normalizeSkillForEditor(item);
        });
        state.skillBuilder.selectedIndex = state.skillBuilder.skills.length ? 0 : -1;
        showSkillBuilder(true);
        renderSkillBuilderList();
        renderSkillEditor();
        setSkillBuilderStatus(state.skillBuilder.skills.length ? "Current skills JSON decoded into the visual editor." : "Start by adding a skill.", "success");
    }

    function showSkillBuilder(open) {
        state.skillBuilder.open = open;
        skillBuilderModal().toggleClass("is-hidden", !open);
        skillBuilderModal().attr("aria-hidden", open ? "false" : "true");
        if (open) {
            setTimeout(function() {
                find("[data-skill-code]").focus();
            }, 0);
        }
    }

    function closeSkillBuilder() {
        if (state.skillBuilder.open && state.skillBuilder.selectedIndex >= 0) {
            applySkillEditor({ showStatus: false });
        }
        showSkillBuilder(false);
    }

    function addSkillBuilderSkill(type) {
        if (state.skillBuilder.selectedIndex >= 0 && !applySkillEditor({ showStatus: false })) {
            return;
        }

        state.skillBuilder.skills.push(normalizeSkillForEditor(defaultSkill(type)));
        state.skillBuilder.selectedIndex = state.skillBuilder.skills.length - 1;
        syncSkillBuilderTextarea();
        renderSkillBuilderList();
        renderSkillEditor();
        setSkillBuilderStatus("Added a new " + type.replace("_", " ") + " skill.", "success");
    }

    function duplicateSkillBuilderSkill() {
        var skill = skillBuilderSelected();
        if (!skill) {
            setSkillBuilderStatus("Select a skill to duplicate.", "error");
            return;
        }

        if (!applySkillEditor({ showStatus: false })) {
            return;
        }

        var copy = deepClone(skill);
        copy.code = uniqueSkillCode((copy.code || copy.name || "skill") + "-copy");
        copy.name = (copy.name || copy.code) + " Copy";
        state.skillBuilder.skills.splice(state.skillBuilder.selectedIndex + 1, 0, normalizeSkillForEditor(copy));
        state.skillBuilder.selectedIndex++;
        syncSkillBuilderTextarea();
        renderSkillBuilderList();
        renderSkillEditor();
        setSkillBuilderStatus("Skill duplicated.", "success");
    }

    function deleteSkillBuilderSkill() {
        if (state.skillBuilder.selectedIndex < 0) {
            setSkillBuilderStatus("Select a skill to delete.", "error");
            return;
        }

        if (!confirm("Delete the selected skill?")) {
            return;
        }

        state.skillBuilder.skills.splice(state.skillBuilder.selectedIndex, 1);
        if (state.skillBuilder.selectedIndex >= state.skillBuilder.skills.length) {
            state.skillBuilder.selectedIndex = state.skillBuilder.skills.length - 1;
        }
        syncSkillBuilderTextarea();
        renderSkillBuilderList();
        renderSkillEditor();
        setSkillBuilderStatus("Skill deleted.", "success");
    }

    function reloadSkillBuilderFromTextarea() {
        var parsed = parseSkillArray(skillBuilderField().val());
        if (!parsed.success) {
            setSkillBuilderStatus(parsed.message, "error");
            return;
        }

        state.skillBuilder.skills = $.map(parsed.skills, function(item) {
            return normalizeSkillForEditor(item);
        });
        if (state.skillBuilder.selectedIndex >= state.skillBuilder.skills.length) {
            state.skillBuilder.selectedIndex = state.skillBuilder.skills.length - 1;
        }
        if (state.skillBuilder.selectedIndex < 0 && state.skillBuilder.skills.length) {
            state.skillBuilder.selectedIndex = 0;
        }
        renderSkillBuilderList();
        renderSkillEditor();
        setSkillBuilderStatus("Reloaded skills from the JSON field.", "success");
    }

    function handleSkillTypeChange() {
        var type = find("[data-skill-type]").val() || "data_query";
        toggleSkillTypeBlocks(type);
    }

    function bindEvents() {
        find("[data-provider-options]").on("click", "[data-provider]", function() {
            setProvider($(this).data("provider"));
        });

        find("[data-model-filter]").on("input", function() { state.modelFilter = $(this).val() || ""; renderModels(); });
        find("[data-model]").on("change", updateModelExperience);
        find("[data-custom-model]").on("input", updateModelExperience);
        find("[data-custom-model-metadata]").on("input", updateModelExperience);
        find("[data-discover-models]").on("click", discoverModels);
        find("[data-estimate-input], [data-estimate-cached], [data-estimate-output]").on("input", calculateEstimate);

        find("[data-temperature]").on("input", function() {
            find("[data-temperature-value]").text($(this).val());
        });

        find("[data-creator-form]").on("submit", generateConfig);
        find("[data-save-agent]").on("click", saveAgent);
        find("[data-reset-form]").on("click", resetForm);
        find("[data-copy-output]").on("click", copyOutput);
        find("[data-profile-image-change]").on("click", chooseProfileIcon);
        find("[data-profile-image]").on("error", function() {
            setProfileImage("");
        });
        find("[data-open-skill-builder]").on("click", openSkillBuilder);
        find("[data-skill-template]").on("click", function() {
            insertSkillTemplate($(this).data("skill-template"));
        });
        find("[data-skill-modal-close]").on("click", closeSkillBuilder);
        find("[data-skill-builder-import]").on("click", reloadSkillBuilderFromTextarea);
        find("[data-skill-add]").on("click", function() {
            addSkillBuilderSkill($(this).data("skill-add"));
        });
        find("[data-skill-apply]").on("click", function() {
            applySkillEditor();
        });
        find("[data-skill-duplicate]").on("click", duplicateSkillBuilderSkill);
        find("[data-skill-delete]").on("click", deleteSkillBuilderSkill);
        find("[data-skill-type]").on("change", handleSkillTypeChange);
        find("[data-skill-list]").on("click", "[data-skill-item]", function() {
            selectSkillBuilderIndex(parseInt($(this).data("skill-item"), 10));
        });
        find("[data-skill-modal]").on("keydown", function(event) {
            if (event.key === "Escape") {
                closeSkillBuilder();
            }
        });
        find("[data-test-form]").on("submit", testAgent);
        find("[data-delete-agent]").on("click", deleteSelectedAgent);
        find("[data-test-agent]").on("change", function() {
            selectAgent($(this).val());
        });
        find("[data-test-files]").on("change", function() { addTestFiles(this.files); });
        find("[data-test-attachments]").on("click", "[data-remove-attachment]", function() {
            state.testAttachments.splice(parseInt($(this).data("remove-attachment"), 10), 1);
            renderTestAttachments();
        });
        find("[data-agent-list]").on("click", "[data-agent-item]", function() {
            selectAgent($(this).data("agent-item"));
        });
        find("[data-format]").on("click", function() {
            state.format = $(this).data("format");
            find("[data-format]").removeClass("is-active");
            $(this).addClass("is-active");
            renderOutput();
        });
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("creator-api");
        bindEvents();
        initializeProfileTools();
        renderOutput();
        renderTestAttachments();
        loadProviders();
        loadAgents();
    };
});
