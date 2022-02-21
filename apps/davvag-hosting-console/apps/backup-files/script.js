WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},files:[]
    };

    var vueData =  {
        methods:{
            download:download,
            delete_file:delete_data,
           
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

    function delete_data(file){
      
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.DeleteFile(file).then(function(result){
                backup_files();
                
                if(result.success){
                    scope.submitInfo.push("File deleted");
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

    function download(file){
        window.open("components/davvag-hosting-console/hosting-handler/service/File?file="+file.name)
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
