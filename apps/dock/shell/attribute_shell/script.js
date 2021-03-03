WEBDOCK.component().register(function(exports){
    var attrivuteID;
    var formdata=[];
    var callback;
    var data,primaryData=[],dataTimeInputs=[];
    
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

    function renderGrid(attributejson,id,columnlist,filter,cb){
        attrivuteID=attributejson;
        //data=_data;
        callback=cb;
        retriveGridData(filter,function(_data){
            if(_data){
                $formTmp=drawGrid(columnlist,_data);
                //$("#" + id).html('');
                //$("#" + id).replaceWith($formTmp);
                $("#" + id).html('<table class="table">'+$formTmp.html()+'</table>')
            }
        },function(err){
            
        })

    }

    

    function drawGrid(columnlist,_data){
        let table =$('<table class="table"></table>');
        window[attrivuteID]={};
        let header=true;
        let tableheader=$("<thead></thead>");
        let tableBody=$("<tbody></tbody>")
        window[attrivuteID+'data0001']=_data;
        //window.Att_ID=
        //window.

        for (let i = 0; i < _data.length; i++) {
            const element = _data[i];
            let row=$("<tr></tr>");
            
            for (let index = 0; index < columnlist.length; index++) {
                const el = columnlist[index];
                str_attribute="";
                if(el.attributes){
                    for (let s = 0; s < el.attributes.length; s++) {
                        const ex = el.attributes[s];
                        for(var pname in ex){
                            str_attribute+=" "+pname+'="'+ex[pname]+'"';
                        }
                        
                    }
                }
                
                switch (el.type) {
                    case "data":
                        //c = (el.style?' class="'+el.style+'"':'');
                        if(header){
                            tableheader.append('<th'+str_attribute+'>'+el.displayname+"</th>");
                        }
                        row.append('<td'+str_attribute+'>'+element[el.name]+"</td>");
                        break;
                    case "button":
                        if(header){
                            tableheader.append('<th'+str_attribute+'>'+el.displayname+"</th>");
                        }
                            window[attrivuteID][el.name]=el.function;
                            data={id:attrivuteID,data:element};
                            jsonstr=JSON.stringify(data);
                            row.append('<td'+str_attribute+'><button onclick='+"'"+attrivuteID+"."+el.name+"("+jsonstr+");'"+'">'+el.caption+'</button></td>');
                        break;
                    default:
                        break;
                }
                
            }
            if(header){
                tableheader.append('<th></th>');
                header=false;
            }
            tableBody.append(row);
        }
        table.append(tableheader);
        table.append(tableBody);
        return table;
        
    }

    function openPopupForm(id,_data,cb){
        var popup=$("#popupAttribute");
        if(popup==null){
            bodyEt=$("body");
            bodyEt.append("<div id='popupAttribute' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='exampleModalLabel' aria-hidden='true'><div class='modal-dialog' role='document'><div class='modal-content'><div class='modal-header'> <h5 class='modal-title' id='popupAttribute-title'>Crop the image</h5></div><div id='popupAttribute-body' class='modal-body'></div><div class='modal-footer'><button type='button' class='btn btn-secondary' data-dismiss='modal'>Cancel</button><button id='btnAttribute_save' type='button' class='btn btn-primary' id='crop'>Crop</button></div></div></div></div>");
        }
    }

    function getInputType(inputType){
        switch (inputType) {
            case "int":
                return "number";
                break;
            case "java.lang.String":
                return "text"
                break;
            case "float":
                return "number";
            break;
            case "java.util.Date":
                return "text";
                break;
            default:
                return "text"
                break;
        }

    }

    function getGenrateDateTime(){
        //$.fn.datepicker.defaults.format = "mm-dd-yyyy";
        
        for (let index = 0; index < dataTimeInputs.length; index++) {
            const element = dataTimeInputs[index];
            $("#"+element).datepicker();
            
        }
    }

    function createForm(arr,id){
        var $formTmp = $('<form id="'+id+'_form" class="form-horizontal form-bordered"></form>');
        primaryData=[];
        arr.forEach( function(obj, idx) {
            elementID=attrivuteID+"_"+obj.name;
            var $fieldSet,
                $selctOpts = $('<select class="form-control" name="" id="'+elementID+'" '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':'')+'  value="'+(data[obj.name]?data[obj.name]:'')+'" ></select>'),
                inputType = obj.type; 
            
            switch (inputType){
                case 'text':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-9"></div>');
                    
                    if(obj.valuetype=="java.util.Date"){
                        dataTimeInputs.push(elementID);
                        date_time=$('<div id="'+elementID+'" class="input-append date"></div>');
                        date_time.append('<input type="text"></input>');
                        //date_time.append('<input type="'+getInputType(obj.valuetype)+'"  size="16" data-format="dd/MM/yyyy hh:mm:ss"  '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':'')+' value="'+(data[obj.name]?data[obj.name]:'')+'" />');
                        date_time.append('<span class="add-on"><i data-time-icon="icon-time" data-date-icon="icon-calendar"></i></span>');
                        $txt.append(date_time);
                    }else{
                        $txt.append('<input class="form-control" type="'+getInputType(obj.valuetype)+'" id="'+elementID+'" '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':'')+' value="'+(data[obj.name]?data[obj.name]:'')+'" />');

                    }
                    $fieldSet.append($txt); 
                    $formTmp.append($fieldSet);
                    break;
                case 'textarea':
                    $fieldSet = $('<div class="form-group"></div>');
                    $fieldSet.append('<label  class="col-sm-3 control-label">'+obj.label+'</label>');
                    $txt=$('<div class="col-sm-9"></div>');
                    $txt.append('<textarea class="form-control" rows="4" cols="50" id="'+elementID+'" '+(obj.readonly==1?'disabled':'')+' '+(obj.req==1?'required':"")+'>'+(data[obj.name]?data[obj.name]:'')+'</textarea>');
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
                        cheked="";
                        if(data[obj.name]){
                            if(data[obj.name]==obj.truevalue){
                                cheked="checked";
                            }
                        }
                        if ( obj.req === 1) {
                            $txt.append('<input class="form-control" type="checkbox" true-value="'+obj.truevalue+'" false-value="'+obj.falsevalue+'"  id="'+elementID+'" required  '+cheked+'>');
                        } else {
                            $txt.append('<input class="form-control" type="checkbox" true-value="'+obj.truevalue+'" false-value="'+obj.falsevalue+'"  id="'+elementID+'"  '+cheked+'>');
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
        getGenrateDateTime();
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
                    data[element.name]=$("#"+attrivuteID+"_"+element.name).val()
                    break;
                case "checkbox":
                    if($("#"+attrivuteID+"_"+element.name).is(':checked')){
                        data[element.name]=element.truevalue;
                    }else{
                        data[element.name]=element.falsevalue;
                    }
                    break
                default:
                    data[element.name]=$("#"+attrivuteID+"_"+element.name).val()
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

    function retriveGridData(filter,cb,cbErr){
        
        rQuery=[];
        rQuery.push({storename:attrivuteID,search:(filter?filter:"")});
        menuhandler  = exports.getComponent("soss-data");
        menuhandler.services.q(rQuery)
                        .then(function(r){
                            if(r.success){
                                if(r.result[attrivuteID] && r.result[attrivuteID].length>0){
                                    data=r.result[attrivuteID];
                                    cb(data);
                                }else{
                                    data=[];
                                    cb(data);
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
    exports.renderGrid=renderGrid;
    exports.get_data=data_retrive;

});
