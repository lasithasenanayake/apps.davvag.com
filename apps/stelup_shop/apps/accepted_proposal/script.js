WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,complete_call;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{}
    };

    var vueData =  {
        methods:{
            submit:submit,
            Reject:Reject
           
        },
        data :bindData,
        onReady: function(s,c){
            scope=s;
            call_handler=c;
            complete_call=call_handler.completedEvent?call_handler.completedEvent:null;
            if(c.data){
                bindData.data=c.data;
            }
            initialize();
        }
    }

    function initialize(){
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        loadValidator();
    }

    function submit(){
        bindData.data.notfy.closeapp=true;
        complete_call(bindData.data);
    }

    function Reject(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.RejectProductProposal(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    complete_call(bindData.data);
                    scope.submitInfo.push("Rejected");
                }else{
                    scope.submitErrors.push("Error Confirming Request");
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
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
