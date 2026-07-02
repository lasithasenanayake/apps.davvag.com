WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;

    var bindData = {
        activeTab: "dashboard",
        loading: false,
        saving: false,
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
        poProductSearch: { value: "" },
        poProductResults: [],
        grnProductSearch: { value: "" },
        grnProductResults: [],
        adjustProductSearch: { value: "" },
        adjustProductResults: [],
        selectedPoNo: "",
        po: emptyTransaction(),
        grn: emptyTransaction(),
        adjustment: emptyAdjustment(),
        issue: emptyIssue()
    };

    exports.vue = {
        data: bindData,
        methods: {
            refresh: refresh,
            setTab: setTab,
            navigateProducts: navigateProducts,
            lookupProduct: lookupProduct,
            openSupplierPopup: openSupplierPopup,
            clearSupplier: clearSupplier,
            filteredOpenPOs: filteredOpenPOs,
            searchProducts: searchProducts,
            addLine: addLine,
            removeLine: removeLine,
            addBarcodeToLine: addBarcodeToLine,
            removeBarcodeFromLine: removeBarcodeFromLine,
            recalc: recalc,
            savePO: savePO,
            receiveFromPO: receiveFromPO,
            loadSelectedPO: loadSelectedPO,
            saveGRN: saveGRN,
            selectAdjustmentProduct: selectAdjustmentProduct,
            saveAdjustment: saveAdjustment,
            issueGoods: issueGoods,
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
            status: "Approved",
            remarks: "",
            InvoiceItems: []
        };
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
                    applyDashboard(response.result || {});
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

    function setTab(tab){
        bindData.activeTab = tab;
        clearMessages();
    }

    function navigateProducts(){
        if(routeHandler && routeHandler.appNavigate){
            routeHandler.appNavigate("..");
        }
    }

    function lookupProduct(){
        var code = (bindData.lookup.value || "").toString().trim();
        if(code === ""){
            return;
        }
        productLookup({ column: "all", value: code }, function(products){
            if(products.length === 1){
                setInfo("Found #" + products[0].itemid + " " + products[0].name + " with " + number(products[0].qty) + " " + (products[0].uom || "") + " on hand.");
            }else if(products.length > 1){
                setInfo("Found " + products.length + " matching products. Use the stock table search to narrow it.");
            }else{
                setError("No product found for " + code + ".");
            }
        });
    }

    function openSupplierPopup(target){
        clearMessages();
        var popup = exports.getShellComponent("app_popup");
        if(!popup || !popup.open){
            setError("Profile popup is not loaded.");
            return;
        }
        popup.open("profileapp", "frmprofile-list-popup", {}, function(profile, instance){
            if(profile){
                applySupplier(target, profile);
            }
            if(instance && instance.close){
                instance.close();
            }else if(popup.close){
                popup.close();
            }
        }, "Select Supplier");
    }

    function applySupplier(target, profile){
        if(!profile){
            return;
        }
        var profileId = profile.id || profile.profileId || 0;
        var transaction = target === "grn" ? bindData.grn : bindData.po;
        transaction.profileId = profileId;
        transaction.name = profile.name || "";
        transaction.email = profile.email || "";
        transaction.contactno = profile.contactno || "";
        transaction.address = profile.address || "";
        transaction.city = profile.city || "";
        transaction.country = profile.country || "";
        if(target === "grn"){
            bindData.selectedPoNo = "";
        }
    }

    function clearSupplier(target){
        var transaction = target === "grn" ? bindData.grn : bindData.po;
        transaction.profileId = 0;
        transaction.name = "";
        transaction.email = "";
        transaction.contactno = "";
        transaction.address = "";
        transaction.city = "";
        transaction.country = "";
        if(target === "grn"){
            bindData.selectedPoNo = "";
        }
    }

    function filteredOpenPOs(){
        if(!bindData.grn.profileId){
            return [];
        }
        return bindData.openPurchaseOrders.filter(function(po){
            return String(po.profileId || "") === String(bindData.grn.profileId);
        });
    }

    function searchProducts(target){
        clearMessages();
        var holder = productSearchHolder(target);
        var term = (holder.search.value || "").toString().trim();
        if(term === ""){
            setError("Scan or enter a product search value.");
            return;
        }
        productLookup({ column: "all", value: term }, function(products){
            holder.results.splice(0, holder.results.length);
            products.forEach(function(product){
                holder.results.push(product);
            });
            if(products.length === 1){
                addLine(target, products[0]);
            }else if(products.length === 0){
                setError("No inventory products found.");
            }
        });
    }

    function productLookup(request, cb){
        productHandler.services.ProductLookup(request)
            .then(function(response){
                if(response.success){
                    cb(response.result || []);
                }else{
                    setError(response.error || "Product lookup failed.");
                    cb([]);
                }
            })
            .error(function(error){
                setError(error && error.responseJSON ? error.responseJSON.result : "Product lookup failed.");
                cb([]);
            });
    }

    function productSearchHolder(target){
        if(target === "grn"){
            return { search: bindData.grnProductSearch, results: bindData.grnProductResults };
        }
        if(target === "adjust"){
            return { search: bindData.adjustProductSearch, results: bindData.adjustProductResults };
        }
        return { search: bindData.poProductSearch, results: bindData.poProductResults };
    }

    function addLine(target, product){
        if(!product || !product.itemid){
            return;
        }
        var transaction = target === "grn" ? bindData.grn : bindData.po;
        var existing = findLine(transaction.InvoiceItems, product.itemid);
        if(existing){
            existing.qty = parseFloat(existing.qty || 0) + 1;
        }else{
            transaction.InvoiceItems.push({
                itemid: product.itemid,
                name: product.name || "",
                uom: product.uom || "",
                qty: 1,
                price: parseFloat(product.cost || product.price || 0),
                total: 0,
                invType: product.invType || "Inventory",
                catogory: product.catogory || "",
                barcode: product.barcode || "",
                barcodes: [],
                barcodeInput: ""
            });
        }
        productSearchHolder(target).results.splice(0, productSearchHolder(target).results.length);
        recalc(target);
    }

    function addBarcodeToLine(index){
        var line = bindData.grn.InvoiceItems[index];
        if(!line){
            return;
        }
        var barcode = (line.barcodeInput || "").toString().trim();
        if(barcode === ""){
            return;
        }
        line.barcodes = line.barcodes || [];
        for(var i = 0; i < line.barcodes.length; i++){
            if(line.barcodes[i] === barcode){
                line.barcodeInput = "";
                return;
            }
        }
        line.barcodes.push(barcode);
        line.qty = line.barcodes.length;
        line.barcodeInput = "";
        recalc("grn");
    }

    function removeBarcodeFromLine(line, index){
        if(!line || !line.barcodes){
            return;
        }
        line.barcodes.splice(index, 1);
        line.qty = line.barcodes.length;
        recalc("grn");
    }

    function findLine(lines, itemid){
        for(var i = 0; i < lines.length; i++){
            if(String(lines[i].itemid) === String(itemid)){
                return lines[i];
            }
        }
        return null;
    }

    function removeLine(target, index){
        var transaction = target === "grn" ? bindData.grn : bindData.po;
        transaction.InvoiceItems.splice(index, 1);
        recalc(target);
    }

    function recalc(target){
        var transaction = target === "grn" ? bindData.grn : bindData.po;
        transaction.subtotal = 0;
        transaction.InvoiceItems.forEach(function(line){
            line.qty = parseFloat(line.qty || 0);
            line.price = parseFloat(line.price || 0);
            line.total = round(line.qty * line.price);
            transaction.subtotal += line.total;
        });
        transaction.subtotal = round(transaction.subtotal);
        transaction.taxamount = round(transaction.subtotal * (parseFloat(transaction.tax || 0) / 100));
        transaction.total = round(transaction.subtotal + transaction.taxamount);
    }

    function savePO(){
        clearMessages();
        recalc("po");
        if(!bindData.po.profileId){
            setError("Select a supplier before saving the purchase order.");
            return;
        }
        if(bindData.po.InvoiceItems.length === 0){
            setError("Add products before saving the purchase order.");
            return;
        }
        bindData.saving = true;
        productHandler.services.SavePurchaseOrder(clone(bindData.po))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("Purchase order #" + response.result.tranNo + " saved.");
                    bindData.po = emptyTransaction();
                    refresh();
                }else{
                    setError(response.error || "Purchase order save failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Purchase order save failed.");
            });
    }

    function receiveFromPO(po){
        bindData.activeTab = "grn";
        applySupplier("grn", po);
        bindData.selectedPoNo = po.tranNo;
        applyPOToGRN(po);
    }

    function loadSelectedPO(){
        if(!bindData.selectedPoNo){
            bindData.grn = emptyTransaction();
            return;
        }
        var pos = filteredOpenPOs();
        for(var i = 0; i < pos.length; i++){
            if(String(pos[i].tranNo) === String(bindData.selectedPoNo)){
                applyPOToGRN(pos[i]);
                return;
            }
        }
    }

    function applyPOToGRN(po){
        bindData.grn = clone(po);
        bindData.grn.poid = po.tranNo;
        bindData.grn.tranNo = 0;
        bindData.grn.InvoiceItems = clone(po.InvoiceItems || []);
        bindData.grn.InvoiceItems.forEach(function(line){
            line.barcodes = line.barcodes || [];
            line.barcodeInput = "";
        });
        recalc("grn");
    }

    function saveGRN(){
        clearMessages();
        recalc("grn");
        if(bindData.grn.InvoiceItems.length === 0){
            setError("Add received products before saving the GRN.");
            return;
        }
        bindData.saving = true;
        productHandler.services.ReceiveGoods(clone(bindData.grn))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("GRN #" + response.result.tranNo + " saved and stock updated.");
                    bindData.grn = emptyTransaction();
                bindData.selectedPoNo = "";
                refresh();
                }else{
                    setError(response.error || "GRN save failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "GRN save failed.");
            });
    }

    function selectAdjustmentProduct(product){
        bindData.adjustment.itemid = product.itemid;
        bindData.adjustment.name = product.name || "";
        bindData.adjustment.uom = product.uom || "";
        bindData.adjustProductResults = [];
    }

    function saveAdjustment(){
        clearMessages();
        if(!bindData.adjustment.itemid){
            setError("Select a product before saving the adjustment.");
            return;
        }
        bindData.saving = true;
        productHandler.services.StockAdjustment(clone(bindData.adjustment))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("Stock adjustment saved.");
                    bindData.adjustment = emptyAdjustment();
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

    function stockStatus(product){
        var qty = parseFloat(product.stockQty || product.qty || 0);
        var reorder = parseFloat(product.reorder_qty || 0);
        if(reorder > 0 && qty <= reorder){
            return "Low";
        }
        return "OK";
    }

    function stockStatusClass(product){
        return stockStatus(product) === "Low" ? "label label-warning" : "label label-success";
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

    function round(value){
        return Math.round((parseFloat(value || 0) + Number.EPSILON) * 100) / 100;
    }

    function formatDateTime(value){
        return value.getFullYear() + "-" + pad(value.getMonth() + 1) + "-" + pad(value.getDate()) + " " + pad(value.getHours()) + ":" + pad(value.getMinutes()) + ":00";
    }

    function pad(value){
        value = String(value);
        return value.length === 1 ? "0" + value : value;
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
        link.href = "components/productapp-v2/product-style/file/product-common.css?v=0.4";
        document.getElementsByTagName("head")[0].appendChild(link);
    }

    function setError(message){
        bindData.errors.push(message);
    }

    function setInfo(message){
        bindData.info.push(message);
    }

    function clearMessages(){
        bindData.errors = [];
        bindData.info = [];
    }
});
