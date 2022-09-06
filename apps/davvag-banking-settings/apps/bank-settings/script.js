WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},qr_code:""
    };

    var newfiles=[];
    function createImage(files) {
        //console.log(JSON.stringify(files));
        //if(!newfiles){
        newfiles=[];
        if(files.length>0){
            
            getImage(0,files[0]);
            
        }
        
    }

    function getImage(index,file){
        var reader = new FileReader();
            reader.onload = function (e) {
                //newfiles[index].scr=e.target.result;
                //newfiles[index].name="0";
                //file.name="0";
                newfiles.push(file);
                bindData.data.qr_code=e.target.result;
                bindData.qr_code=e.target.result;
            };
        reader.readAsDataURL(file);
    }


    var vueData =  {
        methods:{
            submit:submit,
            delete:function(){
                alert("Not Implumented.")
            },
            onFileChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                createImage(files);
            },
            navigateback: function(){
                handler = exports.getShellComponent("soss-routes");
                handler.appNavigate("../settings");
            }
           
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
        handler = exports.getShellComponent("soss-routes");
        routeData = handler.getInputData();
        service_handler.services.Bank({"bank_code":routeData.bank_code?routeData.bank_code:"null"}).then(function(result){
                
            
            if(result.success){
                if(result.result!=null){
                    unlockForm();
                    bindData.data=result.result;
                    bindData.qr_code=bindData.data.qr_code;
                    
                    //$("#bank_code").prop("disabled", true);
                }
                //scope.submitInfo.push("Settings saved successfully.");
            }else{
                scope.submitErrors.push("Error");
                unlockForm();
            }
            
        }).error(function(result){
            scope.submitErrors = [];
            bindData.submitErrors.push("Error");
            unlockForm();
        });
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
            if(newfiles.length>0){
                bindData.data.qr_code="components/davvag-cms/soss-uploader/service/get/davvag-banking/"+newfiles[0].name;
            }

            service_handler.services.Save(bindData.data).then(function(result){
                
                console.log(result);
                handler = exports.getShellComponent("soss-routes");
                if(result.success){
                    if(newfiles.length>0){
                        exports.getAppComponent("davvag-tools","davvag-file-uploader", function(uploader){
                            uploader.initialize();
                            uploader.upload_uncompressed(newfiles, "davvag-banking", null,function(){
                                scope.submitInfo.push("Settings saved successfully.");
                                handler.appNavigate("../settings");
                            });
                        });
                    }else{
                        
                        handler.appNavigate("../settings");
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
        validator_profile.map ("data.bank_code",true, "Please enter Unique Back Code.");
        validator_profile.map ("data.bank_accountno",true, "Please enter Account No.");
        validator_profile.map ("data.bank_swift_code",true, "Please enter Swift Code of the bank.");
        validator_profile.map ("data.bank_name",true, "Please enter Name of the Bank.");
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
