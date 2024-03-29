WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,cropper1,routeData,apploader,profilehandler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},attributes:{},p_image:"components/dock/soss-uploader/service/get/profile/0"
    };

    var vueData =  {
        methods:{
            sellersiginup:function(){
                //bindData.submitErrors = [];
                //bindData.submitErrors = validator_profile.validate();
                //if(!bindData.submitErrors){ 
                    $("#form-details-2").toggle();
                    $("#form-details-1").toggle();
                //}
            },
            biosiginup:function(){
                $("#form-details-2").toggle();
                $("#form-details-3").toggle();
                datanew= bindData.data;
                datanew.css="";
                apploader.downloadAPP("davvag-stripe","register-stripe","app_stripe",function(d){
                    
                    
                },function(e){
                    console.log(e);
                    bindData.loadingAppError=true;
                },completeResponce,datanew);
            },
            stripesiginup:function(){
                $("#form-details-3").toggle();
                $("#form-details-4").toggle();
            },
            sellingoplicy:function(){
                $("#form-details-4").toggle();
                $("#form-details-5").toggle();
            },
            tradingoplicy:function(){
                $("#form-details-5").toggle();
                $("#form-details-6").toggle();
            },
            crope:function(){
                cropper1.crope(1,1,function(e){
                    //console.log(e);
                    bindData.p_image=e.data;
                    newFile=e.fileData;
                });
            },
            submit:submit
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            pInstance = exports.getShellComponent("soss-routes");
            routeData = pInstance.getInputData();
            Login(routeData,function(){
                initialize();
            });
            exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_uploader){
                apploader=_uploader;
                
            });
            
            
        }
    }

    function completeResponce(s){
        $("#form-details-3").toggle();
        $("#form-details-4").toggle();
    }

    function Login(routeData,cb){
        if(localStorage.profile==null){
            if(routeData.u){
                //scope.isBusy=false;
                location.href="#/app/userapp?u="+escape("#/app/stelup_shop/selleronboard?u="+routeData.u);
                //location.href=unescape(routeData.u);
            }else{
                location.href="#/app/userapp?u=#/app/stelup_shop/selleronboard";
            }
        }else{
            bindData.data=JSON.parse(localStorage.profile);
            bindData.p_image="components/dock/soss-uploader/service/get/profile/"+bindData.data.id.toString();
            if(bindData.data.catogory==null){
                cb();
                return;
            }
            if(bindData.data.catogory.toLowerCase()=='vender'){
                if(routeData.u){
                    location.href=unescape(routeData.u);
                }else{
                    cb();
                }
            }else{
                cb();
            }
            
        }
    }

    function initialize(){
        
        $("#form-details-0").toggle();
        $("#form-details-1").toggle();
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        exports.getAppComponent("davvag-tools","davvag-img-cropper", function(cropper){
            cropper.initialize(300,300);
            cropper1=cropper;
        });
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        profilehandler  = exports.getComponent("p_svr");
        loadProfile();
        loadValidator();
    }

    function loadProfile(){
        
        idx=bindData.data.id;//?routeData.id:bindData.profile.id;
        bindData.loading=true;
        profilehandler.services.Profile({id:idx}).then(function(a){
            if(a.success){
                bindData.data=a.result;
                bindData.attributes=bindData.data.attributes;
                //oadproducts();
            }else{
                console.log(a);
            }
        }).error(function(e){
            console.log(e);
        })
    }

    var newFile;
    var uploader;
    function uploadFile(productId, cb){
        if (!newFile)cb();
        else{
            exports.getAppComponent("davvag-tools","davvag-file-uploader", function(_uploader){
                uploader=_uploader;
                uploader.initialize();
                var files=[];
                newFile.name=productId;
                files.push(newFile);
                uploader.upload(files, "profile", null,cb)
            });
        }
    }

    function submit(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            bindData.data.catogory="vender";
            bindData.data.attributes=bindData.attributes;
            var target ={};
            Object.assign(target,bindData.data);
            service_handler.services.Save(target).then(function(result){
                
                //console.log(result);
                localStorage.profile=JSON.stringify(target);
                if(result.success){
                    uploadFile(target.id,function(){
                        scope.submitInfo.push("Saved Successfully.");
                        localStorage.profile=JSON.stringify(target);
                        if(uploader){
                            uploader.close();
                        }
                        if(routeData.u){
                            location.href=unescape(routeData.u);
                        }
                    });
                    //scope.submitInfo.push("result.result.message");
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
        validator_profile.map ("data.name",true, "Please enter your full name");
        validator_profile.map ("data.contactno",true, "Please enter your contact number");
        validator_profile.map ("data.contactno","numeric", "Phone number should only consist of numbers");
        ///validator_profile.map ("data.contactno","minlength:9", "Phone number should consit of 10 numbers");
        validator_profile.map ("data.country",true, "Please pick your country");
        //validator_profile.map ("data.country",true, "Please pick your country");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
