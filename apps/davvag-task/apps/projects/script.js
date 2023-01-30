WEBDOCK.component().register(function(exports){
    var scope,editor,service_handler,form_validator;
    
    var bindData = {
        data:{},
        submitErrors : [],submitInfo : [],data:{},types:[]
    };

    var vueData =  {
        methods:{
            submit:submit,
            cancel:function(){
                if(exports.dataObject!=null)
                    exports.Complete(exports.dataObject);
                else{
                    exports.Complete(null);
                }
            },
            delete:function(t){

            },
            changeAccessTask:function(i){
                openViewObject(i.sysviewobject,function(newId,form){
                    if(i.sysviewobject!=newId){
                        i.sysviewobject=newId;
                        submitTask(i);
                        
                    }
                    form.close();
                });
                
            },
            UpdateTask:function(i){
                openAppPopup("davvag-task","project-type",(i==null?{projectId:bindData.data.projectId,sysviewobject:bindData.data.sysviewobject}:i),function(data,form){
                    if(i==null){
                        UpdateTaskList(data);
                    }else{
                        i=data;
                    }
                    form.close();
                },i==null?"New Task Type":"Task Type["+i.name+"]",false,true);
            }
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
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

      form_validator = validatorInstance.newValidator (bindData);
      form_validator.map ("data.name",true, "Please enter the Name of the Projecte");
      form_validator.map ("data.description",true, "Please enter the Description of The Project");
  }

    function UpdateTaskList(i){
        var newTask=true;
        bindData.types=bindData.types!=null?bindData.types:[];
        bindData.types.forEach(element => {
            if(element.typeId==i.typeId){
                newTask=false;
                element=i;
            }
        });
        if(newTask){
            bindData.types.push(i);
        }
    }

    function submitTask(i){
        service_handler.services.SaveType(i).then(function(result){
            if(result.success){
                UpdateTaskList(result.result);
            }else{
              bindData.submitErrors.push("Error Saving the Project");
            }
            unlockForm();
        }).error(function(result){
            bindData.submitErrors = [];
            bindData.submitErrors.push("Error Saving the Project");
            unlockForm();
        });
    }
    function submit(){
      lockForm();
      bindData.data.description=$("#txtcaption").data("editor").html(); 
      scope.submitErrors = [];
      scope.submitErrors = form_validator.validate(); 
      if (!scope.submitErrors){
          scope.submitErrors = [];
          scope.submitInfo=[];
          service_handler.services.SaveProject(bindData.data).then(function(result){
              if(result.success){
                  exports.Complete(result.result);  
                  //alert("Project Updated.");
              }else{
                bindData.submitErrors.push("Error Saving the Project");
              }
              unlockForm();
          }).error(function(result){
                bindData.submitErrors = [];
              bindData.submitErrors.push("Error Saving the Project");
              //alert("Error Updating.");
              unlockForm();
          });

      }
  }


    function initialize(){
      editor=$("#txtcaption").Editor();
      loadValidator(); 
      service_handler = exports.getComponent("taskapi");
      if(!service_handler){
          console.log("Service has not Loaded please check.")
      }
      
      if(exports.dataObject!=null){
        bindData.data=exports.dataObject;
        $("#txtcaption").data("editor").html(bindData.data.description);
      }
      service_handler.services.TypesForProject({"projectId":bindData.data.projectId?bindData.data.projectId:0}).then(function(results){
        bindData.types=results.success?results.result:[];
      }).error(function(err){
        console.log(err);
      })
    }
    

    

    

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
