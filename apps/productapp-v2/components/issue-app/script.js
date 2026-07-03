WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;

    var bindData = {
        loading: false,
        saving: false,
        errors: [],
        info: [],
        Message: "Loading issue app...",
        barcodeUnits: [],
        issue: emptyIssue()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: refresh,
            backToInventory: backToInventory,
            issueGoods: issueGoods,
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

    function emptyIssue(){
        return {
            barcode: "",
            issuedTo: "",
            issuedRef: "",
            remarks: ""
        };
    }

    function refresh(){
        clearMessages();
        bindData.loading = true;
        productHandler.services.InventoryDashboard({})
            .then(function(response){
                bindData.loading = false;
                if(response.success){
                    bindData.barcodeUnits = (response.result && response.result.barcodeUnits) ? response.result.barcodeUnits : [];
                    bindData.Message = "Issue app ready.";
                }else{
                    setError(response.error || "Issue app failed to load.");
                }
            })
            .error(function(error){
                bindData.loading = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Issue app failed to load.");
            });
    }

    function issueGoods(){
        clearMessages();
        if((bindData.issue.barcode || "").toString().trim() === ""){
            setError("Scan or enter a barcode to issue.");
            return;
        }
        bindData.saving = true;
        productHandler.services.IssueGoods(clone(bindData.issue))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("Barcode " + response.result.barcode + " issued.");
                    bindData.issue = emptyIssue();
                    refresh();
                }else{
                    setError(response.error || "Issue failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Issue failed.");
            });
    }

    function backToInventory(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate("../inventory");
        }
    }

    function formatMoney(value){
        var amount = parseFloat(value || 0);
        return isNaN(amount) ? "0.00" : amount.toFixed(2);
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
