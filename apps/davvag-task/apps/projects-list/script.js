WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute;

    var bindData = {
        submitErrors : [],submitInfo : [],data:[],types:[]
    };

    var vueData =  {
        methods:{
            view:function(i){
                openAppPopup("davvag-task","projects",i,function(data,form){
                    if(i==null){
                        bindData.data.push(data);
                    }else{
                        i=data;
                    }
                    form.close();
                },i==null?"New Project":"Project["+i.name+"]",false,true);
                
            }
            
            ,
            changeAccess:function(i){
                openViewObject(i.sysviewobject,function(newId,form){
                    if(i.sysviewobject!=newId){
                        i.sysviewobject=newId;
                        submit(i);
                        form.close();
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

        service_handler.services.AllProjects({fromPage:"0"}).then(function(r){
            bindData.data=r.result;
        }).error(function(e){
            alert("Error Loading Projects");
        });
        
    }

    

    function submit(item){
       
            service_handler.services.SaveProject(item).then(function(result){
                console.log(result);
                if(result.success){
                    alert("Project Access Updated.");
                }else{
                   alert("Error Updating Access.");
                }
                //unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                alert("Error Updating Access.");
            });

        
    }

    

    

    

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
