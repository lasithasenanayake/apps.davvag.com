WEBDOCK.component().register(function (exports) {
    var api;
    var router;

    function emptyForm() {
        return {
            id: "",
            program_id: "",
            package_code: "",
            title: "",
            description: "",
            credit_amount: "",
            bonus_credit_amount: 0,
            price_minor: "",
            currency: "",
            payment_channel: "EXTERNAL",
            provider_product_id: "",
            product_id: 0,
            mapped_product_name: "",
            purchase_limit_per_profile: 0,
            first_purchase_only: "false",
            active_from: "",
            active_until: "",
            sort_order: 1,
            status: "ACTIVE"
        };
    }

    var data = {
        packages: [],
        programs: [],
        currencies: [],
        defaultCurrency: null,
        form: emptyForm(),
        search: "",
        products: [],
        productSearch: "",
        showProductPicker: false,
        loadingProducts: false,
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
            visiblePackages: visiblePackages,
            openProductPicker: openProductPicker,
            searchProducts: searchProducts,
            selectProduct: selectProduct,
            clearProduct: clearProduct,
            formatPrice: formatPrice,
            productImage: productImage
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
            data.packages = response.result.packages || [];
            data.programs = response.result.programs || [];
            data.currencies = response.result.currencies || [];
            data.defaultCurrency = response.result.defaultCurrency || null;
            if (!data.form.id) {
                applyDefaults();
            }
        }).error(function () {
            fail("Packages could not be loaded.");
        });
    }

    function applyDefaults() {
        if (!data.form.program_id && data.programs.length) {
            data.form.program_id = data.programs[0].id;
        }
        if (!data.form.currency && data.defaultCurrency) {
            data.form.currency = data.defaultCurrency.code;
        }
    }

    function visiblePackages() {
        var needle = data.search.toLowerCase().trim();
        if (!needle) {
            return data.packages;
        }
        return data.packages.filter(function (item) {
            return [item.package_code, item.title, item.mapped_product_name, item.currency, item.status].join(" ").toLowerCase().indexOf(needle) >= 0;
        });
    }

    function edit(item) {
        data.form = JSON.parse(JSON.stringify(item));
        data.form.product_id = parseInt(data.form.product_id || 0, 10);
        data.form.mapped_product_name = item.mapped_product_name || "";
        data.form.active_from = inputDate(data.form.active_from);
        data.form.active_until = inputDate(data.form.active_until);
        data.showProductPicker = false;
        clearMessages();
        window.scrollTo(0, 0);
    }

    function resetForm() {
        data.form = emptyForm();
        data.showProductPicker = false;
        data.products = [];
        data.productSearch = "";
        clearMessages();
        applyDefaults();
    }

    function save() {
        if (data.busy) {
            return;
        }
        data.busy = true;
        clearMessages();
        var payload = JSON.parse(JSON.stringify(data.form));
        delete payload.mapped_product_name;
        payload.active_from = serverDate(payload.active_from);
        payload.active_until = serverDate(payload.active_until);
        api.services.SavePackage(payload).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            data.info = [data.form.id ? "Package updated." : "Package created."];
            resetFormKeepingMessage();
            load();
        }).error(function () {
            data.busy = false;
            fail("The package could not be saved.");
        });
    }

    function remove(item) {
        if (data.busy || !window.confirm("Delete package " + item.package_code + "? Referenced packages will be archived for audit history.")) {
            return;
        }
        data.busy = true;
        clearMessages();
        api.services.DeletePackage({id: item.id}).then(function (response) {
            data.busy = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            data.info = [response.result.message || "Package deleted."];
            if (parseInt(data.form.id || 0, 10) === parseInt(item.id, 10)) {
                resetFormKeepingMessage();
            }
            load();
        }).error(function () {
            data.busy = false;
            fail("The package could not be deleted.");
        });
    }

    function openProductPicker() {
        data.showProductPicker = true;
        searchProducts();
    }

    function searchProducts() {
        data.loadingProducts = true;
        api.services.ProductCatalog({search: data.productSearch, limit: 100}).then(function (response) {
            data.loadingProducts = false;
            if (!response.success) {
                fail(message(response));
                return;
            }
            data.products = response.result || [];
        }).error(function () {
            data.loadingProducts = false;
            fail("Products could not be loaded.");
        });
    }

    function selectProduct(product) {
        data.form.product_id = product.product_id;
        data.form.mapped_product_name = product.product_title;
        data.showProductPicker = false;
    }

    function clearProduct() {
        data.form.product_id = 0;
        data.form.mapped_product_name = "";
        data.showProductPicker = false;
    }

    function formatPrice(item) {
        var currency = null;
        data.currencies.forEach(function (candidate) {
            if (candidate.code === item.currency) {
                currency = candidate;
            }
        });
        var places = currency && currency.decimalPlaces !== undefined && currency.decimalPlaces !== null ? parseInt(currency.decimalPlaces, 10) : 2;
        if (isNaN(places)) {
            places = 2;
        }
        var divisor = Math.pow(10, places);
        var amount = parseInt(item.price_minor || 0, 10) / divisor;
        return (currency ? (currency.symbol || currency.code) : item.currency) + " " + amount.toFixed(places);
    }

    function productImage(product) {
        return product.image || "assets/productapp/appicon.png";
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

    function resetFormKeepingMessage() {
        var info = data.info.slice(0);
        data.form = emptyForm();
        data.showProductPicker = false;
        data.info = info;
        applyDefaults();
    }

    function fail(text) {
        data.errors = [text];
        data.info = [];
    }
});
