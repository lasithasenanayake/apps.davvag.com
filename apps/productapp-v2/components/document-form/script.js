WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;
    var routeData = {};

    var bindData = {
        type: "po",
        title: "Edit Purchase Order",
        icon: "fa-file-text-o",
        loading: false,
        saving: false,
        errors: [],
        info: [],
        Message: "Loading document...",
        productSearch: { value: "" },
        productResults: [],
        document: emptyDocument()
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToList: backToList,
            viewDocument: viewDocument,
            openSupplierPopup: openSupplierPopup,
            clearSupplier: clearSupplier,
            searchProducts: searchProducts,
            addLine: addLine,
            removeLine: removeLine,
            recalc: recalc,
            saveDocument: saveDocument,
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
        bindData.type = href.indexOf("/grn") >= 0 || routeData.type === "grn" ? "grn" : "po";
        if(bindData.type === "grn"){
            bindData.title = "Edit Goods Receive Note";
            bindData.icon = "fa-truck";
        }
    }

    function loadDocument(){
        var tranNo = documentNumberFromRoute();
        if(!tranNo){
            bindData.Message = "Open a document from the PO or GRN list.";
            setError("Document number was not found in the route.");
            return;
        }
        clearMessages();
        bindData.loading = true;
        bindData.Message = "Loading " + bindData.title.toLowerCase() + " #" + tranNo + "...";
        productHandler.services.DocumentDetails({ type: bindData.type, tranNo: tranNo })
            .then(function(response){
                bindData.loading = false;
                if(response.success){
                    bindData.document = normalizeDocument(response.result || {});
                    recalc();
                    bindData.Message = bindData.title + " #" + bindData.document.tranNo + " is ready.";
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
        base.Complete = (base.Complete || (bindData.type === "grn" ? "Y" : "N")).toString().toUpperCase();
        return base;
    }

    function openSupplierPopup(){
        clearMessages();
        var popup = exports.getShellComponent("app_popup");
        if(!popup || !popup.open){
            setError("Profile popup is not loaded.");
            return;
        }
        popup.open("profileapp", "frmprofile-list-popup", {}, function(profile, instance){
            if(profile){
                applySupplier(profile);
            }
            if(instance && instance.close){
                instance.close();
            }else if(popup.close){
                popup.close();
            }
        }, "Select Supplier");
    }

    function applySupplier(profile){
        bindData.document.profileId = profile.id || profile.profileId || 0;
        bindData.document.name = profile.name || "";
        bindData.document.email = profile.email || "";
        bindData.document.contactno = profile.contactno || "";
        bindData.document.address = profile.address || "";
        bindData.document.city = profile.city || "";
        bindData.document.country = profile.country || "";
    }

    function clearSupplier(){
        bindData.document.profileId = 0;
        bindData.document.name = "";
        bindData.document.email = "";
        bindData.document.contactno = "";
        bindData.document.address = "";
        bindData.document.city = "";
        bindData.document.country = "";
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
                        addLine(bindData.productResults[0]);
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

    function addLine(product){
        if(!product || !product.itemid){
            return;
        }
        var line = findLine(product.itemid);
        if(line){
            line.qty = parseFloat(line.qty || 0) + 1;
        }else{
            bindData.document.InvoiceItems.push({
                itemid: product.itemid,
                name: product.name || "",
                uom: product.uom || "",
                qty: 1,
                price: parseFloat(product.cost || product.price || 0),
                total: 0,
                catogory: product.catogory || ""
            });
        }
        bindData.productSearch.value = "";
        bindData.productResults = [];
        recalc();
    }

    function findLine(itemid){
        for(var i = 0; i < bindData.document.InvoiceItems.length; i++){
            if(String(bindData.document.InvoiceItems[i].itemid) === String(itemid)){
                return bindData.document.InvoiceItems[i];
            }
        }
        return null;
    }

    function removeLine(index){
        bindData.document.InvoiceItems.splice(index, 1);
        recalc();
    }

    function recalc(){
        bindData.document.subtotal = 0;
        bindData.document.InvoiceItems.forEach(function(line){
            line.qty = parseFloat(line.qty || 0);
            line.price = parseFloat(line.price || 0);
            line.total = round(line.qty * line.price);
            bindData.document.subtotal += line.total;
        });
        bindData.document.subtotal = round(bindData.document.subtotal);
        bindData.document.taxamount = round(bindData.document.subtotal * (parseFloat(bindData.document.tax || 0) / 100));
        bindData.document.total = round(bindData.document.subtotal + bindData.document.taxamount);
        bindData.document.balance = bindData.document.total;
    }

    function saveDocument(){
        clearMessages();
        recalc();
        if(bindData.type === "po" && !bindData.document.profileId){
            setError("Select a supplier before saving the purchase order.");
            return;
        }
        if(bindData.document.InvoiceItems.length === 0){
            setError("Add products before saving.");
            return;
        }
        bindData.saving = true;
        productHandler.services.SaveDocument({ type: bindData.type, document: clone(bindData.document) })
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    bindData.document = normalizeDocument(response.result || bindData.document);
                    bindData.Message = bindData.title + " #" + bindData.document.tranNo + " saved.";
                    setInfo(bindData.Message);
                }else{
                    setError(response.error || "Document save failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Document save failed.");
            });
    }

    function backToList(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate(bindData.type === "grn" ? "../grn-list" : "../po-list");
        }
    }

    function viewDocument(){
        if(routeHandler && routeHandler.appNavigate && bindData.document.tranNo){
            routeHandler.appNavigate((bindData.type === "grn" ? "../grn-view" : "../po-view") + "?tid=" + encodeURIComponent(bindData.document.tranNo));
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
