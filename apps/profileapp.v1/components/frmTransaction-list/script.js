WEBDOCK.component().register(function(exports){
    var configs = {
        invoice: {
            type: "invoice",
            store: "orderheader",
            idField: "invoiceNo",
            dateField: "invoiceDate",
            profileColumn: "profileId",
            title: "Invoices",
            subtitle: "Latest invoice records with search and print-view access.",
            icon: "fa-file-text-o",
            idLabel: "Invoice #",
            dateLabel: "Invoice Date",
            primaryLabel: "Customer",
            secondaryLabel: "Contact",
            statusLabel: "Status",
            amountLabel: "Total",
            defaultColumn: "invoiceNo",
            columns: [
                { value: "invoiceNo", label: "Invoice #" },
                { value: "profileId", label: "Profile ID" },
                { value: "name", label: "Customer" },
                { value: "email", label: "Email" },
                { value: "contactno", label: "Contact no" },
                { value: "status", label: "Status" },
                { value: "preparedBy", label: "Prepared by" },
                { value: "PaymentComplete", label: "Payment complete" }
            ]
        },
        receipt: {
            type: "receipt",
            store: "paymentheader",
            idField: "receiptNo",
            dateField: "receiptDate",
            profileColumn: "profileId",
            title: "Receipts",
            subtitle: "Latest receipt records with search and print-view access.",
            icon: "fa-credit-card",
            idLabel: "Receipt #",
            dateLabel: "Receipt Date",
            primaryLabel: "Customer",
            secondaryLabel: "Payment Type",
            statusLabel: "Status",
            amountLabel: "Paid Amount",
            defaultColumn: "receiptNo",
            columns: [
                { value: "receiptNo", label: "Receipt #" },
                { value: "profileId", label: "Profile ID" },
                { value: "name", label: "Customer" },
                { value: "email", label: "Email" },
                { value: "contactno", label: "Contact no" },
                { value: "paymentType", label: "Payment type" },
                { value: "status", label: "Status" },
                { value: "collectedBy", label: "Collected by" }
            ]
        },
        deposit: {
            type: "deposit",
            store: "dipositheader",
            idField: "TranNo",
            dateField: "invoiceDate",
            profileColumn: "profileId",
            title: "Deposits",
            subtitle: "Latest deposit records with search and print-view access.",
            icon: "fa-archive",
            idLabel: "Deposit #",
            dateLabel: "Deposit Date",
            primaryLabel: "Customer",
            secondaryLabel: "Payment Type",
            statusLabel: "Status",
            amountLabel: "Total",
            defaultColumn: "TranNo",
            columns: [
                { value: "TranNo", label: "Deposit #" },
                { value: "profileId", label: "Profile ID" },
                { value: "name", label: "Customer" },
                { value: "email", label: "Email" },
                { value: "contactno", label: "Contact no" },
                { value: "paymenttype", label: "Payment type" },
                { value: "status", label: "Status" },
                { value: "preparedBy", label: "Prepared by" }
            ]
        },
        collection: {
            type: "collection",
            store: "ledger",
            idField: "tranid",
            dateField: "tranDate",
            profileColumn: "profileid",
            title: "Collections",
            subtitle: "Ledger collections and transaction history with linked print views.",
            icon: "fa-list",
            idLabel: "Transaction #",
            dateLabel: "Transaction Date",
            primaryLabel: "Description",
            secondaryLabel: "Profile ID",
            statusLabel: "Type",
            amountLabel: "Amount",
            defaultColumn: "tranid",
            columns: [
                { value: "tranid", label: "Transaction #" },
                { value: "profileid", label: "Profile ID" },
                { value: "trantype", label: "Type" },
                { value: "tranDate", label: "Date" },
                { value: "amount", label: "Amount" }
            ]
        }
    };

    var bindData = {
        type: "invoice",
        title: "Invoices",
        subtitle: "",
        icon: "fa-file-text-o",
        idLabel: "Invoice #",
        dateLabel: "Invoice Date",
        primaryLabel: "Customer",
        secondaryLabel: "Contact",
        statusLabel: "Status",
        amountLabel: "Total",
        columns: [],
        SearchColumn: "invoiceNo",
        SearchItem: "",
        records: [],
        total: 0,
        pageSize: 50,
        nextSysVersionId: null,
        hasMore: false,
        profileId: "",
        loading: false,
        loadingMore: false,
        Message: "Loading latest transactions..."
    };

    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data: bindData,
        methods: {
            loadLatest: loadLatest,
            loadMore: loadMore,
            searchRecords: searchRecords,
            clearSearch: clearSearch,
            openRecord: openRecord,
            canOpen: canOpen,
            recordId: recordId,
            recordDate: recordDate,
            recordPrimary: recordPrimary,
            recordSecondary: recordSecondary,
            recordStatus: recordStatus,
            recordAmount: recordAmount,
            recordCurrency: recordCurrency,
            formatMoney: formatMoney,
            formatDate: formatDate,
            statusClass: statusClass
        }
    };

    exports.vue = vueData;
    exports.onReady = function(element){};

    var profileHandler;
    var routeHandler;

    function initializeComponent(){
        profileHandler = exports.getComponent("profile");
        routeHandler = exports.getShellComponent("soss-routes");

        var routeData = {};
        try{
            routeData = routeHandler.getInputData() || {};
        }catch(e){
            routeData = {};
        }

        setConfig(resolveType(routeData));
        if(routeData.id || routeData.profileId){
            bindData.profileId = routeData.id || routeData.profileId;
            bindData.subtitle = bindData.subtitle + " Filtered by profile #" + bindData.profileId + ".";
        }
        loadRecords();
    }

    function resolveType(routeData){
        if(routeData && routeData.type){
            return normalizeType(routeData.type);
        }
        var hash = "";
        try{
            hash = (window.location.hash || "").toLowerCase();
        }catch(e){
            hash = "";
        }

        if(hash.indexOf("/receipts") !== -1 || hash.indexOf("/reciepts") !== -1){
            return "receipt";
        }
        if(hash.indexOf("/deposits") !== -1 || hash.indexOf("/deposit-list") !== -1){
            return "deposit";
        }
        if(hash.indexOf("/collections") !== -1 || hash.indexOf("/col") !== -1){
            return "collection";
        }
        return "invoice";
    }

    function normalizeType(type){
        type = (type || "invoice").toString().toLowerCase();
        if(type === "reciept"){
            return "receipt";
        }
        if(type === "diposit"){
            return "deposit";
        }
        if(!configs[type]){
            return "invoice";
        }
        return type;
    }

    function setConfig(type){
        var config = configs[normalizeType(type)];
        bindData.type = config.type;
        bindData.title = config.title;
        bindData.subtitle = config.subtitle;
        bindData.icon = config.icon;
        bindData.idLabel = config.idLabel;
        bindData.dateLabel = config.dateLabel;
        bindData.primaryLabel = config.primaryLabel;
        bindData.secondaryLabel = config.secondaryLabel;
        bindData.statusLabel = config.statusLabel;
        bindData.amountLabel = config.amountLabel;
        bindData.columns = config.columns;
        bindData.SearchColumn = config.defaultColumn;
    }

    function loadLatest(){
        bindData.SearchItem = "";
        loadRecords();
    }

    function searchRecords(){
        loadRecords();
    }

    function clearSearch(){
        bindData.SearchItem = "";
        loadRecords();
    }

    function loadRecords(){
        fetchRecords(false);
    }

    function loadMore(){
        if(bindData.loading || bindData.loadingMore || !bindData.hasMore || !bindData.nextSysVersionId){
            return;
        }
        fetchRecords(true);
    }

    function fetchRecords(append){
        bindData.loading = true;
        bindData.loadingMore = append;
        if(!append){
            bindData.records = [];
            bindData.nextSysVersionId = null;
            bindData.hasMore = false;
        }
        bindData.Message = (append ? "Loading older " : "Loading ") + bindData.title.toLowerCase() + " please wait...";
        if(typeof WEBDOCK !== "undefined" && WEBDOCK.freezeUiComponent){
            WEBDOCK.freezeUiComponent("soss-routes",true);
        }

        var request = {
            type: bindData.type,
            searchColumn: bindData.SearchColumn,
            searchValue: bindData.SearchItem,
            profileId: bindData.profileId,
            limit: bindData.pageSize,
            sysversionid: append ? bindData.nextSysVersionId : null
        };

        if(typeof profileHandler.services.TransactionList !== "function"){
            loadRecordsViaQuery(request,append);
            return;
        }

        profileHandler.services.TransactionList(request).then(function(response){
            bindData.loading = false;
            bindData.loadingMore = false;
            if(typeof WEBDOCK !== "undefined" && WEBDOCK.freezeUiComponent){
                WEBDOCK.freezeUiComponent("soss-routes",false);
            }
            if(response.success){
                var result = response.result || {};
                setRecords(result.records || [], result.total, append, result.hasMore, result.nextSysVersionId || result.sysversionid);
            }else{
                if(!append){
                    bindData.records = [];
                }
                bindData.hasMore = false;
                bindData.Message = response.error || "Unable to load " + bindData.title.toLowerCase() + ".";
                alert(bindData.Message);
            }
        }).error(function(error){
            bindData.loading = false;
            bindData.loadingMore = false;
            if(!append){
                bindData.records = [];
            }
            bindData.hasMore = false;
            if(typeof WEBDOCK !== "undefined" && WEBDOCK.freezeUiComponent){
                WEBDOCK.freezeUiComponent("soss-routes",false);
            }
            bindData.Message = error && error.responseJSON ? error.responseJSON.result : "Unable to load transactions.";
            alert(bindData.Message);
        });
    }

    function loadRecordsViaQuery(request,append){
        var config = configs[bindData.type];
        var query = [{
            storename: config.store,
            search: buildSearch(config,request),
            sysversionid: request.sysversionid,
            lastVersionId: request.sysversionid,
            nocache: true
        }];

        profileHandler.services.q(query).then(function(response){
            bindData.loading = false;
            bindData.loadingMore = false;
            if(typeof WEBDOCK !== "undefined" && WEBDOCK.freezeUiComponent){
                WEBDOCK.freezeUiComponent("soss-routes",false);
            }
            if(response.success){
                var result = response.result || {};
                setRecords(result[config.store] || [], null, append);
            }else{
                if(!append){
                    bindData.records = [];
                }
                bindData.hasMore = false;
                bindData.Message = response.error || "Unable to load " + bindData.title.toLowerCase() + ".";
                alert(bindData.Message);
            }
        }).error(function(error){
            bindData.loading = false;
            bindData.loadingMore = false;
            if(!append){
                bindData.records = [];
            }
            bindData.hasMore = false;
            if(typeof WEBDOCK !== "undefined" && WEBDOCK.freezeUiComponent){
                WEBDOCK.freezeUiComponent("soss-routes",false);
            }
            bindData.Message = error && error.responseJSON ? error.responseJSON.result : "Unable to load transactions.";
            alert(bindData.Message);
        });
    }

    function buildSearch(config,request){
        var searchValue = (request.searchValue || "").toString().trim();
        var profileId = (request.profileId || "").toString().trim();
        var parts = [];

        if(profileId !== ""){
            parts.push(config.profileColumn + ":" + profileId);
        }
        if(searchValue !== ""){
            parts.push(request.searchColumn + ":" + searchValue);
        }

        return parts.join(",");
    }

    function setRecords(records,total,append,hasMore,nextSysVersionId){
        records = Array.isArray(records) ? records : [];
        var sorted = sortRecords(records || []);
        var page = sorted.slice(0,bindData.pageSize);
        var cursor = nextSysVersionId || getNextSysVersionId(page);

        bindData.records = append ? mergeRecords(bindData.records,page) : page;
        bindData.total = total || bindData.records.length;
        bindData.nextSysVersionId = cursor;
        bindData.hasMore = !!cursor && (hasMore === true || page.length >= bindData.pageSize);

        if(bindData.records.length === 0){
            bindData.Message = "No " + bindData.title.toLowerCase() + " found.";
        }else{
            bindData.Message = "Showing " + bindData.records.length + " " + bindData.title.toLowerCase() + (bindData.hasMore ? ". Use Load More for older records." : ".");
        }
    }

    function sortRecords(records){
        var config = configs[bindData.type];
        return records.slice().sort(function(a,b){
            var aVersion = parseFloat(recordValue(a,"sysversionid") || 0);
            var bVersion = parseFloat(recordValue(b,"sysversionid") || 0);
            if(!isNaN(aVersion) && !isNaN(bVersion) && aVersion !== bVersion){
                return aVersion < bVersion ? 1 : -1;
            }

            var aId = parseFloat(recordValue(a,config.idField) || 0);
            var bId = parseFloat(recordValue(b,config.idField) || 0);
            if(!isNaN(aId) && !isNaN(bId) && aId !== bId){
                return aId < bId ? 1 : -1;
            }

            var aDate = dateScore(recordValue(a,config.dateField));
            var bDate = dateScore(recordValue(b,config.dateField));
            if(aDate === bDate){
                return 0;
            }
            return aDate < bDate ? 1 : -1;
        });
    }

    function dateScore(value){
        if(!value){
            return 0;
        }
        value = value.toString().replace(/^\"|\"$/g,"");
        var time = new Date(value).getTime();
        return isNaN(time) ? 0 : time;
    }

    function getNextSysVersionId(records){
        var next = null;
        records.forEach(function(record){
            var versionId = parseFloat(recordValue(record,"sysversionid") || 0);
            if(!isNaN(versionId) && versionId > 0){
                if(next === null || versionId < next){
                    next = versionId;
                }
            }
        });
        return next;
    }

    function mergeRecords(existing,page){
        var seen = {};
        var merged = [];
        (existing || []).concat(page || []).forEach(function(record){
            var key = recordKey(record);
            if(!seen[key]){
                seen[key] = true;
                merged.push(record);
            }
        });
        return sortRecords(merged);
    }

    function recordKey(record){
        var versionId = recordValue(record,"sysversionid");
        if(versionId !== ""){
            return bindData.type + ":v:" + versionId;
        }
        return bindData.type + ":id:" + recordId(record) + ":" + recordStatus(record);
    }

    function recordValue(record, field){
        if(!record || !field){
            return "";
        }
        return record[field] === undefined || record[field] === null ? "" : record[field];
    }

    function recordId(record){
        if(bindData.type === "invoice"){
            return recordValue(record,"invoiceNo");
        }
        if(bindData.type === "receipt"){
            return recordValue(record,"receiptNo");
        }
        if(bindData.type === "deposit"){
            return recordValue(record,"TranNo");
        }
        return recordValue(record,"tranid");
    }

    function recordDate(record){
        if(bindData.type === "receipt"){
            return recordValue(record,"receiptDate");
        }
        if(bindData.type === "collection"){
            return recordValue(record,"tranDate");
        }
        return recordValue(record,"invoiceDate");
    }

    function recordPrimary(record){
        if(bindData.type === "collection"){
            return recordValue(record,"description") || ("Transaction " + recordId(record));
        }
        return recordValue(record,"name") || recordValue(record,"email") || ("Profile " + recordValue(record,"profileId"));
    }

    function recordSecondary(record){
        if(bindData.type === "receipt"){
            return recordValue(record,"paymentType") || recordValue(record,"contactno");
        }
        if(bindData.type === "deposit"){
            return recordValue(record,"paymenttype") || recordValue(record,"contactno");
        }
        if(bindData.type === "collection"){
            return recordValue(record,"profileid");
        }
        return recordValue(record,"contactno") || recordValue(record,"email");
    }

    function recordStatus(record){
        if(bindData.type === "collection"){
            return recordValue(record,"trantype") || "transaction";
        }
        return recordValue(record,"status") || "new";
    }

    function recordAmount(record){
        if(bindData.type === "receipt"){
            return recordValue(record,"paymentAmount");
        }
        if(bindData.type === "collection"){
            return recordValue(record,"amount");
        }
        return recordValue(record,"total");
    }

    function recordCurrency(record){
        return recordValue(record,"currencycode");
    }

    function formatMoney(value){
        var amount = parseFloat(value || 0);
        if(isNaN(amount)){
            amount = 0;
        }
        return amount.toFixed(2);
    }

    function formatDate(value){
        if(!value){
            return "";
        }
        value = value.toString().replace(/^\"|\"$/g,"");
        var date = new Date(value);
        if(isNaN(date.getTime())){
            return value;
        }
        return date.toLocaleDateString();
    }

    function statusClass(status){
        status = (status || "").toString().toLowerCase();
        switch(status){
            case "active":
            case "new":
            case "receipt":
                return "success";
            case "invoice":
                return "primary";
            case "void":
            case "cancelled":
            case "deleted":
                return "danger";
            case "grn":
            case "payment":
            case "deposit":
            case "diposit":
                return "warning";
            default:
                return "default";
        }
    }

    function canOpen(record){
        if(!recordId(record)){
            return false;
        }
        if(bindData.type !== "collection"){
            return true;
        }
        var tranType = (recordValue(record,"trantype") || "").toString().toLowerCase();
        return tranType === "invoice" || tranType === "receipt" || tranType === "deposit" || tranType === "diposit";
    }

    function openRecord(record){
        if(!canOpen(record)){
            return;
        }
        var route = "";
        if(bindData.type === "invoice"){
            route = "invoice";
        }else if(bindData.type === "receipt"){
            route = "receipt";
        }else if(bindData.type === "deposit"){
            route = "deposit";
        }else{
            route = collectionRoute(record);
        }

        if(route){
            routeHandler.appNavigate("../" + route + "?tid=" + recordId(record));
        }
    }

    function collectionRoute(record){
        var tranType = (recordValue(record,"trantype") || "").toString().toLowerCase();
        switch(tranType){
            case "invoice":
                return "invoice";
            case "receipt":
                return "receipt";
            case "deposit":
            case "diposit":
                return "deposit";
            default:
                return "";
        }
    }
});
