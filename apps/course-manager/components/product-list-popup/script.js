WEBDOCK.component().register(function (exports) {
    var api;

    var bindData = {
        errors: [],
        loading: false,
        search: "",
        products: [],
        message: "Search or select a product."
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadProducts,
            searchProducts: loadProducts,
            selectProduct: selectProduct,
            productImage: productImage,
            formatMoney: formatMoney
        },
        onReady: function () {
            initialize();
        }
    };

    exports.onReady = function () {};

    function initialize() {
        ensureCourseStyles();
        api = exports.getComponent("api");
        if (!api) {
            setError("Course Manager service is not loaded.");
            return;
        }
        loadProducts();
    }

    function loadProducts() {
        clearMessages();
        bindData.loading = true;
        bindData.message = "Loading products...";
        api.services.ProductCatalog({search: bindData.search}).then(function (response) {
            bindData.loading = false;
            if (response.success) {
                bindData.products = response.result || [];
                bindData.message = bindData.products.length === 0 ? "No products found." : "Showing " + bindData.products.length + " products.";
            } else {
                bindData.products = [];
                setError(response.result && response.result.message ? response.result.message : "Product search failed.");
            }
        }).error(function () {
            bindData.loading = false;
            bindData.products = [];
            setError("Product search failed.");
        });
    }

    function selectProduct(product) {
        if (product && product.product_id) {
            exports.Complete(product);
        }
    }

    function productImage(product) {
        return product && product.image ? product.image : "assets/productapp-v2/appicon.png";
    }

    function formatMoney(value, currency) {
        var amount = parseFloat(value || 0);
        if (isNaN(amount)) {
            amount = 0;
        }
        return (currency || "") + " " + amount.toFixed(2);
    }

    function ensureCourseStyles() {
        if (document.getElementById("course-manager-common-css")) {
            return;
        }
        var link = document.createElement("link");
        link.id = "course-manager-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/course-manager/course-style/file/course-manager.css?v=0.7";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message) {
        bindData.errors.push(message);
        bindData.message = message;
    }

    function clearMessages() {
        bindData.errors = [];
    }
});
