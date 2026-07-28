WEBDOCK.component().register(function (exports) {
    var api;
    var router;

    function emptyForm() {
        return {
            id: "",
            program_id: "",
            campaign_code: "",
            coupon_type: "GIFT",
            name: "",
            description: "",
            credit_amount: "",
            total_redemption_limit: 0,
            per_profile_limit: 1,
            first_time_only: "false",
            minimum_account_age_days: 0,
            eligible_group_ids_json: "",
            active_from: "",
            active_until: "",
            credit_expiry_days: 0,
            status: "ACTIVE"
        };
    }

    var data = {
        campaigns: [],
        couponCodes: [],
        programs: [],
        form: emptyForm(),
        generator: {campaign_id: "", count: 1, maximum_redemptions: 1, assigned_profile_id: 0, expires_at: ""},
        generatedCodes: [],
        campaignSearch: "",
        codeSearch: "",
        busy: false,
        errors: [],
        info: []
    };

    exports.vue = {
        data: data,
        methods: {
            go: go,
            edit: edit,
            removeCampaign: removeCampaign,
            removeCode: removeCode,
            resetForm: resetForm,
            save: save,
            generate: generate,
            visibleCampaigns: visibleCampaigns,
            visibleCodes: visibleCodes
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
            data.campaigns = response.result.campaigns || [];
            data.couponCodes = response.result.couponCodes || [];
            data.programs = response.result.programs || [];
            applyDefaults();
        }).error(function () {
            fail("Coupon administration data could not be loaded.");
        });
    }

    function applyDefaults() {
        if (!data.form.program_id && data.programs.length) {
            data.form.program_id = data.programs[0].id;
        }
        if (!data.generator.campaign_id && data.campaigns.length) {
            data.generator.campaign_id = data.campaigns[0].id;
        }
    }

    function visibleCampaigns() {
        var needle = data.campaignSearch.toLowerCase().trim();
        if (!needle) {
            return data.campaigns;
        }
        return data.campaigns.filter(function (item) {
            return [item.campaign_code, item.name, item.coupon_type, item.status].join(" ").toLowerCase().indexOf(needle) >= 0;
        });
    }

    function visibleCodes() {
        var needle = data.codeSearch.toLowerCase().trim();
        if (!needle) {
            return data.couponCodes;
        }
        return data.couponCodes.filter(function (item) {
            return [item.masked_code, item.campaign_code, item.campaign_name, item.status, item.assigned_profile_id].join(" ").toLowerCase().indexOf(needle) >= 0;
        });
    }

    function edit(item) {
        data.form = JSON.parse(JSON.stringify(item));
        data.form.active_from = inputDate(data.form.active_from);
        data.form.active_until = inputDate(data.form.active_until);
        if (data.form.eligible_group_ids_json && typeof data.form.eligible_group_ids_json !== "string") {
            data.form.eligible_group_ids_json = JSON.stringify(data.form.eligible_group_ids_json);
        }
        data.generator.campaign_id = item.id;
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
        if (data.form.eligible_group_ids_json) {
            try {
                JSON.parse(data.form.eligible_group_ids_json);
            } catch (error) {
                fail("Eligible group IDs must be valid JSON.");
                return;
            }
        }
        data.busy = true;
        clearMessages();
        var payload = JSON.parse(JSON.stringify(data.form));
        payload.active_from = serverDate(payload.active_from);
        payload.active_until = serverDate(payload.active_until);
        api.services.SaveCouponCampaign(payload).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            var notice = data.form.id ? "Coupon campaign updated." : "Coupon campaign created.";
            data.form = emptyForm();
            data.info = [notice];
            load();
        }).error(function () {
            data.busy = false;
            fail("The coupon campaign could not be saved.");
        });
    }

    function removeCampaign(item) {
        if (data.busy || !window.confirm("Delete coupon campaign " + item.campaign_code + "? Generated or redeemed coupons will be disabled and retained for audit history.")) {
            return;
        }
        data.busy = true;
        clearMessages();
        api.services.DeleteCouponCampaign({id: item.id}).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            if (parseInt(data.form.id || 0, 10) === parseInt(item.id, 10)) {
                data.form = emptyForm();
            }
            data.generator.campaign_id = "";
            data.info = [response.result.message || "Coupon campaign deleted."];
            load();
        }).error(function () {
            data.busy = false;
            fail("The coupon campaign could not be deleted.");
        });
    }

    function removeCode(item) {
        if (data.busy || !window.confirm("Delete coupon " + item.masked_code + "? Redeemed coupons will be disabled and retained for audit history.")) {
            return;
        }
        data.busy = true;
        clearMessages();
        api.services.DeleteCouponCode({id: item.id}).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            data.info = [response.result.message || "Coupon deleted."];
            load();
        }).error(function () {
            data.busy = false;
            fail("The coupon could not be deleted.");
        });
    }

    function generate() {
        if (data.busy) {
            return;
        }
        data.busy = true;
        clearMessages();
        var payload = JSON.parse(JSON.stringify(data.generator));
        payload.expires_at = serverDate(payload.expires_at);
        api.services.GenerateCoupons(payload).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            data.generatedCodes = response.result.codes || [];
            data.info = [response.result.warning || "Coupon codes generated."];
            load();
        }).error(function () {
            data.busy = false;
            fail("Coupons could not be generated.");
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

    function fail(text) {
        data.errors = [text];
        data.info = [];
    }
});
