WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;

    var bindData = {
        loading: false,
        errors: [],
        info: [],
        totals: {},
        stockProducts: [],
        lowStock: [],
        openPurchaseOrders: [],
        recentGrns: [],
        recentMovements: [],
        barcodeUnits: [],
        lookup: { value: "" },
        Message: "Loading inventory dashboard..."
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: refresh,
            navigateProducts: navigateProducts,
            navigatePoApp: navigatePoApp,
            navigateGrnApp: navigateGrnApp,
            navigateIssueApp: navigateIssueApp,
            navigateAdjustmentApp: navigateAdjustmentApp,
            navigatePayment: navigatePayment,
            navigatePoList: navigatePoList,
            navigateGrnList: navigateGrnList,
            navigateUom: navigateUom,
            navigateGrnAppForPo: navigateGrnAppForPo,
            lookupProduct: lookupProduct,
            stockStatus: stockStatus,
            stockStatusClass: stockStatusClass,
            formatMoney: formatMoney,
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

    function refresh(){
        clearMessages();
        bindData.loading = true;
        bindData.Message = "Loading inventory dashboard...";
        productHandler.services.InventoryDashboard({})
            .then(function(response){
                bindData.loading = false;
                if(response.success){
                    applyDashboard(response.result || {});
                    bindData.Message = "Inventory dashboard ready.";
                }else{
                    setError(response.error || "Inventory dashboard failed to load.");
                }
            })
            .error(function(error){
                bindData.loading = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Inventory dashboard failed to load.");
            });
    }

    function applyDashboard(data){
        bindData.totals = data.totals || {};
        bindData.stockProducts = data.products || [];
        bindData.lowStock = data.lowStock || [];
        bindData.openPurchaseOrders = data.openPurchaseOrders || [];
        bindData.recentGrns = data.recentGrns || [];
        bindData.recentMovements = data.recentMovements || [];
        bindData.barcodeUnits = data.barcodeUnits || [];
    }

    function navigateProducts(){
        navigate("..");
    }

    function navigatePoApp(){
        navigate("../po-app");
    }

    function navigateGrnApp(){
        navigate("../grn-app");
    }

    function navigateIssueApp(){
        navigate("../issue-app");
    }

    function navigateAdjustmentApp(){
        navigate("../adjustment-app");
    }

    function navigatePayment(){
        navigate("../payment");
    }

    function navigatePoList(){
        navigate("../po-list");
    }

    function navigateGrnList(){
        navigate("../grn-list");
    }

    function navigateUom(){
        navigate("../uom");
    }

    function navigateGrnAppForPo(po){
        navigate("../grn-app?poid=" + encodeURIComponent(po && po.tranNo ? po.tranNo : ""));
    }

    function navigate(path){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate(path);
        }
    }

    function lookupProduct(){
        clearMessages();
        var value = (bindData.lookup.value || "").toString().trim();
        if(value === ""){
            setError("Scan or enter a product code.");
            return;
        }
        productHandler.services.ProductLookup({ column: "all", value: value })
            .then(function(response){
                if(response.success){
                    var products = response.result || [];
                    if(products.length === 1){
                        var product = products[0];
                        setInfo("Found #" + product.itemid + " " + product.name + " with " + number(product.qty) + " " + (product.uom || "") + " on hand.");
                    }else if(products.length > 1){
                        setInfo("Found " + products.length + " matching products.");
                    }else{
                        setError("No product found for " + value + ".");
                    }
                }else{
                    setError(response.error || "Product lookup failed.");
                }
            })
            .error(function(error){
                setError(error && error.responseJSON ? error.responseJSON.result : "Product lookup failed.");
            });
    }

    function stockStatus(product){
        var qty = parseFloat(product.stockQty || product.qty || 0);
        var reorder = parseFloat(product.reorder_qty || 0);
        if(qty <= 0){
            return "Out";
        }
        if(reorder > 0 && qty <= reorder){
            return "Low";
        }
        return "OK";
    }

    function stockStatusClass(product){
        var status = stockStatus(product);
        if(status === "Out"){
            return "label label-danger";
        }
        if(status === "Low"){
            return "label label-warning";
        }
        return "label label-success";
    }

    function formatMoney(value){
        var amount = parseFloat(value || 0);
        if(isNaN(amount)){
            amount = 0;
        }
        return amount.toFixed(2);
    }

    function number(value){
        var amount = parseFloat(value || 0);
        if(isNaN(amount)){
            amount = 0;
        }
        return amount.toFixed(2).replace(/\.00$/, "");
    }

    function ensureProductStyles(){
        if(document.getElementById("productapp-v2-common-css")){
            return;
        }
        var link = document.createElement("link");
        link.id = "productapp-v2-common-css";
        link.rel = "stylesheet";
        link.type = "text/css";
        link.href = "components/productapp-v2/product-style/file/product-common.css?v=0.8";
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
