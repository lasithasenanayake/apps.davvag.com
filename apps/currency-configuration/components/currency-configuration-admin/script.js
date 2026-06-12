WEBDOCK.component().register(function(exports){
    var handler;
    var bindData = {
        items: [],
        form: emptyForm(),
        commonCodes: ["LKR","USD","EUR","GBP","INR","AUD","CAD","JPY","CNY","SGD","AED"],
        submitErrors: [],
        submitInfo: []
    };

    function emptyForm(){
        return {id:0, code:"LKR", numericCode:"144", name:"Sri Lankan Rupee", symbol:"Rs.", decimalPlaces:2, exchangeRate:1, isBase:"Y", status:"active", sortOrder:1};
    }

    var vueData = {
        data: bindData,
        methods: {
            save: save,
            edit: edit,
            clear: clear,
            archive: archive,
            normalizeCode: normalizeCode,
            navigateBack: navigateBack
        },
        onReady: function(){
            initialize();
        }
    };

    exports.vue = vueData;
    exports.onReady = function(){};

    function initialize(){
        handler = exports.getComponent("currency-configuration-handler");
        load();
    }

    function load(){
        handler.services.List().then(function(response){
            if(response.success){
                bindData.items = response.result || [];
            }
        }).error(function(){
            bindData.submitErrors = ["Unable to load currencies."];
        });
    }

    function normalizeCode(){
        bindData.form.code = (bindData.form.code || "").toUpperCase().replace(/[^A-Z]/g, "").substring(0, 3);
        bindData.form.numericCode = (bindData.form.numericCode || "").replace(/[^0-9]/g, "").substring(0, 3);
    }

    function validate(){
        normalizeCode();
        var errors = [];
        if(!/^[A-Z]{3}$/.test(bindData.form.code)){
            errors.push("Currency code must be a three-letter ISO 4217 code.");
        }
        if(!bindData.form.name || bindData.form.name.trim() === ""){
            errors.push("Currency name is required.");
        }
        if(bindData.form.exchangeRate === "" || isNaN(parseFloat(bindData.form.exchangeRate))){
            errors.push("Exchange rate is required.");
        }
        return errors;
    }

    function save(){
        bindData.submitErrors = validate();
        bindData.submitInfo = [];
        if(bindData.submitErrors.length){
            return;
        }
        handler.services.Save(bindData.form).then(function(response){
            if(response.success){
                bindData.submitInfo = ["Currency saved."];
                clear();
                load();
            }else{
                bindData.submitErrors = ["Unable to save currency."];
            }
        }).error(function(error){
            bindData.submitErrors = [error.responseJSON ? error.responseJSON.result : "Unable to save currency."];
        });
    }

    function edit(item){
        bindData.form = JSON.parse(JSON.stringify(item));
    }

    function archive(item){
        var copy = JSON.parse(JSON.stringify(item));
        copy.status = "inactive";
        handler.services.Save(copy).then(function(response){
            if(response.success){
                load();
            }
        });
    }

    function clear(){
        bindData.form = emptyForm();
    }

    function navigateBack(){
        var route = exports.getShellComponent("soss-routes");
        route.appNavigate("..");
    }
});
