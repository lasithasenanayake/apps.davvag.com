WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{}
    };

    var vueData =  {
        methods:{
            submit:submit,
            sendmail:sendmail
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    function initialize(){
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        loadValidator();
        lockForm();
        service_handler.services.Settings().then(function(result){
            
            if(result.success){
                bindData.data=result.result?result.result:{};
            }
            unlockForm();
        }).error(function(result){
            scope.submitErrors = [];
            bindData.submitErrors.push("Error");
            unlockForm();
        });
    }

    function submit(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.Save(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    scope.submitInfo.push("result.result.message");
                }else{
                    scope.submitErrors.push("Error");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                unlockForm();
            });

        }
    }

    function sendmail(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.TestMail(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    if(result.result){
                        scope.submitInfo.push("please check mail your inbox");
                    }else{
                        scope.submitErrors.push("Something went wrong");

                    }
                }else{
                    scope.submitErrors.push("Error");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                unlockForm();
            });

        }
    }

    function navigateBack(){

    }

    

    function lockForm(){
        $("#form-details :input").prop("disabled", true);
        $("#form-details :button").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details :input").prop("disabled", false);
        $("#form-details :button").prop("disabled", false);
    }

    function loadValidator(){
        var validatorInstance = exports.getShellComponent ("soss-validator");

        validator_profile = validatorInstance.newValidator (scope);
        validator_profile.map ("data.username",true, "Please enter your full name");
        validator_profile.map ("data.password",true, "Please enter your contact number");
        validator_profile.map ("data.port","numeric", "Phone number should only consist of numbers");
        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
