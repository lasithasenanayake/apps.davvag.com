WEBDOCK.component().register(function(exports){
    var scope,handler;

    function loadUoms(skip, take){
         
        
        handler.services.PendingdipositsRequests()
        .then(function(result){
            //bindData.product.title=unescape(bindData.product.title);
            vueData.data.items = result.result;
        })
        .error(function(){
    
        });
    }
    //bindData=
    var vueData =  {
        methods:{
            open: function(item){
               vueData.data.data=item;
               $('#modalappwindow').modal('show');

            },
            close:function(){
               $('#modalappwindow').modal('show');
            },
            save:submit
        },
        data :{
            items : [],data:{}
        },
        onReady: function(s){
            scope = s;
            handler = exports.getComponent("app-handler");
            loadUoms(0,100);
            loadValidator();
        },
        filter:{
            foramtdata:function(v){
                var newdata=unescape(v);
                console.log(newdata);
                return newdata;
            }
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
            

            handler.services.Payment(vueData.data.data).then(function(result){
                
                console.log(result);
                //handler = exports.getShellComponent("soss-routes");
                if(result.success){
                    //bindData.data=result.result;
                    scope.submitInfo.push("Bank Tranfer slip has been sent.");
                    filteredItems = vueData.data.items.filter(function(item) {
                        if(item.id!==result.result.id)
                            return item;
                    });
                    vueData.data.items=filteredItems==null?[]:filteredItems;
                    $('#modalappwindow').modal('hide');
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
        validator_profile.map ("data.clearedAmount",true, "Please enter Cleared Amount");
        validator_profile.map ("data.cleared_ref",true, "Please enter Cleared Bank Reference");
    }


    exports.vue = vueData;
    exports.onReady = function(element){
    }
});
