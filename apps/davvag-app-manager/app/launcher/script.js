WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute,filter,pid=0;

    var bindData = {
        submitErrors : [],submitInfo : [],
        data:{},launcherType:"",app:{},
        applications:[],subApps:[],subApp:{},
        pid:0,userGroups:[],tmpUserGroups:[],
        fields:[],
        inputData:[]
    };

    function clearItems(){
        bindData.data={};
        bindData.launcherType="";
        bindData.subApp={};
        bindData.app={};
        bindData.subApps=[];
        bindData.inputData=[];
    }

    function trimValue(value){
        if(value==null)
            return "";

        return value.toString().replace(/^\s+|\s+$/g, "");
    }

    function validateLauncher(){
        var errors=[];
        var name=trimValue(bindData.data.name);
        var shortname=trimValue(bindData.data.shortname);
        var type=trimValue(bindData.launcherType);

        if(name=="")
            errors.push("Please enter launcher name");

        if(shortname=="")
            errors.push("Please enter short name");

        if(shortname.length>10)
            errors.push("Short name must be 10 characters or less");

        if(pid!=0 && type=="")
            errors.push("Please select launcher type");

        if(type=="weburi"){
            var url=trimValue(bindData.data.url);
            if(url=="")
                errors.push("Please enter Web URI");
            else if(/\s/.test(url))
                errors.push("Web URI cannot contain spaces");
        }

        if(type=="application" || pid==0){
            if(!bindData.app || !bindData.app.appCode)
                errors.push("Please select an application");

            if(!bindData.subApp || !bindData.subApp.Code)
                errors.push("Please select a sub application");
        }

        return errors;
    }

    var vueData =  {
        methods:{
            goBack:function(){
                //window.location="#/app/davvag-app-manager/launcher";
                window.history.back();
            },
             submit:submit,
             selectApp:function(x){
                bindData.subApps=x && x.Apps ? x.Apps : [];
                bindData.subApp={};
                bindData.inputData=[];
             },
             selectSubApp:function(x){
                //bindData.subApps=x.Apps;
                bindData.inputData=x && x.inputData ? x.inputData : [];
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
        bindData.launcherType=pid==0?"application":bindData.launcherType;

            
        service_handler.services.UserGroupsByLauncher({p_appid:pid.toString()}).then(function(d){
            bindData.userGroups = d.result;
            bindData.tmpUserGroups=d.result;
            //unlockForm();
        }).error(function(){
            //unlockForm();
        });
        service_handler.services.Apps().then(function(result){
            bindData.applications = result.result;
            service_handler.services.LauncherParentApp({bid:pid.toString()}).then(function(r){
                console.log(JSON.stringify(r));
                //bindData.applications[r.result.appcode]
                bindData.applications.forEach(a => {
                    if(a.appCode==r.result.appcode){
                        a.Apps.forEach(s => {
                            if(s.Code==r.result.subappcode)
                            {
                                bindData.fields=s.outputData?s.outputData:[];
                            }
                        });
                    }
                });
            }).error(function(e){
    
            });
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
                    Vue.set(bindData.userGroups, i, bindData.userGroups[i]);
                    
                }

                for (let i = 0; i < GrList.length; i++) {
                  
                    for (let x = 0; x < bindData.userGroups.length; x++) {
                        if(bindData.userGroups[x].groupid==GrList[i].groupid){
                            bindData.userGroups[x].selected="Y";
                            Vue.set(bindData.userGroups, x, bindData.userGroups[x]);
                        }
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
            try{
                bindData.inputData=JSON.parse(bindData.data.inputData?bindData.data.inputData:"[]");
            }catch(e){
                bindData.inputData=[];
            }
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
            bindData.launcherType="application"; 
        }
        //bindData.launcherType=bindData.launcherType?bindData.launcherType:"application";
        if((bindData.launcherType=="application" || pid==0) && bindData.app && bindData.app.appCode && bindData.subApp && bindData.subApp.Code){
            bindData.data.url="/#/"+bindData.app.appCode+"/"+bindData.subApp.path;
            bindData.data.applicationtype=bindData.launcherType;
            bindData.data.appcode=bindData.app.appCode;
            bindData.data.subappcode=bindData.subApp.Code;
            bindData.data.inputData=JSON.stringify(bindData.inputData);
        }
    }
    function submit(){
        scope.submitErrors = [];
        scope.submitErrors = validateLauncher();

        if(scope.submitErrors.length>0)
            return;

        fillData();
        lockForm();
        var validatorErrors = validator_profile.validate(); 

        if (!validatorErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            bindData.data.applicationtype=bindData.launcherType;

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
            scope.submitErrors = validatorErrors;
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
