WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,routeData;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},stripe_pulich_key:"",order:{},reciept:{},banks:[],bank_slip:"",bank:{}
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
                newfiles.push(file);
                bindData.data.bank_slip=e.target.result;
                bindData.bank_slip=e.target.result;
            };
        reader.readAsDataURL(file);
    }


    var vueData =  {
        methods:{
            navigateback:function(){
               // window.history.back();
                window.location=decodeURI(routeData.url);
            },
            navigateApp:function(item){
                $("#maindisplay").toggle();
                $("#bankdisplay").toggle();
                bindData.data.bank_code=item.bank_code;
                bindData.data.bank_accountno=item.bank_accountno;
                bindData.data.bank_name=item.bank_name;
                bindData.bank=item;

                console.log("toggle");
            },
            back:function(){
                bindData.data={};
                $("#maindisplay").toggle();
                $("#bankdisplay").toggle();
            },
            submition:function(){
                $("#banksubmition").toggle();
                $("#bankdisplay").toggle();
            },
            submitionback:function(){
                $("#banksubmition").toggle();
                $("#bankdisplay").toggle();
            },
            onFileChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                createImage(files);
            },
            save:submit
           
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
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        lockForm();
        if(routeData.orderid){
            service_handler.services.Order({id:routeData.orderid}).then(function(result){
                    
                console.log(result);
                
                if(result.success){
                   bindData.data=result.result;
                   //if(bindData.order.stripeKey!=null){}
                   bindData.banks=bindData.data.banks;
                   if(bindData.banks.length==0){
                        if(routeData.url){
                            window.location=decodeURI(routeData.url);
                        }else{
                            bindData.submitErrors.push("Banks not registered in the system");
                        }
                   }
                   if(bindData.data.amount>0){
                    InitDirectpay();
                   }else{
                        bindData.submitErrors.push("Order is already paid.");
                        if(routeData.url){
                            window.location=decodeURI(routeData.url);
                        }
                   }
                   
                }else{
                    bindData.order=null;
                    bindData.submitErrors.push("Order is not found to pay.");
                }
                
            }).error(function(result){
                bindData.order=null;
                bindData.submitErrors.push("Critical Error please refresh");
                lockForm();
            });
            loadValidator();
        }
        else if(routeData.repid){
            service_handler.services.ExtPaymentRequest({id:routeData.repid}).then(function(result){
                    
                console.log(result);
                
                if(result.success && result.result!=null){
                   bindData.data=result.result;
                   //if(bindData.order.stripeKey!=null){}
                   bindData.banks=bindData.data.banks;
                   if(bindData.banks.length==0){
                        if(routeData.url){
                            window.location=decodeURI(routeData.url);
                        }else{
                            bindData.submitErrors.push("Banks not registered in the system");
                        }
                   }
                   if(bindData.data.amount>0){
                        InitDirectpay();
                   }else{
                        bindData.submitErrors.push("Order is already paid.");
                        if(routeData.url){
                            window.location=decodeURI(routeData.url);
                        }
                   }
                   
                }else{
                    bindData.data=null;
                    bindData.submitErrors.push("No payment to pay.");
                }
                
            }).error(function(result){
                bindData.data=null;
                bindData.submitErrors.push("Critical Error please refresh");
                lockForm();
            });
            
        }else{
            bindData.submitErrors.push("Error Retriving Deposit information")
            return null;
            lockForm();
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
            if(newfiles.length>0){
                bindData.data.bank_slip="components/davvag-cms/soss-uploader/service/get/davvag-bank-diposits/"+newfiles[0].name;
            }

            service_handler.services.SaveBankDiposit(bindData.data).then(function(result){
                
                console.log(result);
                handler = exports.getShellComponent("soss-routes");
                if(result.success){
                    bindData.data=result.result;
                    $("#maindisplay").toggle();
                    $("#banksubmition").toggle();
                    if(newfiles.length>0){
                        exports.getAppComponent("davvag-tools","davvag-file-uploader", function(uploader){
                            uploader.initialize();
                            uploader.upload_uncompressed(newfiles, "davvag-bank-diposits", null,function(){
                                scope.submitInfo.push("Bank Tranfer slip has been sent.");
                                //handler.appNavigate("../settings");
                            });
                        });
                    }else{
                        
                        scope.submitInfo.push("Bank Tranfer slip has been sent.");
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

    
    function InitDirectpay(){

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
        validator_profile.map ("data.name",true, "Please enter Name");
        validator_profile.map ("data.email",true, "Please enter Email");
        validator_profile.map ("data.contactno",true, "please enter contatct no");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
