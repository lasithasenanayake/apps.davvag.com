WEBDOCK.component().register(function(exports) {
    var api;
    var root;
    var profileCropper;
    var profileUploader;
    var pendingProfileFile;
    var state = {
        configured: false,
        profileRegistered: false,
        profileId: 0,
        busy: false,
        messages: []
    };

    function find(selector) {
        return root.find(selector);
    }

    function setStatus(message, tone) {
        var status = find("[data-agent-status]");
        status.removeClass("is-error is-success is-muted");
        if (tone) {
            status.addClass("is-" + tone);
        }
        status.text(message);
    }

    function setBusy(isBusy) {
        state.busy = isBusy;
        find("button, input, textarea").prop("disabled", isBusy);
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

    function showServiceError(response, fallback) {
        var result = serviceResult(response && response.responseJSON ? response.responseJSON : response);
        setStatus(result.message || fallback || "Request failed.", "error");
    }

    function profileImageUrl(profileId) {
        return profileId > 0 ? "components/dock/soss-uploader/service/get/profile/" + profileId : "";
    }

    function setProfileImage(src) {
        var image = find("[data-agent-profile-image]");
        var fallback = find("[data-agent-profile-fallback]");
        if (src) {
            image.attr("src", src).show();
            fallback.hide();
        } else {
            image.removeAttr("src").hide();
            fallback.show();
        }
    }

    function applyProfileResult(result) {
        state.profileId = parseInt(result.profileId || 0, 10) || 0;
        state.profileRegistered = result.profileRegistered !== undefined ? !!result.profileRegistered : state.profileId > 0;

        find("[data-agent-profile-id]").val(state.profileId || "");
        find("[data-agent-profile-name]").val(result.profileName || "ChatGPT Agent");
        find("[data-agent-profile-email]").val(result.profileEmail || "");
        find("[data-agent-profile-phone]").val(result.profilePhone || "");
        setProfileImage(result.profileImage || profileImageUrl(state.profileId));
    }

    function loadConfig() {
        if (!api) {
            setStatus("The agent-api service is not loaded.", "error");
            return;
        }

        api.services.Config()
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setStatus(result.message || "Unable to load configuration.", "error");
                    return;
                }

                state.configured = !!result.apiKeyConfigured;
                applyProfileResult(result);
                find("[data-agent-model]").val(result.model || "gpt-4.1-mini");
                find("[data-agent-temperature]").val(result.temperature !== undefined && result.temperature !== null ? result.temperature : "");
                find("[data-agent-max-output]").val(result.maxOutputTokens || 1000);
                find("[data-agent-instructions]").val(result.instructions || "");
                updateConfigStatus();
            })
            .error(function(response) {
                showServiceError(response, "Unable to load configuration.");
            });
    }

    function updateConfigStatus() {
        if (!state.profileRegistered) {
            setStatus("Agent profile is required. Add name, email, phone, and save.", "muted");
        } else if (state.configured) {
            setStatus("Agent profile and API key configured. The agent is ready to chat.", "success");
        } else {
            setStatus("Agent profile saved. Add an API key before chatting.", "muted");
        }
    }

    function configPayload() {
        return {
            profileId: find("[data-agent-profile-id]").val(),
            profileName: find("[data-agent-profile-name]").val(),
            profileEmail: find("[data-agent-profile-email]").val(),
            profilePhone: find("[data-agent-profile-phone]").val(),
            apiKey: find("[data-agent-api-key]").val(),
            model: find("[data-agent-model]").val(),
            temperature: find("[data-agent-temperature]").val(),
            maxOutputTokens: find("[data-agent-max-output]").val(),
            instructions: find("[data-agent-instructions]").val()
        };
    }

    function saveConfig() {
        setBusy(true);
        api.services.SaveConfig(configPayload())
            .then(function(response) {
                var result = serviceResult(response);
                if (result.success === false) {
                    setBusy(false);
                    setStatus(result.message || "Configuration was not saved.", "error");
                    return;
                }

                state.configured = !!result.apiKeyConfigured;
                applyProfileResult(result);
                find("[data-agent-api-key]").val("");

                if (pendingProfileFile && state.profileId > 0) {
                    setStatus("Uploading profile icon...", "muted");
                    uploadProfileIcon(state.profileId, function() {
                        setBusy(false);
                        updateConfigStatus();
                    });
                    return;
                }

                setBusy(false);
                updateConfigStatus();
            })
            .error(function(response) {
                setBusy(false);
                showServiceError(response, "Configuration was not saved.");
            });
    }

    function clearApiKey() {
        if (!confirm("Clear the saved OpenAI API key for this tenant?")) {
            return;
        }

        setBusy(true);
        api.services.ClearConfig({})
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    setStatus(result.message || "API key was not cleared.", "error");
                    return;
                }

                state.configured = false;
                find("[data-agent-api-key]").val("");
                updateConfigStatus();
            })
            .error(function(response) {
                setBusy(false);
                showServiceError(response, "API key was not cleared.");
            });
    }

    function renderMessages() {
        var container = find("[data-agent-messages]");
        container.empty();

        if (!state.messages.length) {
            container.append($("<div>").addClass("ai-agent__empty").text("Save the agent profile and API key, tune the instructions, then send a message."));
            return;
        }

        for (var i = 0; i < state.messages.length; i++) {
            var message = state.messages[i];
            var bubble = $("<article>").addClass("ai-agent__message ai-agent__message--" + message.role);
            bubble.append($("<span>").addClass("ai-agent__role").text(message.role === "user" ? "You" : "Agent"));
            bubble.append($("<div>").addClass("ai-agent__bubble").text(message.content));
            container.append(bubble);
        }

        container.scrollTop(container[0].scrollHeight);
    }

    function sendMessage(message) {
        if (!state.profileRegistered) {
            setStatus("Save the AI agent profile before chatting.", "error");
            return;
        }
        if (!state.configured) {
            setStatus("Add an OpenAI API key before chatting.", "error");
            return;
        }

        var history = state.messages.slice(-20);
        state.messages.push({ role: "user", content: message });
        renderMessages();
        setBusy(true);
        setStatus("Waiting for the AI response...", "muted");

        api.services.Chat({
            message: message,
            history: history
        })
            .then(function(response) {
                var result = serviceResult(response);
                setBusy(false);
                if (result.success === false) {
                    setStatus(result.message || "The AI request failed.", "error");
                    return;
                }

                state.messages.push({
                    role: "assistant",
                    content: result.reply || ""
                });
                renderMessages();
                updateConfigStatus();
            })
            .error(function(response) {
                setBusy(false);
                showServiceError(response, "The AI request failed.");
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
            setStatus("Profile icon selected. Save configuration to upload it.", "muted");
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

    function bindEvents() {
        find("[data-agent-save]").on("click", saveConfig);
        find("[data-agent-clear-key]").on("click", clearApiKey);
        find("[data-agent-profile-image-change]").on("click", chooseProfileIcon);
        find("[data-agent-profile-image]").on("error", function() {
            setProfileImage("");
        });
        find("[data-agent-reset]").on("click", function() {
            state.messages = [];
            renderMessages();
            updateConfigStatus();
        });

        find("[data-agent-form]").on("submit", function(event) {
            event.preventDefault();
            var input = find("[data-agent-message]");
            var message = input.val().trim();
            if (!message) {
                return;
            }
            input.val("");
            sendMessage(message);
        });

        find("[data-agent-message]").on("keydown", function(event) {
            if (event.key === "Enter" && !event.shiftKey) {
                event.preventDefault();
                find("[data-agent-form]").trigger("submit");
            }
        });
    }

    exports.onReady = function(element) {
        root = element;
        api = exports.getComponent("agent-api");
        bindEvents();
        initializeProfileTools();
        renderMessages();
        loadConfig();
    };
});
