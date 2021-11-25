WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute;

    var bindData = {
        submitErrors : [],submitInfo : [],data:[],type:"new",order:{},orderDetails:[]
    };

    var vueData =  {
        methods:{
            submit:submit,
            view:function(i){
                bindData.order=i;
                service_handler.services.OrderDetails({"id":bindData.order.invoiceNo.toString()}).then(function(r){
                    $("#appPermission").modal('toggle');
                    bindData.orderDetails=r.result;
                }).error(function(e){
                    bindData.submitErrors.push("Error Loading Orders");
                });
                
            },
            dispatch:function(i){
                bindData.order=i;
                att_popup=exports.getShellComponent("attribute_shell_popup");
                att_popup.open("attr_stelup_dispatch",bindData.order,function(data){
                    loadOrders();
                    $("#appPermission").modal('toggle'); 

                });
                //bindData.order=i;
                /*
                service_handler.services.UpdateOrder({"id":bindData.order.invoiceNo.toString(),"status":"dispatch"}).then(function(r){
                    //$("#appPermission").modal('toggle');
                    loadOrders();
                    $("#appPermission").modal('toggle');
                }).error(function(e){
                    bindData.submitErrors.push("Error Loading Orders");
                });*/
                
            },userPermClose:function(){
                $("#appPermission").modal('toggle');//("show");
             },
            load:function(){
                bindData.data=attribute.get_data();
                
                attribute.services.Save(bindData.data).then(function(r){
                    console.log(JSON.stringify(r));
                }).error(function(e){
                    console.log(JSON.stringify(e));
                });
            }
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    

    function initialize(){
        service_handler = exports.getComponent("seller_svr");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        attribute=exports.getShellComponent("attribute_shell");
        let type=routeData.t?routeData.t:"new"
        bindData.type=type;
        loadOrders();
        loadValidator();
    }

    function loadOrders(){
        service_handler.services.Orders({"type":bindData.type}).then(function(r){
            bindData.data=r.result;
        }).error(function(e){
            bindData.submitErrors.push("Error Loading Orders");
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
