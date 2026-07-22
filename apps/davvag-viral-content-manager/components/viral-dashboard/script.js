WEBDOCK.component().register(function (exports) {
    var scope;
    var api;
    var urlFetchTimer = null;

    var bindData = {
        activeTab: "optimize",
        loading: {
            fetch: false,
            optimize: false,
            generate: false,
            shorts: false,
            accounts: false,
            oauth: false,
            history: false,
            agents: false
        },
        messages: {
            errors: [],
            info: []
        },
        optimizeForm: emptyOptimizeForm(),
        generateForm: emptyGenerateForm(),
        shortsForm: emptyShortsForm(),
        accountForm: emptyAccountForm(),
        optimizeResult: null,
        generateResult: null,
        shortsResult: null,
        urlDetails: null,
        fetchStatus: "",
        editingAccountId: null,
        deletingAccountId: null,
        accounts: [],
        history: [],
        agentCatalog: [],
        agentTasks: [],
        savedAgents: [],
        agentMappings: {}
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: refresh,
            setTab: setTab,
            scheduleUrlFetch: scheduleUrlFetch,
            pasteOptimizeUrl: pasteOptimizeUrl,
            fetchUrlDetails: fetchUrlDetails,
            analyzeUrl: analyzeUrl,
            clearOptimize: clearOptimize,
            generatePost: generatePost,
            findShorts: findShorts,
            saveAccount: saveAccount,
            editAccount: editAccount,
            deleteAccount: deleteAccount,
            isDeletingAccount: isDeletingAccount,
            newAccount: newAccount,
            startOAuth: startOAuth,
            applyPlatformOAuthDefaults: applyPlatformOAuthDefaults,
            loadHistory: loadHistory,
            queueResult: queueResult,
            saveAgentMappings: saveAgentMappings,
            copyAgentPrompt: copyAgentPrompt,
            copyText: copyText,
            copyOptimizePackage: copyOptimizePackage,
            mappedAgentName: mappedAgentName
        },
        onReady: function (s) {
            scope = s;
            initialize();
        }
    };

    exports.onReady = function () {
    };

    function initialize() {
        api = exports.getComponent("viral-api");
        setTabFromHash();

        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }

        refresh();
        if (window && window.addEventListener) {
            window.addEventListener("hashchange", setTabFromHash);
            window.addEventListener("message", onOAuthMessage);
        }
    }

    function emptyOptimizeForm() {
        return {
            url: "",
            platform: "",
            title: "",
            description: "",
            transcript: "",
            audience: "",
            language: "",
            useAgents: false
        };
    }

    function emptyGenerateForm() {
        return {
            idea: "",
            platform: "YouTube",
            audience: "",
            language: "",
            useAgents: false
        };
    }

    function emptyShortsForm() {
        return {
            url: "",
            platform: "TikTok",
            transcript: "",
            language: "",
            useAgents: false
        };
    }

    function emptyAccountForm() {
        return {
            platform: "YouTube",
            accountName: "",
            accountHandle: "",
            connectionStatus: "Manual",
            notes: "",
            apiKey: "",
            accessToken: "",
            pageId: "",
            clientId: "",
            clientSecret: "",
            redirectUri: "",
            scopesText: defaultScopes("YouTube"),
            hasSavedApiKey: false
        };
    }

    function refresh() {
        clearMessages();
        loadAgentCatalog();
        loadAccounts();
        loadHistory();
    }

    function setTab(tab) {
        bindData.activeTab = tab;
    }

    function setTabFromHash() {
        var hash = window.location.hash || "";
        if (hash.indexOf("generate") >= 0) {
            bindData.activeTab = "generate";
        } else if (hash.indexOf("shorts") >= 0) {
            bindData.activeTab = "shorts";
        } else if (hash.indexOf("accounts") >= 0) {
            bindData.activeTab = "accounts";
        } else if (hash.indexOf("agents") >= 0) {
            bindData.activeTab = "agents";
        } else if (hash.indexOf("history") >= 0) {
            bindData.activeTab = "history";
        } else {
            bindData.activeTab = "optimize";
        }
    }

    function analyzeUrl() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }

        if (bindData.loading.optimize) {
            return;
        }
        clearMessages();
        bindData.loading.optimize = true;
        api.services.AnalyzeUrl(withAgentMappings(bindData.optimizeForm)).then(function (response) {
            bindData.loading.optimize = false;
            if (response.success) {
                bindData.optimizeResult = response.result;
                setInfo("Analysis saved.");
                reportAgentResult(response.result && response.result.agentNotes);
                loadHistory();
            } else {
                setError(responseMessage(response, "URL analysis failed."));
            }
        }).error(function () {
            bindData.loading.optimize = false;
            setError("URL analysis failed.");
        });
    }

    function scheduleUrlFetch() {
        if (urlFetchTimer) {
            window.clearTimeout(urlFetchTimer);
        }
        bindData.fetchStatus = "";
        if (!looksLikeSupportedUrl(bindData.optimizeForm.url)) {
            return;
        }
        urlFetchTimer = window.setTimeout(fetchUrlDetails, 900);
    }

    function pasteOptimizeUrl() {
        function useValue(value) {
            if (value !== null && value !== undefined) {
                bindData.optimizeForm.url = String(value).trim();
                fetchUrlDetails();
            }
        }
        if (navigator.clipboard && navigator.clipboard.readText) {
            navigator.clipboard.readText().then(useValue).catch(function () {
                useValue(window.prompt("Paste the content URL", bindData.optimizeForm.url || ""));
            });
        } else {
            useValue(window.prompt("Paste the content URL", bindData.optimizeForm.url || ""));
        }
    }

    function fetchUrlDetails() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }
        if (!looksLikeSupportedUrl(bindData.optimizeForm.url) || bindData.loading.fetch) {
            return;
        }

        bindData.loading.fetch = true;
        bindData.fetchStatus = "Fetching platform details...";
        api.services.FetchUrlDetails({
            url: bindData.optimizeForm.url,
            platform: bindData.optimizeForm.platform
        }).then(function (response) {
            bindData.loading.fetch = false;
            if (response.success) {
                applyFetchedDetails(response.result || {});
            } else {
                bindData.fetchStatus = "Platform details could not be fetched.";
            }
        }).error(function () {
            bindData.loading.fetch = false;
            bindData.fetchStatus = "Platform details could not be fetched.";
        });
    }

    function applyFetchedDetails(details) {
        bindData.urlDetails = details;
        if (details.platform) {
            bindData.optimizeForm.platform = details.platform;
        }
        if (details.title) {
            bindData.optimizeForm.title = details.title;
        }
        if (details.description) {
            bindData.optimizeForm.description = details.description;
        }
        if (details.transcript) {
            bindData.optimizeForm.transcript = details.transcript;
        }
        if (details.language) {
            bindData.optimizeForm.language = details.language;
        }

        var message = "Platform details loaded" + (details.source ? " from " + details.source : "") + ".";
        if (details.messages && details.messages.length) {
            message += " " + details.messages.join(" ");
        }
        bindData.fetchStatus = message;
    }

    function clearOptimize() {
        bindData.optimizeForm = emptyOptimizeForm();
        bindData.optimizeResult = null;
        bindData.urlDetails = null;
        bindData.fetchStatus = "";
        clearMessages();
    }

    function generatePost() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }

        clearMessages();
        bindData.loading.generate = true;
        api.services.GeneratePost(withAgentMappings(bindData.generateForm)).then(function (response) {
            bindData.loading.generate = false;
            if (response.success) {
                bindData.generateResult = response.result;
                setInfo("Post idea saved.");
                loadHistory();
            } else {
                setError("Post generation failed.");
            }
        }).error(function () {
            bindData.loading.generate = false;
            setError("Post generation failed.");
        });
    }

    function findShorts() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }

        clearMessages();
        bindData.loading.shorts = true;
        api.services.FindShorts(withAgentMappings(bindData.shortsForm)).then(function (response) {
            bindData.loading.shorts = false;
            if (response.success) {
                bindData.shortsResult = response.result;
                setInfo("Short clips saved.");
                loadHistory();
            } else {
                setError("Short clip search failed.");
            }
        }).error(function () {
            bindData.loading.shorts = false;
            setError("Short clip search failed.");
        });
    }

    function loadAccounts() {
        if (!api) {
            return;
        }

        api.services.ListAccounts({}).then(function (response) {
            if (response.success) {
                bindData.accounts = response.result || [];
            } else {
                setError(responseMessage(response, "Account list failed."));
            }
        }).error(function () {
            setError("Account list failed.");
        });
    }

    function saveAccount() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }

        clearMessages();
        bindData.loading.accounts = true;
        api.services.SaveAccount(clone(bindData.accountForm)).then(function (response) {
            bindData.loading.accounts = false;
            if (response.success) {
                newAccount(false);
                setInfo("Account saved.");
                loadAccounts();
            } else {
                setError(responseMessage(response, "Account save failed."));
            }
        }).error(function () {
            bindData.loading.accounts = false;
            setError("Account save failed.");
        });
    }

    function editAccount(account) {
        var form = emptyAccountForm();
        var raw = account && account.raw ? clone(account.raw) : {};
        form.socialAccountId = account.socialAccountId;
        form.platform = account.platform || form.platform;
        form.accountName = account.accountName || "";
        form.accountHandle = account.accountHandle || "";
        form.accountId = account.accountId || "";
        form.connectionType = account.connectionType || "Manual";
        form.connectionStatus = account.connectionStatus || "Manual";
        form.notes = account.notes || "";
        form.scopesText = scopesToText(account.scopes, raw.scopesText || defaultScopes(form.platform));
        form.apiKey = secretForEdit(raw.apiKey);
        form.accessToken = secretForEdit(raw.accessToken);
        form.pageId = raw.pageId || account.accountId || "";
        form.clientId = raw.clientId || raw.appId || "";
        form.clientSecret = secretForEdit(raw.clientSecret || raw.appSecret);
        form.redirectUri = raw.redirectUri || "";
        form.hasSavedApiKey = !!(account.credentialStatus && account.credentialStatus.apiKey);
        bindData.accountForm = form;
        bindData.editingAccountId = form.socialAccountId || null;
        setInfo("Editing " + form.accountName + ".");
    }

    function deleteAccount(account) {
        var accountId = account && parseInt(account.socialAccountId, 10);
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }
        if (!accountId || bindData.deletingAccountId !== null) {
            return;
        }

        var accountName = account.accountName || account.accountHandle || "this connected account";
        if (!window.confirm("Delete " + accountName + "? This removes its saved connection and credentials.")) {
            return;
        }

        clearMessages();
        bindData.deletingAccountId = accountId;
        api.services.DeleteAccount({ socialAccountId: accountId }).then(function (response) {
            bindData.deletingAccountId = null;
            if (response.success) {
                bindData.accounts = bindData.accounts.filter(function (item) {
                    return parseInt(item.socialAccountId, 10) !== accountId;
                });
                if (parseInt(bindData.editingAccountId, 10) === accountId) {
                    newAccount(false);
                }
                setInfo("Connected account deleted.");
            } else {
                setError(responseMessage(response, "Account delete failed."));
            }
        }).error(function () {
            bindData.deletingAccountId = null;
            setError("Account delete failed.");
        });
    }

    function isDeletingAccount(account) {
        return account
            && bindData.deletingAccountId !== null
            && parseInt(account.socialAccountId, 10) === bindData.deletingAccountId;
    }

    function newAccount(clearNotice) {
        bindData.accountForm = emptyAccountForm();
        bindData.editingAccountId = null;
        if (clearNotice !== false) {
            clearMessages();
        }
    }

    function startOAuth() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }
        clearMessages();
        var oauthWindow = window.open("about:blank", "davvagOAuth", "width=720,height=760");
        if (!oauthWindow) {
            setError("OAuth popup was blocked. Allow popups for this site and try again.");
            return;
        }
        bindData.loading.oauth = true;
        api.services.StartOAuth(clone(bindData.accountForm)).then(function (response) {
            bindData.loading.oauth = false;
            if (response.success && response.result && response.result.authUrl) {
                bindData.accountForm.redirectUri = response.result.redirectUri || bindData.accountForm.redirectUri;
                oauthWindow.location.href = response.result.authUrl;
                oauthWindow.focus();
                setInfo("OAuth window opened. Complete provider authorization, then return here.");
            } else {
                oauthWindow.close();
                setError(responseMessage(response, "OAuth could not be started."));
            }
        }).error(function () {
            bindData.loading.oauth = false;
            oauthWindow.close();
            setError("OAuth could not be started.");
        });
    }

    function onOAuthMessage(event) {
        if (!event || !event.data || event.data.type !== "davvag-oauth-complete") {
            return;
        }
        if (event.data.success) {
            setInfo("OAuth connected.");
            bindData.accountForm = emptyAccountForm();
            loadAccounts();
        } else {
            setError("OAuth did not complete.");
        }
    }

    function applyPlatformOAuthDefaults() {
        if (!bindData.accountForm.scopesText || bindData.accountForm.scopesText === defaultScopes("YouTube") || bindData.accountForm.scopesText === defaultScopes("TikTok") || bindData.accountForm.scopesText === defaultScopes("Facebook Pages")) {
            bindData.accountForm.scopesText = defaultScopes(bindData.accountForm.platform);
        }
    }

    function defaultScopes(platform) {
        if (platform === "TikTok") {
            return "user.info.basic,video.upload,video.publish";
        }
        if (platform === "Facebook Pages" || platform === "Facebook Reels") {
            return "pages_show_list,pages_read_engagement,pages_manage_posts,pages_read_user_content";
        }
        return "https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.force-ssl";
    }

    function loadHistory() {
        if (!api) {
            return;
        }

        bindData.loading.history = true;
        api.services.ListHistory({}).then(function (response) {
            bindData.loading.history = false;
            if (response.success) {
                bindData.history = response.result || [];
            }
        }).error(function () {
            bindData.loading.history = false;
        });
    }

    function loadAgentCatalog() {
        if (!api) {
            return;
        }

        bindData.loading.agents = true;
        api.services.AgentCatalog({}).then(function (response) {
            bindData.loading.agents = false;
            if (response.success) {
                applyAgentCatalog(response.result);
            }
        }).error(function () {
            bindData.loading.agents = false;
        });
    }

    function applyAgentCatalog(result) {
        result = result || {};
        if (Object.prototype.toString.call(result) === "[object Array]") {
            bindData.agentTasks = result;
            bindData.agentCatalog = result;
            bindData.savedAgents = [];
            bindData.agentMappings = {};
            return;
        }

        bindData.agentTasks = result.tasks || [];
        bindData.agentCatalog = bindData.agentTasks;
        bindData.savedAgents = result.savedAgents || [];
        bindData.agentMappings = result.mappings || {};
    }

    function saveAgentMappings() {
        if (!api) {
            setError("Viral API service is not loaded.");
            return;
        }

        clearMessages();
        bindData.loading.agents = true;
        api.services.SaveAgentMappings({
            mappings: agentMappingPayload()
        }).then(function (response) {
            bindData.loading.agents = false;
            if (response.success) {
                applyAgentCatalog(response.result);
                setInfo("Agent mappings saved.");
            } else {
                setError("Agent mappings could not be saved.");
            }
        }).error(function () {
            bindData.loading.agents = false;
            setError("Agent mappings could not be saved.");
        });
    }

    function copyAgentPrompt(prompt) {
        copyText(prompt, "Agent prompt");
    }

    function copyText(value, label) {
        if (Object.prototype.toString.call(value) === "[object Array]") {
            value = value.join("\n");
        }
        value = value === undefined || value === null ? "" : String(value);
        if (!value) {
            return;
        }
        label = label || "Content";
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(function () {
                setInfo(label + " copied.");
            }).catch(function () {
                window.prompt("Copy " + label.toLowerCase(), value);
            });
        } else {
            window.prompt("Copy " + label.toLowerCase(), value);
        }
    }

    function copyOptimizePackage() {
        var result = bindData.optimizeResult;
        if (!result) {
            return;
        }
        var sections = [
            "TITLES\n" + (result.titleOptions || []).join("\n"),
            "DESCRIPTION\n" + (result.improvedDescription || ""),
            "KEYWORDS\n" + (result.keywords || []).join(", "),
            "HASHTAGS\n" + (result.hashtags || []).join(" "),
            "CTA\n" + (result.cta || ""),
            "PINNED COMMENT\n" + (result.pinnedComment || "")
        ];
        copyText(sections.join("\n\n"), "Optimization package");
    }

    function mappedAgentName(taskCode) {
        for (var i = 0; i < bindData.agentTasks.length; i++) {
            var task = bindData.agentTasks[i];
            if (task.code === taskCode) {
                return task.mappedAgentExists
                    ? (task.mappedAgentName + " (" + task.selectedAgentCode + ")")
                    : "No available agent mapped";
            }
        }
        return "Agent mappings are loading";
    }

    function reportAgentResult(notes) {
        if (!bindData.optimizeForm.useAgents || !notes) {
            return;
        }
        if (notes.status === "completed") {
            setInfo("Mapped agent " + notes.agentCode + " completed the analysis.");
        } else if (notes.error) {
            setError("Mapped agent: " + notes.error);
        }
    }

    function queueResult(result, platform) {
        if (!api || !result) {
            return;
        }

        clearMessages();
        api.services.QueuePublish({
            contentUid: result.contentUid || "",
            platform: platform || result.platform || "Manual Copy",
            queueType: "Manual Copy",
            payload: result,
            notes: "Queued from DAVVAG Viral Content Manager"
        }).then(function (response) {
            if (response.success) {
                setInfo("Queued for manual publishing.");
            } else {
                setError("Queue save failed.");
            }
        }).error(function () {
            setError("Queue save failed.");
        });
    }

    function clearMessages() {
        bindData.messages.errors = [];
        bindData.messages.info = [];
    }

    function setError(message) {
        bindData.messages.errors.push(message);
    }

    function setInfo(message) {
        bindData.messages.info.push(message);
    }

    function clone(obj) {
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function responseMessage(response, fallback) {
        if (response && response.message) {
            return response.message;
        }
        if (response && response.error) {
            return response.error;
        }
        return fallback;
    }

    function scopesToText(scopes, fallback) {
        if (Object.prototype.toString.call(scopes) === "[object Array]") {
            return scopes.join(" ");
        }
        if (typeof scopes === "string" && scopes !== "") {
            return scopes;
        }
        return fallback || "";
    }

    function secretForEdit(value) {
        return value && value !== "********" ? value : "";
    }

    function looksLikeSupportedUrl(url) {
        url = (url || "").toLowerCase();
        return url.indexOf("youtube.com") >= 0
            || url.indexOf("youtu.be") >= 0
            || url.indexOf("tiktok.com") >= 0
            || url.indexOf("facebook.com") >= 0
            || url.indexOf("fb.watch") >= 0
            || url.indexOf("instagram.com") >= 0;
    }

    function withAgentMappings(form) {
        var data = clone(form);
        data.agentMappings = agentMappingPayload();
        return data;
    }

    function agentMappingPayload() {
        var mappings = {};
        for (var i = 0; i < bindData.agentTasks.length; i++) {
            mappings[bindData.agentTasks[i].code] = bindData.agentTasks[i].selectedAgentCode || "";
        }
        return mappings;
    }
});
