WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;
    var uomHandler;
    var uomCatalog = [];

    var bindData = {
        loading: false,
        saving: false,
        errors: [],
        info: [],
        Message: "Loading purchase order app...",
        productSearch: { value: "" },
        productResults: [],
        taxes: [],
        selectedTax: null,
        po: emptyTransaction()
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToInventory: backToInventory,
            openSupplierPopup: openSupplierPopup,
            clearSupplier: clearSupplier,
            searchProducts: searchProducts,
            addLine: addLine,
            removeLine: removeLine,
            refreshLineUom: refreshLineUom,
            recalc: recalc,
            savePO: savePO,
            applySelectedTax: applySelectedTax,
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
        uomHandler = exports.getComponent("uom-handler");
        routeHandler = exports.getShellComponent("soss-routes");
        bindData.po.tranDate = formatDateTime(new Date());
        loadUoms();
        loadTaxes();
        bindData.Message = "Ready to create a purchase order.";
    }

    function emptyTransaction(){
        return {
            tranNo: 0,
            poid: 0,
            tranDate: formatDateTime(new Date()),
            invoiceDueDate: formatDateTime(new Date()),
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
            taxid: 0,
            taxcode: "",
            taxname: "",
            status: "Approved",
            remarks: "",
            InvoiceItems: []
        };
    }

    function loadTaxes(){
        productHandler.services.InvoiceTaxes()
            .then(function(response){
                if(response.success){
                    bindData.taxes = response.result || [];
                    bindData.selectedTax = defaultTax();
                    applySelectedTax();
                }
            })
            .error(function(){
                bindData.taxes = [];
            });
    }

    function loadUoms(){
        if(!uomHandler || !uomHandler.transformers || !uomHandler.transformers.allUom){
            uomCatalog = [];
            return;
        }
        uomHandler.transformers.allUom()
            .then(function(response){
                if(response.success){
                    uomCatalog = response.result || [];
                    refreshAllLineUoms();
                }
            })
            .error(function(){
                uomCatalog = [];
            });
    }

    function defaultTax(){
        if(!bindData.taxes || bindData.taxes.length === 0){
            return null;
        }
        for(var i = 0; i < bindData.taxes.length; i++){
            if((bindData.taxes[i].isDefault || "").toString().toUpperCase() === "Y"){
                return bindData.taxes[i];
            }
        }
        return bindData.taxes[0];
    }

    function applySelectedTax(){
        if(bindData.selectedTax){
            bindData.po.taxid = parseInt(bindData.selectedTax.id || 0, 10) || 0;
            bindData.po.taxcode = bindData.selectedTax.code || "";
            bindData.po.taxname = bindData.selectedTax.name || "";
            bindData.po.tax = parseFloat(bindData.selectedTax.rate || 0);
        }
        recalc();
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
        bindData.po.profileId = profile.id || profile.profileId || 0;
        bindData.po.name = profile.name || "";
        bindData.po.email = profile.email || "";
        bindData.po.contactno = profile.contactno || "";
        bindData.po.address = profile.address || "";
        bindData.po.city = profile.city || "";
        bindData.po.country = profile.country || "";
    }

    function clearSupplier(){
        bindData.po.profileId = 0;
        bindData.po.name = "";
        bindData.po.email = "";
        bindData.po.contactno = "";
        bindData.po.address = "";
        bindData.po.city = "";
        bindData.po.country = "";
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
        var existing = findLine(product.itemid);
        if(existing){
            existing.qty = parseFloat(existing.qty || 0) + 1;
        }else{
            bindData.po.InvoiceItems.push({
                itemid: product.itemid,
                name: product.name || "",
                uom: product.uom || "",
                qty: 1,
                price: parseFloat(product.cost || product.price || 0),
                total: 0,
                catogory: product.catogory || ""
            });
            applyLineUomContext(bindData.po.InvoiceItems[bindData.po.InvoiceItems.length - 1], product.uom || "");
        }
        bindData.productSearch.value = "";
        bindData.productResults = [];
        recalc();
    }

    function findLine(itemid){
        for(var i = 0; i < bindData.po.InvoiceItems.length; i++){
            if(String(bindData.po.InvoiceItems[i].itemid) === String(itemid)){
                return bindData.po.InvoiceItems[i];
            }
        }
        return null;
    }

    function removeLine(index){
        bindData.po.InvoiceItems.splice(index, 1);
        recalc();
    }

    function refreshLineUom(line){
        applyLineUomContext(line, line && line.baseUom ? line.baseUom : "");
        recalc();
    }

    function recalc(){
        bindData.po.subtotal = 0;
        bindData.po.InvoiceItems.forEach(function(line){
            line.qty = parseFloat(line.qty || 0);
            line.price = parseFloat(line.price || 0);
            refreshLineBaseQty(line);
            line.total = round(line.qty * line.price);
            bindData.po.subtotal += line.total;
        });
        bindData.po.subtotal = round(bindData.po.subtotal);
        bindData.po.tax = bindData.selectedTax ? parseFloat(bindData.selectedTax.rate || 0) : parseFloat(bindData.po.tax || 0);
        bindData.po.taxamount = round(bindData.po.subtotal * (bindData.po.tax / 100));
        bindData.po.total = round(bindData.po.subtotal + bindData.po.taxamount);
    }

    function savePO(){
        clearMessages();
        recalc();
        if(!bindData.po.profileId){
            setError("Select a supplier before saving the purchase order.");
            return;
        }
        if(bindData.po.InvoiceItems.length === 0){
            setError("Add products before saving the purchase order.");
            return;
        }
        if(bindData.selectedTax){
            bindData.po.taxid = parseInt(bindData.selectedTax.id || 0, 10) || 0;
            bindData.po.taxcode = bindData.selectedTax.code || "";
            bindData.po.taxname = bindData.selectedTax.name || "";
            bindData.po.tax = parseFloat(bindData.selectedTax.rate || 0);
        }
        refreshAllLineUoms();
        bindData.saving = true;
        productHandler.services.SavePurchaseOrder(clone(bindData.po))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("Purchase order #" + response.result.tranNo + " saved.");
                    bindData.po = emptyTransaction();
                    bindData.po.tranDate = formatDateTime(new Date());
                    bindData.selectedTax = defaultTax();
                    applySelectedTax();
                }else{
                    setError(response.error || "Purchase order save failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Purchase order save failed.");
            });
    }

    function backToInventory(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate("../inventory");
        }
    }

    function formatMoney(value){
        var amount = parseFloat(value || 0);
        if(isNaN(amount)){
            amount = 0;
        }
        return amount.toFixed(2);
    }

    function formatDateTime(value){
        return value.getFullYear() + "-" + pad(value.getMonth() + 1) + "-" + pad(value.getDate()) + " " + pad(value.getHours()) + ":" + pad(value.getMinutes()) + ":00";
    }

    function pad(value){
        value = String(value);
        return value.length === 1 ? "0" + value : value;
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

    function refreshAllLineUoms(){
        bindData.po.InvoiceItems.forEach(function(line){
            applyLineUomContext(line, line.baseUom || line.uom || "");
        });
    }

    function applyLineUomContext(line, baseUom){
        if(!line){
            return;
        }
        line.baseUom = baseUom || line.baseUom || line.uom || "";
        if(!line.uom){
            line.uom = line.baseUom;
        }
        line.uomOptions = buildUomOptions(line.baseUom);
        line.uomEditable = line.uomOptions.length > 1 ? "Y" : "N";
        refreshLineBaseQty(line);
    }

    function refreshLineBaseQty(line){
        if(!line){
            return;
        }
        var factor = getUomFactorToBase(line);
        line.baseQty = parseFloat((parseFloat(line.qty || 0) * factor).toFixed(6));
    }

    function getUomFactorToBase(line){
        var selected = (line && line.uom ? String(line.uom) : "").toLowerCase();
        var base = (line && line.baseUom ? String(line.baseUom) : "").toLowerCase();
        if(!selected || !base || selected === base){
            return 1;
        }
        var options = buildUomOptions(line.baseUom || line.uom || "");
        for(var i = 0; i < options.length; i++){
            if(String(options[i].symbol || "").toLowerCase() === selected){
                return parseFloat(options[i].factorToBase || 1) || 1;
            }
        }
        return 1;
    }

    function buildUomOptions(baseUom){
        var options = [];
        var normalizedBase = (baseUom || "").toString().trim();
        if(normalizedBase === ""){
            return options;
        }
        var seen = {};
        var units = {};
        uomCatalog.forEach(function(record){
            if(isConversionUom(record)){
                return;
            }
            var symbol = (record.symbol || "").toString().trim();
            if(symbol !== ""){
                units[symbol.toLowerCase()] = record.name || symbol;
            }
        });
        options.push({
            symbol: normalizedBase,
            name: units[normalizedBase.toLowerCase()] || normalizedBase,
            factorToBase: 1
        });
        seen[normalizedBase.toLowerCase()] = true;
        uomCatalog.forEach(function(record){
            if(!isConversionUom(record) || String(record.status || "Active").toLowerCase() === "inactive"){
                return;
            }
            var fromSymbol = (record.fromSymbol || "").toString().trim();
            var toSymbol = (record.toSymbol || "").toString().trim();
            var fromQty = parseFloat(record.fromQty || 0);
            var toQty = parseFloat(record.toQty || 0);
            if(fromQty <= 0 || toQty <= 0){
                return;
            }
            if(fromSymbol.toLowerCase() === normalizedBase.toLowerCase() && toSymbol && !seen[toSymbol.toLowerCase()]){
                options.push({
                    symbol: toSymbol,
                    name: units[toSymbol.toLowerCase()] || toSymbol,
                    factorToBase: fromQty / toQty
                });
                seen[toSymbol.toLowerCase()] = true;
            }
            if(toSymbol.toLowerCase() === normalizedBase.toLowerCase() && fromSymbol && !seen[fromSymbol.toLowerCase()]){
                options.push({
                    symbol: fromSymbol,
                    name: units[fromSymbol.toLowerCase()] || fromSymbol,
                    factorToBase: toQty / fromQty
                });
                seen[fromSymbol.toLowerCase()] = true;
            }
        });
        return options;
    }

    function isConversionUom(record){
        return String(record && record.recordtype || "").toLowerCase() === "conversion"
            || (!!record && !!record.fromSymbol && !!record.toSymbol);
    }
});
