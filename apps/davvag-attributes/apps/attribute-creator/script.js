WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitFieldErrors : [],submitErrors : [],submitInfo : [],data:{},valuetype:"",select:{},select_values:[],field:{},fields:[],fieldTypes:["text","textarea","select","checkbox","option","date","fileupload"],fieldType:"text"
    };

    var vueData =  {
        methods:{
            submit:submit,
            addField:function(){
                $('#modalFieldPopup').modal('show');
            },
            closeField:function(){
                $('#modalFieldPopup').modal('toggle');
            },
            addNew:function(f){
                bindData.submitFieldErrors=[];
                bindData.fields.forEach(element => {
                    if(element.name==f.name){
                        bindData.submitFieldErrors.push("Duplicate Field. name filed is unique.")
                        return;
                    }
                });
                if(bindData.submitFieldErrors.length>0){
                    return;
                }
                newField={"type":bindData.fieldType,"name":f.name,"label":f.label,"valuetype":bindData.valuetype,"req":f.req}
                if(f.valuetype=='java.lang.String')
                    newField.maxlen=f.maxlen
                if(f.choices!=null){
                    newField.choices=f.choices;
                    bindData.select_values=[];
                }

                if(f.truevalue){
                    newField.truevalue=f.truevalue;
                }

                if(f.falsevalue){
                    newField.falsevalue=f.falsevalue;
                }
                bindData.fields.push(newField);
                bindData.field={};
                createForm(bindData.fields,"sampleForm")
                $('#modalFieldPopup').modal('toggle');
            },
            addValue:function(f){
                bindData.submitFieldErrors=[];
                bindData.select_values.forEach(element => {
                    if(element.sel==f.value){
                        bindData.submitFieldErrors.push("Duplicate Field value")
                        return;
                    }
                });
                if(bindData.submitFieldErrors.length>0){
                    return;
                }
                newField={"label":f.caption,"sel":f.value};
                if(bindData.field.choices==null)
                    bindData.field.choices=[];
                bindData.select_values.push(newField);
                bindData.field.choices.push(newField);
                bindData.select={};
            },
            removeValue:function(x){

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
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        loadValidator();
    }

    function createForm(arr,id){
        var $formTmp = $('<form class="form-horizontal form-bordered"></form>');
    
        arr.forEach( function(obj, idx) {
            var $fieldSet,
                $selctOpts = $('<select class="form-control" name="" id="'+obj.name+'"></select>'),
                inputType = obj.type; 
                
            switch (inputType){
                case 'text':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-6"></div>');
                    if ( obj.req === 1) {
                        $txt.append('<input class="form-control" type="text" id="'+obj.name+'" required>');
                    } else {
                        $txt.append('<input class="form-control" type="text" id="'+obj.name+'">');
                    }
                    $fieldSet.append($txt); 
                    $formTmp.append($fieldSet);
                    break;
                case 'textarea':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label  class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-6"></div>');
                    $txt.append('<textarea class="form-control" rows="4" cols="50" id="'+obj.name+'"></textarea>');
                    $fieldSet.append($txt); 
                    $formTmp.append($fieldSet);
                    break;
                case 'select':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label  class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-6"></div>');
                    addOptions($selctOpts, obj.choices);
                    $txt.append($selctOpts);
                    $fieldSet.append($txt);                     
                    $formTmp.append($fieldSet);
                    break;
                case 'checkbox':
                        $fieldSet = $('<div class="form-group"></div>');
                        $fieldSet.append('<label class="col-sm-3 control-label">'+obj.label+'</label>');
                        $txt=$('<div class="col-sm-6"></div>');
                        if ( obj.req === 1) {
                            $txt.append('<input class="form-control" type="checkbox" true-value="'+obj.truevalue+'" false-value="'+obj.falsevalue+'"  id="'+obj.name+'" required>');
                        } else {
                            $txt.append('<input class="form-control" type="checkbox" true-value="'+obj.truevalue+'" false-value="'+obj.falsevalue+'"  id="'+obj.name+'">');
                        }
                        $fieldSet.append($txt); 
                        $formTmp.append($fieldSet);
                        break;  
                default:
                    alert('There was no input type found.');
                    break;
            }               
        });
    
        $("#" + id).html($formTmp.html())
           
        // Loop for the select options.
        function addOptions(elem, arr){
            arr.forEach(function(obj){
                elem.append('<option value="'+obj.sel+'">'+obj.label+'</option>');              
            });
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
