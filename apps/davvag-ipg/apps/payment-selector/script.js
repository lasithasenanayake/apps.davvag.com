WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:[],id:0,type:"store"
    };

    var vueData =  {
        methods:{
            submit:submit,
            goto:function(i){
                switch(bindData.type){
                    case "order":
                        window.location="#/app/"+i.AppCode+"?url="+encodeURI(bindData.url)+"&orderid="+bindData.id;
                        break;
                    case "ExPayment":
                        window.location="#/app/"+i.AppCode+"?url="+encodeURI(bindData.url)+"&repid="+bindData.id;
                        break;
                }
            }
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            pInstance = exports.getShellComponent("soss-routes");
            routeData = pInstance.getInputData();
            if(routeData.orderid){
                bindData.id=routeData.orderid;
                bindData.type="order";
            }
            if(routeData.repid){
                bindData.id=routeData.repid;
                bindData.type="ExPayment";
            }
            if(routeData.url){
                bindData.url=decodeURI(routeData.url);
            }else{
                if(sessionStorage.blogheader){
                    i=JSON.parse(sessionStorage.blogheader)
                    bindData.url=i.buttonuri;
                }else{
                    bindData.url=null;
                }
            }
            initialize();
        }
    }

    function initialize(){
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        service_handler.services.IPGs({"id":bindData.id.toString(),"type":bindData.type}).then(function(result){
                
            console.log(result);
            
            if(result.success){
                bindData.data=result.result.ipgs;
                ipg=result.result.ipgs?result.result.ipgs:[];
                if(ipg.length==0){
                    window.location=bindData.url;
                }
                
                //scope.submitInfo.push("result.result.message");
            }else{
                scope.submitErrors.push("Error Loading Please contact Vender.");
            }
            unlockForm();
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
        validator_profile.map ("data.email",true, "Please enter your full name");
        validator_profile.map ("data.password",true, "Please enter your contact number");
        validator_profile.map ("data.contactno","numeric", "Phone number should only consist of numbers");
        validator_profile.map ("data.contactno","minlength:9", "Phone number should consit of 10 numbers");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
