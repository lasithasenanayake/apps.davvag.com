WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var profileCropper;
    var profileUploader;
    var pendingProfileFile;
    var state = {
        provider: "openai",
        format: "json",
        agents: [],
        selectedAgentCode: "",
        output: {
            json: "{}",
            yaml: ""
        }
    };

    var providers = {
        openai: {
            method: "REST API: chat/completions",
            model: "gpt-4.1-mini",
            apiPlaceholder: "sk-...",
            endpoint: "",
            fields: ["apiKey"]
        },
        ollama: {
            method: "Local runtime: CLI/HTTP",
            model: "llama3.1",
            apiPlaceholder: "",
            endpoint: "http://localhost:11434/api/chat",
            fields: ["endpoint", "cliCommand"]
        },
        lmstudio: {
            method: "Local inference server",
            model: "local-model",
            apiPlaceholder: "",
            endpoint: "http://localhost:1234/v1/chat/completions",
            fields: ["endpoint"]
        },
        google: {
            method: "Generative Language API",
            model: "gemini-1.5-pro",
            apiPlaceholder: "AIza...",
            endpoint: "https://generativelanguage.googleapis.com/v1beta",
            fields: ["apiKey"]
        },
        other: {
            method: "Custom API schema",
            model: "custom-model",
            apiPlaceholder: "token or key",
            endpoint: "https://api.example.com/v1/chat",
            fields: ["apiKey", "endpoint", "customMethod", "authHeader"]
        }
    };

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

    function setProvider(provider) {
        var meta = providers[provider] || providers.openai;
        state.provider = provider;

        find("[data-provider]").removeClass("is-active");
        find('[data-provider="' + provider + '"]').addClass("is-active");
        find("[data-connection-method]").text(meta.method);

        find("[data-field]").addClass("is-hidden");
        for (var i = 0; i < meta.fields.length; i++) {
            find('[data-field="' + meta.fields[i] + '"]').removeClass("is-hidden");
        }

        find("[data-model]").attr("placeholder", meta.model);
        find("[data-api-key]").attr("placeholder", meta.apiPlaceholder);
        find("[data-endpoint]").attr("placeholder", meta.endpoint);

        if (!find("[data-model]").val()) {
            find("[data-model]").val(meta.model);
        }
        if (!find("[data-endpoint]").val() && meta.endpoint) {
            find("[data-endpoint]").val(meta.endpoint);
        }

        setStatus("Provider mapped to " + meta.method + ".", "muted");
    }

    function collectForm() {
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
            model: find("[data-model]").val(),
            apiKey: find("[data-api-key]").val(),
            endpoint: find("[data-endpoint]").val(),
            cliCommand: find("[data-cli-command]").val(),
            customMethod: find("[data-custom-method]").val(),
            authHeader: find("[data-auth-header]").val(),
            systemPrompt: find("[data-system-prompt]").val(),
            temperature: find("[data-temperature]").val(),
            maxTokens: find("[data-max-tokens]").val(),
            streaming: find("[data-streaming]").is(":checked")
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
    }

    function fillFormFromAgent(agent) {
        var config = agent.configuration;
        find("[data-agent-code]").val(agent.agentCode);
        find("[data-agent-name]").val(agent.name);
        find("[data-agent-description]").val(agent.description || "");
        find("[data-agent-capabilities]").val((agent.capabilities || []).join("\n"));
        find("[data-agent-skills]").val(JSON.stringify(agent.skills || config.skills || [], null, 2));
        applyAgentIdentity(agent);

        setProvider(config.provider.type);
        find("[data-model]").val(config.provider.model);
        find("[data-api-key]").val("");
        find("[data-system-prompt]").val(config.agent.startupPrompt || "");
        find("[data-temperature]").val(config.parameters.temperature);
        find("[data-temperature-value]").text(config.parameters.temperature);
        find("[data-max-tokens]").val(config.parameters.maxTokens);
        find("[data-streaming]").prop("checked", !!config.parameters.streaming);

        if (config.connection.endpoint) {
            find("[data-endpoint]").val(config.connection.endpoint);
        }
        if (config.connection.runtime && config.connection.runtime.cliCommand) {
            find("[data-cli-command]").val(config.connection.runtime.cliCommand);
        }
        if (config.connection.httpMethod) {
            find("[data-custom-method]").val(config.connection.httpMethod);
        }
        if (config.connection.auth && config.connection.auth.header) {
            find("[data-auth-header]").val(config.connection.auth.header);
        }
    }

    function testAgent(event) {
        event.preventDefault();
        var agentCode = find("[data-test-agent]").val();
        var message = find("[data-test-message]").val();

        if (!agentCode) {
            setStatus("Select a saved agent before testing.", "error");
            return;
        }

        setBusy(true);
        find("[data-test-response]").text("Waiting for response...");

        api.services.TestAgent({
            agentCode: agentCode,
            message: message,
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
                    session: result.session || null,
                    usage: result.usage || null,
                    billingUsageId: result.billingUsageId || "",
                    skillResults: result.skillResults || []
                }, null, 2));
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
        pendingProfileFile = null;
        setProfileImage("");
        state.output = { json: "{}", yaml: "" };
        renderOutput();
        setProvider("openai");
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
                }
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
            dataFile: "products.json",
            queryFields: ["name", "sku", "description"],
            limit: 5
        };
    }

    function insertSkillTemplate(type) {
        var field = find("[data-agent-skills]");
        var current = $.trim(field.val());
        var skills = [];
        if (current) {
            try {
                skills = JSON.parse(current);
                if (!$.isArray(skills)) {
                    skills = [skills];
                }
            } catch (error) {
                setStatus("Skills JSON must be valid before adding a template.", "error");
                return;
            }
        }

        skills.push(skillTemplate(type));
        field.val(JSON.stringify(skills, null, 2));
        setStatus("Skill template added.", "muted");
    }

    function bindEvents() {
        find("[data-provider]").on("click", function() {
            find("[data-model]").val("");
            find("[data-endpoint]").val("");
            setProvider($(this).data("provider"));
        });

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
        find("[data-skill-template]").on("click", function() {
            insertSkillTemplate($(this).data("skill-template"));
        });
        find("[data-test-form]").on("submit", testAgent);
        find("[data-delete-agent]").on("click", deleteSelectedAgent);
        find("[data-test-agent]").on("change", function() {
            selectAgent($(this).val());
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
        setProvider("openai");
        renderOutput();
        loadAgents();
    };
});
