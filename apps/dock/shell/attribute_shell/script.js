WEBDOCK.component().register(function(exports){
    var attrivuteID;
    var formdata=[];
    var callback;
    var data,primaryData=[];
    
    function renderForm(attributejson,id,_data,cb){
        //var formData = [{"type":"text","label":"Test","name":"temp","req":1},{"type":"textarea","label":"123456789","name":"temp","req":0},{"type":"select","label":"example","req":0,"name":"temp","choices":[{"label":"1","sel":0},{"label":"2","sel":0},{"label":"3","sel":0}]}];
        attrivuteID=attributejson;
        data=_data;
        callback=cb;
        formdata=[];
        exports.getResource("attributes",{file:attributejson},function(formData){
            formdata=formData;
            retriveDataFromServer(_data,function(x){
                if(x)
                data=x;
                createForm(formData,id);
                cb(x)
            },function(m){
                createForm(formData,id);
                cb(m);
            })
            //cb();
        });
    }

    function openPopupForm(id,_data,cb){
        var popup=$("#popupAttribute");
        if(popup==null){
            bodyEt=$("body");
            bodyEt.append("<div id='popupAttribute' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='exampleModalLabel' aria-hidden='true'><div class='modal-dialog' role='document'><div class='modal-content'><div class='modal-header'> <h5 class='modal-title' id='popupAttribute-title'>Crop the image</h5></div><div id='popupAttribute-body' class='modal-body'></div><div class='modal-footer'><button type='button' class='btn btn-secondary' data-dismiss='modal'>Cancel</button><button id='btnAttribute_save' type='button' class='btn btn-primary' id='crop'>Crop</button></div></div></div></div>");
        }
    }

    function createForm(arr,id){
        var $formTmp = $('<form id="'+id+'_form" class="form-horizontal form-bordered"></form>');
        primaryData=[];
        arr.forEach( function(obj, idx) {
            var $fieldSet,
                $selctOpts = $('<select class="form-control" name="" id="'+obj.name+'" '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':'')+'  value="'+(data[obj.name]?data[obj.name]:'')+'" ></select>'),
                inputType = obj.type; 
                
            switch (inputType){
                case 'text':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-9"></div>');
                    
                    $txt.append('<input class="form-control" type="text" id="'+obj.name+'" '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':'')+' value="'+(data[obj.name]?data[obj.name]:'')+'" />');
                    
                    $fieldSet.append($txt); 
                    $formTmp.append($fieldSet);
                    break;
                case 'textarea':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label  class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-9"></div>');
                    $txt.append('<textarea class="form-control" rows="4" cols="50" id="'+obj.name+'" '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':"")+'>'+(data[obj.name]?data[obj.name]:'')+'</textarea>');
                    $fieldSet.append($txt); 
                    $formTmp.append($fieldSet);
                    break;
                case 'select':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label  class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-9"></div>');
                    addOptions($selctOpts, obj.choices);
                    $txt.append($selctOpts);
                    $fieldSet.append($txt);                     
                    $formTmp.append($fieldSet);
                    break;
                case 'checkbox':
                        $fieldSet = $('<div class="form-group"></div>');
                        $fieldSet.append('<label class="col-sm-3 control-label">'+obj.label+'</label>');
                        $txt=$('<div class="col-sm-9"></div>');
                        if ( obj.req === 1) {
                            $txt.append('<input class="form-control" type="checkbox" true-value="'+obj.truevalue+'" false-value="'+obj.falsevalue+'"  id="'+obj.name+'" required  value="'+(data[obj.name]?data[obj.name]:'')+'">');
                        } else {
                            $txt.append('<input class="form-control" type="checkbox" true-value="'+obj.truevalue+'" false-value="'+obj.falsevalue+'"  id="'+obj.name+'"  value="'+(data[obj.name]?data[obj.name]:'')+'">');
                        }
                        $fieldSet.append($txt); 
                        $formTmp.append($fieldSet);
                        break;  
                default:
                    alert('There was no input type found.');
                    break;
            }
            if(obj.primary){
                primaryData.push(obj.name);
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
    
    function data_retrive() {
        for (let index = 0; index < formdata.length; index++) {
            const element = formdata[index];
            switch (element.type) {
                case "textarea":
                    data[element.name]=$("#"+element.name).val()
                    break;
                default:
                    data[element.name]=$("#"+element.name).val()
                    break;
            }
            
            
        }
        return {id:attrivuteID,data:data,primary:primaryData};
    }

    function generatePrimary(){
        primaryData=[];
        for (let index = 0; index < formdata.length; index++) {
            const element = formdata[index];
            if(element.primary){
                primaryData.push(element.name);
            }
        }
    }

    function retriveDataFromServer(_data,cb,cbErr){
        if(primaryData.length==0){
            generatePrimary();
        }
        
        loopError=false;
        if(_data){
            data=_data; 
        }
        var query="";
        for (let index = 0; index < primaryData.length; index++) {
            if(data[primaryData[index]]){
                query+=primaryData[index]+':'+data[primaryData[index]]+",";
            }else{
                cbErr("Primay Data Null Exception.");
                loopError =true;
                break;
            }            
        }
        if(loopError){
            return null;
        }
        if(query==""){
            cbErr("Primary Not Found for this Dataset.");
        }
        rQuery=[];
        rQuery.push({storename:attrivuteID,search:query.slice(0, -1)});
        menuhandler  = exports.getComponent("soss-data");
        menuhandler.services.q(rQuery)
                        .then(function(r){
                            //console.log(JSON.stringify(r));
                            if(r.success){
                                if(r.result[attrivuteID] && r.result[attrivuteID].length>0){
                                    data=r.result[attrivuteID][0];
                                    cb(data);
                                }else{
                                    cb(null);
                                }
                               
                            }else{
                                cbErr("Data Rerival Error");
                            }
                        })
                        .error(function(error){
                            cbErr(error.responseJSON);
                 });

    }

    exports.validate=function(){
        return true;
    }

    exports.retriveDataFromServer=retriveDataFromServer;
    exports.renderForm = renderForm;
    exports.get_data=data_retrive;

});
