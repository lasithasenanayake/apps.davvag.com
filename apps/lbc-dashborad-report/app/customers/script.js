WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},items:[]
    };

    var vueData =  {
        methods:{
            submit:submit
            ,
            navigate: function(e){
                //handler = exports.getShellComponent("soss-routes");
                //Rini.appNavigate(id ? "../broadcast?id=" + id : "../broadcast");
                window.open(location.protocol+'//'+location.host+
                location.pathname+"#/app/profileapp/view?id="+e.id.toString(), '_blank');
            },status:function(status){
                switch((status?status:'active').toString().toLowerCase()){
                    case "tobeactive":
                        return "primary";
                    break;
                    case "tobeactivated":
                        return "pramary";
                        break;
                    case "inactive":
                        return "warning";
                    break;
                    case "void":
                        return "danger";
                    break;
                    case "active":
                        return "success";
                    break;
                    default:
                        return "warning";
                    break;
                }
            }
           
        },
        data :bindData,
        onReady: function(s,data){
            if(data.data){

                bindData.data=data.data;
                profileids=bindData.data.cusids.split(",");
                svr=exports.getShellComponent("soss-data");
                profileids.forEach(element => {
                    svr.services.q([{storename:"profile","search":"id:"+element.toString()}]).then(function(x){
                       if(x.result.profile.length>0){ bindData.items.push(x.result.profile[0]);}
                    }).error(function(e){

                    });
                });
            }
            scope=s;
            initialize();
        }
    }

    function initialize(){
        service_handler = exports.getComponent("rpt-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        exports.Complete({});
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
                    exports.Complete(result.result);
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
