WEBDOCK.component().register(function(exports){
    var productHandler;
    var routeHandler;

    var bindData = {
        loading: false,
        saving: false,
        errors: [],
        info: [],
        Message: "Loading payment form...",
        payment: emptyPayment()
    };

    exports.vue = {
        data: bindData,
        methods: {
            backToList: backToList,
            openSupplierPopup: openSupplierPopup,
            clearSupplier: clearSupplier,
            savePayment: savePayment,
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
        bindData.payment.receiptDate = formatDateTime(new Date());
        bindData.Message = "Ready to record a supplier payment.";
    }

    function emptyPayment(){
        return {
            receiptNo: 0,
            receiptDate: "",
            paymentType: "supplier-payment",
            profileId: 0,
            name: "",
            email: "",
            contactno: "",
            address: "",
            city: "",
            country: "",
            supplier_profileId: 0,
            supplier_name: "",
            supplier_email: "",
            supplier_city: "",
            supplier_address: "",
            supplier_country: "",
            paymentAmount: 0,
            advanceAmount: 0,
            advanceUtilized: 0,
            outstandingAmount: 0,
            balanceAmount: 0,
            remarks: "",
            source_id: "",
            status: "new",
            detailsString: "[]"
        };
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
        bindData.payment.profileId = profile.id || profile.profileId || 0;
        bindData.payment.name = profile.name || "";
        bindData.payment.email = profile.email || "";
        bindData.payment.contactno = profile.contactno || "";
        bindData.payment.address = profile.address || "";
        bindData.payment.city = profile.city || "";
        bindData.payment.country = profile.country || "";
        bindData.payment.supplier_profileId = bindData.payment.profileId;
        bindData.payment.supplier_name = bindData.payment.name;
        bindData.payment.supplier_email = bindData.payment.email;
        bindData.payment.supplier_city = bindData.payment.city;
        bindData.payment.supplier_address = bindData.payment.address;
        bindData.payment.supplier_country = bindData.payment.country;
    }

    function clearSupplier(){
        bindData.payment.profileId = 0;
        bindData.payment.name = "";
        bindData.payment.email = "";
        bindData.payment.contactno = "";
        bindData.payment.address = "";
        bindData.payment.city = "";
        bindData.payment.country = "";
        bindData.payment.supplier_profileId = 0;
        bindData.payment.supplier_name = "";
        bindData.payment.supplier_email = "";
        bindData.payment.supplier_city = "";
        bindData.payment.supplier_address = "";
        bindData.payment.supplier_country = "";
    }

    function savePayment(){
        clearMessages();
        if(!bindData.payment.profileId){
            setError("Select a supplier before saving the payment.");
            return;
        }
        if(parseFloat(bindData.payment.paymentAmount || 0) <= 0){
            setError("Enter a payment amount greater than zero.");
            return;
        }
        bindData.saving = true;
        bindData.payment.detailsString = JSON.stringify([
            {
                description: bindData.payment.remarks || "Supplier payment",
                amount: parseFloat(bindData.payment.paymentAmount || 0)
            }
        ]);
        productHandler.services.SaveSupplierPayment(clone(bindData.payment))
            .then(function(response){
                bindData.saving = false;
                if(response.success){
                    setInfo("Supplier payment #" + response.result.receiptNo + " saved.");
                    bindData.payment = emptyPayment();
                    bindData.payment.receiptDate = formatDateTime(new Date());
                }else{
                    setError(response.error || "Supplier payment failed.");
                }
            })
            .error(function(error){
                bindData.saving = false;
                setError(error && error.responseJSON ? error.responseJSON.result : "Supplier payment failed.");
            });
    }

    function backToList(){
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
