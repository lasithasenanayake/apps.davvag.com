WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute;
    var course="attr_subject_creation";
    var bindData = {
        submitErrors : [],submitInfo : [],data:{},active:1,courseData:{}
    };

    var vueData =  {
        methods:{
            submit:submit,
            load:function(){
                bindData.data=attribute.get_data();
                attribute.services.Save(bindData.data).then(function(r){
                    console.log(JSON.stringify(r));
                }).error(function(e){
                    console.log(JSON.stringify(e));
                });
            },
            popup:function(){
                att_popup=exports.getShellComponent("attribute_shell_popup");
                att_popup.open(course,{course_code:bindData.courseData.code,course_name:bindData.courseData.name},function(data){
                    renderDrid(); 
                });
            },
            goback:function(){
                window.location="#/app/lbc-study-app/course_creation"
            },
            redrawGrid:renderDrid
           
        },
        data :bindData,
        onReady: function(s){
            sossrout_handler=exports.getShellComponent("soss-routes");
            r=sossrout_handler.getInputData();
            scope=s;
            if(r.code){
                console.log(r.code);
                rQuery=[];
                rQuery.push({storename:"attr_course_creation",search:"code:"+r.code});
                menuhandler=exports.getShellComponent("soss-data");
                menuhandler.services.q(rQuery)
                .then(function(r){
                    if(r.success && r.result["attr_course_creation"].length>0){
                        bindData.courseData=r.result["attr_course_creation"][0];
                        initialize();
                    } 
                })
                .error(function(error){
                        cbErr(error.responseJSON);
                });
                
            }
        }
    }

    

    function initialize(){
        
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        
        attribute=exports.getShellComponent("attribute_shell");
        renderDrid();
        loadValidator();
    }

    function renderDrid() {
        lockForm(); 
        attribute.renderGrid(course,"form-course",[{type:"data",name:"code",displayname:"Code",attributes:[]},
        {type:"data",name:"name",displayname:"Name"},
        {type:"button",name:"edit",fn:"function",caption:"Edit",displayname:"Edit",function:function(e){
            item=e.data;
            att_popup=exports.getShellComponent("attribute_shell_popup");
            att_popup.open(e.id,item,function(_d){
                console.log(_d);
            });
        }}],
        "status:"+bindData.active.toString()+",course_code:"+bindData.courseData.code,function(i){
            
            
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
