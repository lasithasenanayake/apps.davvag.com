WEBDOCK.component().register(function (exports) {
    var productHandler;

    var bindData = {
        errors: [],
        SearchItem: "",
        SearchColumn: "all",
        TypeFilter: "all",
        allProducts: [],
        products: [],
        loading: false,
        Message: "Search or select a product."
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: loadProducts,
            searchProducts: applyProductFilter,
            clearSearch: clearSearch,
            selectProduct: selectProduct,
            productImage: productImage,
            productStatus: productStatus,
            formatMoney: formatMoney
        },
        onReady: function (scope, popupInstance) {
            initialize(popupInstance);
        }
    };

    exports.onReady = function () {};

    function initialize(popupInstance) {
        ensureProductStyles();
        productHandler = exports.getComponent("product");
        if (!productHandler || !productHandler.transformers || !productHandler.transformers.allProducts) {
            setError("Product service is not loaded.");
            return;
        }
        applyInput(readInputData(popupInstance));
        loadProducts();
    }

    function readInputData(popupInstance) {
        if (popupInstance && popupInstance.data) {
            return popupInstance.data || {};
        }
        var routes = exports.getShellComponent("soss-routes");
        if (routes && routes.getInputData) {
            return routes.getInputData() || {};
        }
        return {};
    }

    function applyInput(input) {
        input = input || {};
        bindData.SearchItem = input.search || input.q || "";
        bindData.SearchColumn = input.column || "all";
        bindData.TypeFilter = normalizeType(input.invType || input.type || "all") || "all";
    }

    function loadProducts() {
        clearMessages();
        bindData.loading = true;
        bindData.Message = "Loading products...";

        productHandler.transformers.allProducts()
            .then(function (response) {
                bindData.loading = false;
                if (response.success) {
                    bindData.allProducts = [];
                    (response.result || []).forEach(function (item) {
                        bindData.allProducts.push(mapProduct(item));
                    });
                    applyProductFilter();
                } else {
                    bindData.products = [];
                    setError(response.error || "Product search failed.");
                }
            })
            .error(function (error) {
                bindData.loading = false;
                bindData.products = [];
                setError(error && error.responseJSON ? error.responseJSON.result : "Product search failed.");
            });
    }

    function mapProduct(item) {
        item = item || {};
        var product = {
            itemid: item.itemid || "",
            id: item.itemid || "",
            product_id: item.itemid || "",
            product_code: item.itemid ? String(item.itemid) : "",
            product_title: item.name || "",
            product_price: item.price || 0,
            product_currency_code: item.currencycode || "",
            name: item.name || "",
            image: productImage(item),
            caption: stripHtml(item.caption || ""),
            keywords: item.keywords || "",
            price: item.price || 0,
            cost: item.cost || 0,
            discountper: item.discountper || 0,
            currencycode: item.currencycode || "",
            imgurl: item.imgurl || "",
            uom: item.uom || "",
            catogory: item.catogory || "",
            catogoryid: item.catogoryid || "",
            invType: item.invType || "",
            showonstore: item.showonstore || "",
            sellstype: item.sellstype || "",
            qty: item.qty,
            reorder_qty: item.reorder_qty,
            storeid: item.storeid,
            storename: item.storename || "",
            sysviewobject: item.sysviewobject
        };
        product.category = product.catogory;
        return product;
    }

    function selectProduct(product) {
        if (product && product.itemid) {
            exports.Complete(product);
        }
    }

    function applyProductFilter() {
        var term = (bindData.SearchItem || "").toString().trim().toLowerCase();
        var column = (bindData.SearchColumn || "all").toString();
        var typeFilter = normalizeType(bindData.TypeFilter || "all");

        bindData.products = bindData.allProducts.filter(function (product) {
            if (typeFilter !== "all" && normalizeType(product.invType) !== typeFilter) {
                return false;
            }

            if (term !== "") {
                if (column === "all") {
                    return searchableText(product).indexOf(term) >= 0;
                }
                return recordValue(product, column).toLowerCase().indexOf(term) >= 0;
            }

            return true;
        });

        bindData.Message = bindData.products.length === 0
            ? "No products found."
            : "Showing " + bindData.products.length + " of " + bindData.allProducts.length + " products.";
    }

    function clearSearch() {
        bindData.SearchItem = "";
        bindData.SearchColumn = "all";
        bindData.TypeFilter = "all";
        applyProductFilter();
    }

    function searchableText(product) {
        return [
            recordValue(product, "itemid"),
            recordValue(product, "name"),
            recordValue(product, "catogory"),
            recordValue(product, "uom"),
            recordValue(product, "invType"),
            recordValue(product, "showonstore"),
            recordValue(product, "sellstype"),
            recordValue(product, "keywords"),
            recordValue(product, "caption")
        ].join(" ").toLowerCase();
    }

    function recordValue(record, field) {
        if (!record || !field) {
            return "";
        }
        return record[field] === undefined || record[field] === null ? "" : record[field].toString();
    }

    function productImage(product) {
        if (!product || !product.imgurl || !product.itemid) {
            return "assets/productapp-v2/appicon.png";
        }
        return "components/dock/soss-uploader/service/get/products/" + product.itemid + "-" + product.imgurl;
    }

    function productStatus(product) {
        if (!product) {
            return "Not set";
        }
        return product.showonstore === "Y" ? "Store" : "Hidden";
    }

    function formatMoney(value, currency) {
        var amount = parseFloat(value || 0);
        if (isNaN(amount)) {
            amount = 0;
        }
        return (currency || "") + " " + amount.toFixed(2);
    }

    function normalizeType(value) {
        value = (value || "").toString().trim().toLowerCase();
        if (value === "" || value === "all") {
            return "all";
        }
        if (value === "inventry") {
            return "inventory";
        }
        return value;
    }

    function stripHtml(value) {
        return value.toString().replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
    }

    function ensureProductStyles() {
        if (document.getElementById("productapp-v2-common-css")) {
            return;
        }
        var link = document.createElement("link");
        link.id = "productapp-v2-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/productapp-v2/product-style/file/product-common.css?v=0.3";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message) {
        bindData.errors.push(message);
        bindData.Message = message;
    }

    function clearMessages() {
        bindData.errors = [];
    }
});
