WEBDOCK.component().register(function(exports){
    var scope, validator_profile, service_handler;

    var bindData = {
        submitErrors: [],
        submitInfo: [],
        currencies: [],
        data: {
            mode: "sandbox",
            currencycode: "",
            brandName: "Davvag Store"
        }
    };

    var vueData =  {
        methods: {
            submit: submit
        },
        data: bindData,
        onReady: function(s){
            scope = s;
            initialize();
        }
    };

    function initialize(){
        service_handler = exports.getComponent("app-handler");
        if (!service_handler){
            bindData.submitErrors.push("Service has not loaded.");
            return;
        }

        lockForm();
        exports.getAppComponent("currency-configuration", "currency-configuration-handler", function(currencyHandler){
            currencyHandler.loadActive(function(items){
                bindData.currencies = items;
                if(!bindData.data.currencycode){
                    currencyHandler.loadDefault(function(currency){
                        if(currency){ bindData.data.currencycode = currency.code; }
                    });
                }
            }, function(){
                bindData.submitErrors.push("Unable to load configured currencies.");
            });
        });
        service_handler.services.PublicToken({ "id": "0" }).then(function(result){
            if (result.success && result.result != null){
                bindData.data = result.result;
            }
            unlockForm();
        }).error(function(){
            bindData.submitErrors.push("Unable to load settings.");
            unlockForm();
        });

        loadValidator();
    }

    function submit(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate();
        if (!scope.submitErrors){
            scope.submitInfo = [];
            service_handler.services.Save(bindData.data).then(function(result){
                if (result.success){
                    scope.submitInfo.push("Settings saved successfully.");
                } else {
                    scope.submitErrors.push("Unable to save PayPal settings.");
                }
                unlockForm();
            }).error(function(){
                scope.submitErrors = [];
                bindData.submitErrors.push("Unable to save PayPal settings.");
                unlockForm();
            });
            return;
        }

        unlockForm();
    }

    function lockForm(){
        $("#form-details :input").prop("disabled", true);
        $("#form-details :button").prop("disabled", true);
        $("#form-details :select").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details :input").prop("disabled", false);
        $("#form-details :button").prop("disabled", false);
        $("#form-details :select").prop("disabled", false);
    }

    function loadValidator(){
        var validatorInstance = exports.getShellComponent("soss-validator");
        validator_profile = validatorInstance.newValidator(scope);
        validator_profile.map("data.clientId", true, "Please enter the PayPal client ID.");
        validator_profile.map("data.secret", true, "Please enter the PayPal secret.");
        validator_profile.map("data.mode", true, "Please select sandbox or live mode.");
        validator_profile.map("data.currencycode", true, "Please enter the currency code.");
    }

    exports.vue = vueData;
    exports.onReady = function(){
        
    };
});
