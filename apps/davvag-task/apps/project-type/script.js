WEBDOCK.component().register(function(exports){
    var scope,editor,service_handler,form_validator;
    
    var bindData = {
        data:{},
        submitErrors : [],submitInfo : [],data:{}
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

    function submit(){
      lockForm();
      bindData.data.description=$("#txttypecaption").data("editor").html(); 
      scope.submitErrors = [];
      scope.submitErrors = form_validator.validate(); 
      if (!scope.submitErrors){
          scope.submitErrors = [];
          scope.submitInfo=[];
          service_handler.services.SaveType(bindData.data).then(function(result){
              if(result.success){
                  exports.Complete(result.result);  
                  //alert("Project Updated.");
              }else{
                bindData.submitErrors.push("Error Saving the Project");
              }
              unlockForm();
          }).error(function(result){
              scope.submitErrors = [];
              bindData.submitErrors.push("Error Saving the Project");
              //alert("Error Updating.");
              unlockForm();
          });

      }
  }


    function initialize(){
      editor=$("#txttypecaption").Editor();
      loadValidator(); 
      service_handler = exports.getComponent("taskapi");
      if(!service_handler){
          console.log("Service has not Loaded please check.")
      }
      if(exports.dataObject!=null){
        bindData.data=exports.dataObject;
        $("#txttypecaption").data("editor").html(bindData.data.description);
      }
    }
    

    

    

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
