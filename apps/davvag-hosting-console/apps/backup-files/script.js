WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},files:[]
    };

    var vueData =  {
        methods:{
            backup_system:backup_system,
            backup_data:backup_data,
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    function initialize(){
        service_handler = exports.getComponent("hosting-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        backup_files();
        loadValidator();
    }

    function backup_data(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.BackupDatabase().then(function(result){
                backup_files();
                console.log(result);
                
                if(result.success){
                    scope.submitInfo.push("Data backed up");
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

    function backup_system(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.BackupSystem().then(function(result){
                
                backup_files();
                
                if(result.success){
                    scope.submitInfo.push("System Backed up");
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

    function backup_files(){
            service_handler.services.DataBackupFiles().then(function(result){
               if(result.success){
                   bindData.files=result.result;
               }
            }).error(function(result){
               
            });

    }
    function navigateBack(){

    }

    

    function lockForm(){
        
        $("#form-details :button").prop("disabled", true);
    }

    function unlockForm(){
        
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
