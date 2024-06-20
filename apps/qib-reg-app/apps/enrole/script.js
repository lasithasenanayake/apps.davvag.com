WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,routeData;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{}
    };

    var vueData =  {
        methods:{
            submit:submit
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    function initialize(){
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        //exports.Complete({});
        loadValidator();
    }

    function submit(){
        
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if(routeData!=null){
            if(routeData.ref!=null)
                bindData.data.referelid=routeData.ref;
        }
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.Save(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    //exports.Complete(result.result);
                    bindData.data=result.result;
                    scope.submitInfo.push("Created Successfully.");
                    $("#form-details-2").toggle();
                    $("#form-details-1").toggle();
                }else{
                    scope.submitErrors.push("Error");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                unlockForm();
            });

        }else{
            unlockForm();
        }
    }

    function navigateBack(){

    }

    

    function lockForm(){
        $("#form-details-1 :input").prop("disabled", true);
        $("#form-details-1 :button").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details-1 :input").prop("disabled", false);
        $("#form-details-1 :button").prop("disabled", false);
    }

    function loadValidator(){
        var validatorInstance = exports.getShellComponent ("soss-validator");

        validator_profile = validatorInstance.newValidator (scope);
        validator_profile.map ("data.name",true, "Please enter your full name");
        validator_profile.map ("data.email",true, "Please enter your email");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        initialize();
    }

});
