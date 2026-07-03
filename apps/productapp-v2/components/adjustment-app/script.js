WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;

    var bindData = {
        loading: false,
        saving: false,
        errors: [],
        info: [],
        Message: "Loading adjustment app...",
        productSearch: { value: "" },
        productResults: [],
        recentMovements: [],
        adjustment: emptyAdjustment()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: refresh,
            backToInventory: backToInventory,
            searchProducts: searchProducts,
            selectProduct: selectProduct,
            saveAdjustment: saveAdjustment,
            number: number
        },
        onReady: function(){
            initialize();
        }
    };

    exports.onReady = function(){};

    function initialize(){
        ensureProductStyles();
        productHandler = exports.getComponent("product");
        routeHandler = exports.getShellComponent("soss-routes");
        refresh();
    }

    function emptyAdjustment(){
        return {
            itemid: 0,
            name: "",
            uom: "",
            qty: "",
            reason: ""
        };
    }

    function refresh(){
        clearMessages();
        bindData.loading = true;
        productHandler.services.InventoryDashboard({})
            .then(function(response){
                bindData.loading = false;
                if(response.success){
                    bindData.recentMovements = (response.result && response.result.recentMovements) ? response.result.recentMovements : [];
                    bindData.Message = "Adjustment app ready.";
                }else{
                    setError(response.error || "Adjustment app failed to load.");
                }
            })
            .error(function(error){
                bindData.loading = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Adjustment app failed to load.");
            });
    }

    function searchProducts(){
        clearMessages();
        var term = (bindData.productSearch.value || "").toString().trim();
        if(term === ""){
            setError("Scan or enter a product search value.");
            return;
        }
        productHandler.services.ProductLookup({ column: "all", value: term })
            .then(function(response){
                if(response.success){
                    bindData.productResults = response.result || [];
                    if(bindData.productResults.length === 1){
                        selectProduct(bindData.productResults[0]);
                    }else if(bindData.productResults.length === 0){
                        setError("No inventory products found.");
                    }
                }else{
                    setError(response.error || "Product lookup failed.");
                }
            })
            .error(function(error){
                setError(error && error.responseJSON ? error.responseJSON.result : "Product lookup failed.");
            });
    }

    function selectProduct(product){
        if(!product){
            return;
        }
        bindData.adjustment.itemid = product.itemid || 0;
        bindData.adjustment.name = product.name || "";
        bindData.adjustment.uom = product.uom || "";
        bindData.productResults = [];
        bindData.productSearch.value = product.name || "";
    }

    function saveAdjustment(){
        clearMessages();
        if(!bindData.adjustment.itemid){
            setError("Select a product before saving the adjustment.");
            return;
        }
        if(parseFloat(bindData.adjustment.qty || 0) === 0){
            setError("Adjustment quantity cannot be zero.");
            return;
        }
        bindData.saving = true;
        productHandler.services.StockAdjustment(clone(bindData.adjustment))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("Stock adjustment saved.");
                    bindData.adjustment = emptyAdjustment();
                    bindData.productSearch.value = "";
                    refresh();
                }else{
                    setError(response.error || "Stock adjustment failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Stock adjustment failed.");
            });
    }

    function backToInventory(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate("../inventory");
        }
    }

    function number(value){
        var amount = parseFloat(value || 0);
        if(isNaN(amount)){
            amount = 0;
        }
        return amount.toFixed(2).replace(/\.00$/, "");
    }

    function clone(obj){
        return JSON.parse(JSON.stringify(obj || {}));
    }

    function ensureProductStyles(){
        if(document.getElementById("productapp-v2-common-css")){
            return;
        }
        var link = document.createElement("link");
        link.id = "productapp-v2-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/productapp-v2/product-style/file/product-common.css?v=0.7";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message){
        bindData.errors.push(message);
        bindData.Message = message;
    }

    function setInfo(message){
        bindData.info.push(message);
    }

    function clearMessages(){
        bindData.errors = [];
        bindData.info = [];
    }
});
