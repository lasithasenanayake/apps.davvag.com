WEBDOCK.component().register(function(exports){
    var scope,editor,service_handler;
    
    var bindData = {
        data:{},
        submitErrors : [],submitInfo : [],data:{}
    };

    var vueData =  {
        methods:{
            submit:submit,
            cancel:function(){}
           
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

      validator_profile = validatorInstance.newValidator (scope);
      validator_profile.map ("data.name",true, "Please enter the Name of the Projecte");
      validator_profile.map ("data.description",true, "Please enter the Description of The Project");
  }

    function submit(){
      lockForm();
      scope.submitErrors = [];
      scope.submitErrors = validator_profile.validate(); 
      if (!scope.submitErrors){
          scope.submitErrors = [];
          scope.submitInfo=[];
          service_handler.services.SaveProject(bindData.data).then(function(result){
              if(result.success){
                  alert("Project Updated.");
              }else{
                 alert("Error Updating.");
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
      editor=$("#txtcaption").Editor();
      loadValidator();
      service_handler = exports.getComponent("taskapi");
      if(!service_handler){
          console.log("Service has not Loaded please check.")
      }
    }
    

    

    

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
