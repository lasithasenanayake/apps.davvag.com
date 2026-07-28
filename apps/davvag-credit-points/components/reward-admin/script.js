WEBDOCK.component().register(function (exports) {
    var api;
    var router;

    function emptyForm() {
        return {
            id: "",
            program_id: "",
            rule_code: "",
            title: "",
            cadence: "DAILY",
            award_mode: "CLAIM",
            credit_amount: "",
            timezone: "Asia/Colombo",
            week_start_day: 1,
            month_claim_day: 1,
            claim_window_hours: 24,
            eligibility_json: "",
            expiry_days: 0,
            active_from: "",
            active_until: "",
            status: "ACTIVE"
        };
    }

    var data = {
        rewards: [],
        programs: [],
        form: emptyForm(),
        search: "",
        busy: false,
        errors: [],
        info: []
    };

    exports.vue = {
        data: data,
        methods: {
            go: go,
            edit: edit,
            remove: remove,
            resetForm: resetForm,
            save: save,
            visibleRewards: visibleRewards
        },
        onReady: initialize
    };

    exports.onReady = function () {};

    function initialize() {
        api = exports.getComponent("credit-admin-api");
        router = exports.getShellComponent("soss-routes");
        if (!api) {
            fail("Credit administration service is not loaded.");
            return;
        }
        load();
    }

    function load() {
        api.services.Bootstrap({}).then(function (response) {
            if (!response.success) {
                fail(message(response));
                return;
            }
            data.rewards = response.result.rewards || [];
            data.programs = response.result.programs || [];
            applyDefaults();
        }).error(function () {
            fail("Reward rules could not be loaded.");
        });
    }

    function applyDefaults() {
        if (!data.form.program_id && data.programs.length) {
            data.form.program_id = data.programs[0].id;
            data.form.timezone = data.programs[0].timezone || data.form.timezone;
        }
    }

    function visibleRewards() {
        var needle = data.search.toLowerCase().trim();
        if (!needle) {
            return data.rewards;
        }
        return data.rewards.filter(function (item) {
            return [item.rule_code, item.title, item.cadence, item.status].join(" ").toLowerCase().indexOf(needle) >= 0;
        });
    }

    function edit(item) {
        data.form = JSON.parse(JSON.stringify(item));
        data.form.active_from = inputDate(data.form.active_from);
        data.form.active_until = inputDate(data.form.active_until);
        if (data.form.eligibility_json && typeof data.form.eligibility_json !== "string") {
            data.form.eligibility_json = JSON.stringify(data.form.eligibility_json, null, 2);
        }
        clearMessages();
        window.scrollTo(0, 0);
    }

    function resetForm() {
        data.form = emptyForm();
        clearMessages();
        applyDefaults();
    }

    function save() {
        if (data.busy) {
            return;
        }
        if (data.form.eligibility_json) {
            try {
                JSON.parse(data.form.eligibility_json);
            } catch (error) {
                fail("Eligibility JSON must be valid JSON.");
                return;
            }
        }
        data.busy = true;
        clearMessages();
        var payload = JSON.parse(JSON.stringify(data.form));
        payload.active_from = serverDate(payload.active_from);
        payload.active_until = serverDate(payload.active_until);
        api.services.SaveRewardRule(payload).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            resetFormKeepingMessage(data.form.id ? "Reward rule updated." : "Reward rule created.");
            load();
        }).error(function () {
            data.busy = false;
            fail("The reward rule could not be saved.");
        });
    }

    function remove(item) {
        if (data.busy || !window.confirm("Delete reward rule " + item.rule_code + "? Claimed rules will be archived for audit history.")) {
            return;
        }
        data.busy = true;
        clearMessages();
        api.services.DeleteRewardRule({id: item.id}).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            if (parseInt(data.form.id || 0, 10) === parseInt(item.id, 10)) {
                data.form = emptyForm();
                applyDefaults();
            }
            data.info = [response.result.message || "Reward rule deleted."];
            load();
        }).error(function () {
            data.busy = false;
            fail("The reward rule could not be deleted.");
        });
    }

    function go(path) {
        if (router && router.appNavigate) {
            router.appNavigate("/" + path);
        }
    }

    function inputDate(value) {
        return value ? String(value).replace(" ", "T").slice(0, 16) : "";
    }

    function serverDate(value) {
        return value ? String(value).replace("T", " ") + (String(value).length === 16 ? ":00" : "") : "";
    }

    function message(response) {
        return response.result && response.result.message ? response.result.message : "Request failed.";
    }

    function clearMessages() {
        data.errors = [];
        data.info = [];
    }

    function resetFormKeepingMessage(notice) {
        data.form = emptyForm();
        applyDefaults();
        data.info = [notice];
    }

    function fail(text) {
        data.errors = [text];
        data.info = [];
    }
});
