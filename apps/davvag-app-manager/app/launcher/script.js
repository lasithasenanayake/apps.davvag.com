WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute,filter,pid=0;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},launcherType:"",app:{},applications:[],subApps:[],subApp:{},pid:0,userGroups:[],tmpUserGroups:[]
    };

    function clearItems(){
        bindData.data={};
                 bindData.launcherType="";
                 bindData.subApp={};
                 bindData.app={};
    }

    var vueData =  {
        methods:{
            goBack:function(){
                //window.location="#/app/davvag-app-manager/launcher";
                window.history.back();
            },
            submit:submit,
             selectApp:function(x){
                bindData.subApps=x.Apps;
             },
             open:function(){
                 clearItems();
                 $("#modalFieldPopup").modal('toggle');//("show");
             },
             close:function(){
                $("#modalFieldPopup").modal('toggle');//("show");
             },
             userPermClose:function(){
                $("#appPermission").modal('toggle');//("show");
             },
             SaveUserGroups:function(){
                let data=bindData.data;
                data.UserGroups=bindData.userGroups;
                service_handler.services.SaveLauncherUserPerm(data).then(function(result){
                    $("#appPermission").modal('toggle');
                }).error(
                    function(e){
                        
                    }
                );
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
        attribute=exports.getShellComponent("attribute_shell");
        sossrout_handler=exports.getShellComponent("soss-routes");
        if(!service_handler){
            console.log("Service has not Loaded please check.");
        }
        loadValidator();
        lockForm();
        //routData=sossrout_handler.getRountData();
        let routData = sossrout_handler.getInputData();
        if(routData.id){
            pid=routData.id;
            bindData.pid=parseInt(pid);
            filter="pid:"+routData.id;
        }else{
            pid=0;
            filter="pid:0";
        }

        
        service_handler.services.UserGroupsByLauncher({p_appid:pid.toString()}).then(function(d){
            bindData.userGroups = d.result;
            bindData.tmpUserGroups=d.result;
            //unlockForm();
        }).error(function(){
            //unlockForm();
        });
        service_handler.services.Apps().then(function(result){
            bindData.applications = result.result;
            unlockForm();
        }).error(function(){
            unlockForm();
        });
        renderGrid();
    }

    var apps=[];
    function renderGrid() {
        //lockForm(); 
        attribute.renderGrid("davvag_launchers","appsgrid",[{type:"data",name:"bid",displayname:"ID",style:""},
        {type:"data",name:"name",displayname:"Name"},
        {type:"button",name:"openalert",fn:"function",caption:"Edit",displayname:"Edit",function:function(e){
            
            getData(e.data);
            $("#modalFieldPopup").modal('toggle');
        }},
        {type:"button",name:"sublauncher",fn:"function",caption:"Sub App",displayname:"Sub Launchers",function:function(e){
            window.location="#/app/davvag-app-manager/launcher?id="+e.data.bid.toString();
            //getData(e.data);
            //$("#modalFieldPopup").modal('toggle');
        }},
        {type:"button",name:"permission",fn:"function",caption:"User Permission",displayname:"User Permission",function:function(e){
            getDataUserGroups(e.data);
            
        }}],
        filter,function(i){
            
            
        });
    }
    function retriveData(){

    }

    function getDataUserGroups(data){
        bindData.data=data;
        if(bindData.data){
            service_handler.services.UserGroupsLancherAccess({appid:data.bid.toString()}).then(function(d){
                let GrList= d.result;
                for (let i = 0; i < bindData.userGroups.length; i++) {
                    bindData.userGroups[i].selected="N";
                }

                for (let i = 0; i < GrList.length; i++) {
                  
                    for (let x = 0; x < bindData.userGroups.length; x++) {
                        if(bindData.userGroups[x].groupid==GrList[i].groupid)
                            bindData.userGroups[x].selected="Y";
                    }
                }
                
                $("#appPermission").modal('toggle');
            }).error(function(){
                //unlockForm();
            });
        }
    }
    
    function getData(data){
        bindData.data=data;
        if(bindData.data){
            //bindData.data.url="/#/"+bindData.app.appCode+"/"+bindData.subApp.path;
            bindData.launcherType=bindData.data.applicationtype;
            bindData.applications.forEach(element => {
                if(element.appCode==bindData.data.appcode){
                    bindData.app=element;
                    bindData.subApps=element.Apps;
                    element.Apps.forEach(subapp => {
                        
                        if(subapp.Code==bindData.data.subappcode){
                            bindData.subApp=subapp;
                        }
                    });
                }
            });
            
        }
    }
    function fillData(){
        bindData.data.pid=pid;
        if(pid==0){
            bindData.launcherType=""; 
        }
        //bindData.launcherType=bindData.launcherType?bindData.launcherType:"application";
        if(bindData.launcherType=="application" || pid==0){
            bindData.data.url="/#/"+bindData.app.appCode+"/"+bindData.subApp.path;
            bindData.data.applicationtype=bindData.launcherType;
            bindData.data.appcode=bindData.app.appCode;
            bindData.data.subappcode=bindData.subApp.Code;
        }
    }
    function submit(){
        fillData();
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 

        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            
            service_handler.services.SaveLauncher(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    renderGrid()
                    scope.submitInfo.push("Saved Successfully");
                    clearItems();
                    $("#modalFieldPopup").modal('toggle');
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
        validator_profile.map ("data.name",true, "Please enter launcher name");
        validator_profile.map ("data.shortname",true, "Please enter short name");
        //validator_profile.map ("data.url",true, "Please enter URL");
        //validator_profile.map ("data.contactno","numeric", "Phone number should only consist of numbers");
        //validator_profile.map ("data.contactno","minlength:9", "Phone number should consit of 10 numbers");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
