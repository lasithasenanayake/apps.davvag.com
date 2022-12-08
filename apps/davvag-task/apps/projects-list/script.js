WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute;

    var bindData = {
        submitErrors : [],submitInfo : [],data:[],type:"new",order:{},orderDetails:[]
    };

    var vueData =  {
        methods:{
            view:function(i){
                openAppPopup("davvag-task","projects",i,function(data){
                    i=data;
                },i==null?"New Project":"Project["+i.name+"]",false,true);
                
            },
            changeAccess:function(i){
                openViewObject(i.sysviewobject,function(newId){
                    if(i.sysviewobject!=newId){
                        i.sysviewobject=newId;
                        submit(i);
                    }
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
        service_handler = exports.getComponent("taskapi");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }

        service_handler.services.AllProjects({frompage:0}).then(function(r){
            bindData.data=r.result;
        }).error(function(e){
            alert("Error Loading")
        });
        
    }

    

    function submit(item){
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.SaveProject(item).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    alert("Project Updated.");
                }else{
                   alert("Error Updating.");
                }
                //unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                alert("Error Updating.");
            });

        }
    }

    

    

    

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
