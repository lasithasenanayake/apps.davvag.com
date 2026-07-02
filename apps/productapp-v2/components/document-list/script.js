WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;
    var routeData = {};

    var bindData = {
        errors: [],
        loading: false,
        SearchItem: "",
        SearchColumn: "all",
        type: "po",
        title: "Purchase Orders",
        subtitle: "Search purchase orders and inspect line items.",
        icon: "fa-file-text-o",
        allRecords: [],
        records: [],
        Message: "Loading documents..."
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: refresh,
            applyFilter: applyFilter,
            clearSearch: clearSearch,
            toggleDetails: toggleDetails,
            goInventory: goInventory,
            editRecord: editRecord,
            viewRecord: viewRecord,
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
        routeData = routeHandler && routeHandler.getInputData ? routeHandler.getInputData() || {} : {};
        configureFromRoute();
        refresh();
    }

    function configureFromRoute(){
        var href = window.location.href.toLowerCase();
        bindData.type = href.indexOf("grn-list") >= 0 || routeData.type === "grn" ? "grn" : "po";
        if(bindData.type === "grn"){
            bindData.title = "GRN List";
            bindData.subtitle = "Search goods receive notes and inspect received line items.";
            bindData.icon = "fa-truck";
        }
    }

    function refresh(){
        clearMessages();
        bindData.loading = true;
        bindData.Message = "Loading " + bindData.title.toLowerCase() + "...";
        productHandler.services.DocumentList({type: bindData.type, limit: 200})
            .then(function(response){
                bindData.loading = false;
                if(response.success){
                    bindData.allRecords = (response.result || []).map(prepareRecord);
                    applyFilter();
                }else{
                    bindData.records = [];
                    setError(response.error || "Document list failed to load.");
                }
            })
            .error(function(error){
                bindData.loading = false;
                bindData.records = [];
                setError(error && error.responseJSON ? error.responseJSON.result : "Document list failed to load.");
            });
    }

    function prepareRecord(record){
        record = record || {};
        record.InvoiceItems = record.InvoiceItems || [];
        record._open = false;
        return record;
    }

    function applyFilter(){
        var term = (bindData.SearchItem || "").toString().trim().toLowerCase();
        var column = bindData.SearchColumn || "all";
        bindData.records = bindData.allRecords.filter(function(record){
            if(term === ""){
                return true;
            }
            if(column === "all"){
                return searchableText(record).indexOf(term) >= 0;
            }
            return recordValue(record, column).toLowerCase().indexOf(term) >= 0;
        });
        bindData.Message = bindData.records.length === 0
            ? "No " + bindData.title.toLowerCase() + " found."
            : "Showing " + bindData.records.length + " of " + bindData.allRecords.length + " " + bindData.title.toLowerCase() + ".";
    }

    function clearSearch(){
        bindData.SearchItem = "";
        bindData.SearchColumn = "all";
        applyFilter();
    }

    function toggleDetails(record){
        record._open = !record._open;
    }

    function goInventory(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate("../inventory");
        }
    }

    function editRecord(record){
        navigateDocument(record, false);
    }

    function viewRecord(record){
        navigateDocument(record, true);
    }

    function navigateDocument(record, viewOnly){
        if(!routeHandler || !routeHandler.appNavigate || !record || !record.tranNo){
            return;
        }
        var path = bindData.type === "grn" ? "../grn" : "../po";
        if(viewOnly){
            path += "-view";
        }
        routeHandler.appNavigate(path + "?tid=" + encodeURIComponent(record.tranNo));
    }

    function searchableText(record){
        var text = [
            recordValue(record, "tranNo"),
            recordValue(record, "profileId"),
            recordValue(record, "name"),
            recordValue(record, "email"),
            recordValue(record, "status"),
            recordValue(record, "Complete"),
            recordValue(record, "total")
        ];
        (record.InvoiceItems || []).forEach(function(line){
            text.push(recordValue(line, "itemid"));
            text.push(recordValue(line, "name"));
            text.push(recordValue(line, "uom"));
        });
        return text.join(" ").toLowerCase();
    }

    function recordValue(record, field){
        if(!record || !field){
            return "";
        }
        return record[field] === undefined || record[field] === null ? "" : record[field].toString();
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
        link.href = "components/productapp-v2/product-style/file/product-common.css?v=0.6";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message){
        bindData.errors.push(message);
        bindData.Message = message;
    }

    function clearMessages(){
        bindData.errors = [];
    }
});
