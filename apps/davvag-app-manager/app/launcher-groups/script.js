WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},launcherType:"",app:{},applications:[],subApps:[],subApp:{}
    };

    var vueData =  {
        methods:{
            submit:submit,
             selectApp:function(x){
                bindData.subApps=x.Apps;
             },
             open:function(){
                 bindData.data={};
                 $("#modalFieldPopup").modal('toggle');//("show");
             },
             close:function(){
                $("#modalFieldPopup").modal('toggle');//("show");
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
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        loadValidator();
        lockForm();
        service_handler.services.Apps().then(function(result){
            bindData.applications = result.result;
            unlockForm();
        }).error(function(){
            unlockForm();
        });
        renderGrid();
    }

    
    function renderGrid() {
        //lockForm(); 
        attribute.renderGrid("davvag_launchers","appsgrid",[{type:"data",name:"bid",displayname:"ID",style:""},
        {type:"data",name:"name",displayname:"Name"},
        {type:"button",name:"openalert",fn:"function",caption:"Edit",displayname:"Edit",function:function(e){
            
            getData(e.data);
            $("#modalFieldPopup").modal('toggle');
        }}],
        "",function(i){
            
            
        });
    }
    function retriveData(){

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
        if(bindData.launcherType=="application"){
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
        validator_profile.map ("data.url",true, "Please enter URL");
        //validator_profile.map ("data.contactno","numeric", "Phone number should only consist of numbers");
        //validator_profile.map ("data.contactno","minlength:9", "Phone number should consit of 10 numbers");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
