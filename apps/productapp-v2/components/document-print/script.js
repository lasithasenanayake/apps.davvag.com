WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;
    var routeData = {};

    var bindData = {
        type: "po",
        title: "Purchase Order",
        icon: "fa-file-text-o",
        loading: false,
        errors: [],
        Message: "Loading document...",
        document: emptyDocument()
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToList: backToList,
            printDocument: printDocument,
            formatMoney: formatMoney
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
        routeData = routeHandler && routeHandler.getInputData ? routeHandler.getInputData() || {} : {};
        configureFromRoute();
        loadDocument();
    }

    function configureFromRoute(){
        var href = window.location.href.toLowerCase();
        bindData.type = href.indexOf("/grn-view") >= 0 || routeData.type === "grn" ? "grn" : "po";
        if(bindData.type === "grn"){
            bindData.title = "Goods Receive Note";
            bindData.icon = "fa-truck";
        }
    }

    function loadDocument(){
        var tranNo = documentNumberFromRoute();
        if(!tranNo){
            bindData.Message = "Document number was not found.";
            setError("Document number was not found in the route.");
            return;
        }
        bindData.loading = true;
        bindData.Message = "Loading document #" + tranNo + "...";
        productHandler.services.DocumentDetails({ type: bindData.type, tranNo: tranNo })
            .then(function(response){
                bindData.loading = false;
                if(response.success){
                    bindData.document = normalizeDocument(response.result || {});
                    bindData.Message = bindData.title + " #" + bindData.document.tranNo + " is ready to print.";
                }else{
                    setError(response.error || "Document failed to load.");
                }
            })
            .error(function(error){
                bindData.loading = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Document failed to load.");
            });
    }

    function documentNumberFromRoute(){
        var value = routeData.tid || routeData.tranNo || routeData.id || "";
        if(!value){
            var match = window.location.href.match(/[?&](tid|tranNo|id)=([^&]+)/i);
            value = match ? decodeURIComponent(match[2]) : "";
        }
        return parseInt(value || 0, 10);
    }

    function emptyDocument(){
        return {
            tranNo: 0,
            poid: 0,
            tranDate: "",
            invoiceDueDate: "",
            profileId: 0,
            name: "",
            email: "",
            contactno: "",
            address: "",
            city: "",
            country: "",
            taxid: 0,
            taxcode: "",
            taxname: "",
            subtotal: 0,
            tax: 0,
            taxamount: 0,
            total: 0,
            paidamount: 0,
            balance: 0,
            status: "Approved",
            Complete: "N",
            remarks: "",
            InvoiceItems: []
        };
    }

    function normalizeDocument(document){
        var base = emptyDocument();
        Object.keys(document || {}).forEach(function(key){
            base[key] = document[key];
        });
        base.InvoiceItems = base.InvoiceItems || [];
        base.InvoiceItems.forEach(function(line){
            line.qty = parseFloat(line.qty || 0);
            line.price = parseFloat(line.price || 0);
            line.total = round(line.qty * line.price);
        });
        base.tax = parseFloat(base.tax || 0);
        return base;
    }

    function printDocument(){
        window.print();
    }

    function backToList(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate(bindData.type === "grn" ? "../grn-list" : "../po-list");
        }
    }

    function formatMoney(value){
        var amount = parseFloat(value || 0);
        if(isNaN(amount)){
            amount = 0;
        }
        return amount.toFixed(2);
    }

    function round(value){
        return Math.round((parseFloat(value || 0) + Number.EPSILON) * 100) / 100;
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
});
