
WEBDOCK.component().register(function(exports, scope){
    var $progress,$progressBar,$closebutton,$modal,service_handler;
    exports.initialize = function(){
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        //clearCeate();
    }
    var callback;
    var errCallback,completed,data_collected;
    var bindData={
        fields:[],
        att_info:{},
        data:{}
    }
    
    exports.Generate=function(ID,data,divid,cbcompleted,cbError){
        
        completed=cbcompleted;
        data_collected=data;
        service_handler.services.Atrribute({id:bindData.att_info.main_node +'_'+id.toString()}).then(function(result){
                
            if(result.success){
               if(result.result!=null){
                   bindData.att_info=result.result;
                   bindData.fields=bindData.att_info.atrributeFields?bindData.att_info.atrributeFields:[];
                   bindData.data=createForm(bindData.fields,divid);
                   cbcompleted(bindData.att_info);
               }else{
                    //bindData.att_info=result.result;
                    bindData.fields=[];
                    bindData.data=createForm(bindData.fields,divid);
                    cbcompleted(bindData.att_info);
               }
            }else{
                bindData.fields=[];
                bindData.data=createForm(bindData.fields,divid);
                cbError(result);
            }
            
        }).error(function(result){
            bindData.fields=[];
            createForm(bindData.fields,divid);
            cbError(result);
        });
    }

    

    function createForm(arr,id){
        var $formTmp = $('<form class="form-horizontal form-bordered"></form>');
        var data ={}; 
        arr.forEach( function(obj, idx) {
            var $fieldSet,
                $selctOpts = $('<select class="form-control" name="" id="'+obj.name+'"></select>'),
                inputType = obj.type; 
            data[obj.name]=null;    
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
            $txt=$('<div class="col-sm-3"></div>'); 
            
            $txt.append('<button class="btn btn-danger">Delete</button>');
            if(obj.primary){
                $txt.append('<label class=" control-label">Primary </label>');
            }
            $fieldSet.append($txt);
            

        });
    
        $("#" + id).html($formTmp.html())
        return data;  
        // Loop for the select options.
        function addOptions(elem, arr){
            arr.forEach(function(obj){
                elem.append('<option value="'+obj.sel+'">'+obj.label+'</option>');              
            });
        }
    }

    

});
