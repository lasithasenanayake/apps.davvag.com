WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,externdata;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},courses:[]
    };

    var vueData =  {
        methods:{
            submit:submit,
            cancel:function(){
                window.location="#/app/lbc-study-app/view?id="+bindData.data.id.toString();
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
        }else{
            sossrout_handler=exports.getShellComponent("soss-routes");
            routData=sossrout_handler.getInputData();
            id=(routData.id?routData.id:(externdata.id?externdata.id:0));
            if(routData.id){
                service_handler.services.Courses({id:id.toString()}).then(function(r){
                    if(r.success){
                        bindData.data=r.result;
                        bindData.courses=r.result.courses;

                    }else{
                        bindData.submitErrors=[];
                        bindData.submitErrors.push("Error Loading data");
                        lockForm();
                    }
                }).error(function(x){
                    bindData.submitErrors=[];
                    bindData.submitErrors.push("Error Loading data");
                    lockForm();
                });

            }
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
            service_handler.services.SaveEntrol(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    window.location="#/app/lbc-study-app/view?id="+result.result.profileId;
                    scope.submitInfo.push("Enrolled Successfully.");
                }else{
                    scope.submitErrors.push("Error");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                //console.log(result.responseJSON.result);
                bindData.submitErrors.push(result.responseJSON.result);
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
        validator_profile.map ("data.course.code",true, "Please Select the Course");
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
