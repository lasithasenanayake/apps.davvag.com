WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

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
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        loadValidator();
    }

    function submit(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.Delete(bindData.data).then(function(result){
                //sconsole.log(result);
                if(result.success){
                    scope.submitInfo.push("Invoice Successfully Deleted.");
                }else{
                    scope.submitErrors.push("Error Deleting Invoice");
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
        //validator_profile.map ("data.invoiceNo",true, "Please Input a Invoice No.");
       // validator_profile.map ("data.profileId",true, "Please Input the Profile ID of the Invoice");
        //validator_profile.map ("data.invoiceNo","numeric", "Invoice No should only consist of numbers");
        //validator_profile.map ("data.profileId","numeric", "Profile ID should consit of 10 numbers");
        //validator_profile.map ("data.remark",true, "Please Input a Invoice No.");
        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
