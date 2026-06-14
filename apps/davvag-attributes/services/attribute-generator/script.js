WEBDOCK.component().register(function(exports){
    var service_handler;
    var bindData = {
        fields: [],
        att_info: {},
        data: {}
    };

    exports.initialize = function(){
        loadServiceHandler();
    };

    exports.Generate = function(ID, data, divid, cbcompleted, cbError){
        var completed = typeof cbcompleted === "function" ? cbcompleted : function(){};
        var failed = typeof cbError === "function" ? cbError : function(){};
        var attributeId = resolveAttributeId(ID, data || {});

        loadServiceHandler();
        if(!service_handler){
            failed("Service has not loaded. Please check app-handler.");
            return;
        }

        var loadService = service_handler.services.Attribute ? service_handler.services.Attribute : service_handler.services.Atrribute;
        if(!loadService){
            failed("Attribute load service is not available.");
            return;
        }

        loadService.call(service_handler.services, {id: attributeId}).then(function(result){
            if(result.success && result.result){
                bindData.att_info = result.result;
                bindData.fields = bindData.att_info.Fields || bindData.att_info.atrributeFields || [];
                bindData.data = createForm(bindData.fields, divid);
                completed(bindData.att_info);
            }else{
                bindData.fields = [];
                bindData.data = createForm(bindData.fields, divid);
                failed(result);
            }
        }).error(function(result){
            bindData.fields = [];
            createForm(bindData.fields, divid);
            failed(result);
        });
    };

    function loadServiceHandler(){
        if(service_handler){
            return;
        }
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not loaded. Please check app-handler.");
        }
    }

    function resolveAttributeId(ID, data){
        var id = (ID || "").toString();
        if(id.indexOf("_") >= 0){
            return id;
        }
        return ((data && data.main_node) || bindData.att_info.main_node || "attr") + "_" + id;
    }

    function isTrue(value){
        return value === true || value === 1 || value === "1" || value === "true";
    }

    function createForm(arr,id){
        var $formTmp = $('<form class="form-horizontal form-bordered"></form>');
        var data = {};

        if(!Array.isArray(arr)){
            arr = [];
        }

        arr.forEach(function(obj) {
            var $fieldSet = $('<div class="form-group"></div>');
            var $label = $('<label class="col-sm-3 control-label"></label>').text(obj.label || obj.name || "");
            var $txt = $('<div class="col-sm-9"></div>');
            var inputType = obj.type;
            data[obj.name] = null;

            $fieldSet.append($label);

            switch (inputType){
                case 'text':
                    $txt.append(flagInput($('<input class="form-control" type="text">').attr({id:obj.name, name:obj.name}), obj));
                    break;
                case 'textarea':
                    $txt.append(flagInput($('<textarea class="form-control" rows="4" cols="50"></textarea>').attr({id:obj.name, name:obj.name}), obj));
                    break;
                case 'select':
                    var $select = flagInput($('<select class="form-control"></select>').attr({id:obj.name, name:obj.name}), obj);
                    if(obj.datasource){
                        fillSelectFromDataSource($select, obj);
                    }else{
                        addOptions($select, obj.choices);
                    }
                    $txt.append($select);
                    break;
                case 'checkbox':
                    $txt.append(flagInput($('<input class="attribute-checkbox-preview" type="checkbox">').attr({
                        id:obj.name,
                        name:obj.name,
                        'true-value':obj.truevalue,
                        'false-value':obj.falsevalue
                    }), obj));
                    break;
                case 'date':
                    $txt.append(flagInput($('<input class="form-control" type="date">').attr({id:obj.name, name:obj.name}), obj));
                    break;
                default:
                    $txt.append($('<p class="form-control-static text-danger"></p>').text('Unsupported field type: ' + inputType));
                    break;
            }

            $fieldSet.append($txt);
            $formTmp.append($fieldSet);
        });

        $("#" + id).empty().append($formTmp.children());
        return data;

        function flagInput(elem, obj){
            if(isTrue(obj.readonly)){
                elem.prop('disabled', true);
            }
            if(isTrue(obj.req)){
                elem.prop('required', true);
            }
            return elem;
        }

        function addOptions(elem, arr){
            if(!Array.isArray(arr)){
                return;
            }
            arr.forEach(function(obj){
                elem.append($('<option></option>').attr('value', obj.sel).text(obj.label));
            });
        }

        function fillSelectFromDataSource(elem, field){
            service_handler.services.GetDataSource(field).then(function(result){
                if(result.success && Array.isArray(result.result)){
                    result.result.forEach(function(obj){
                        elem.append($('<option></option>')
                            .attr('value', obj[field.datavalue] ? obj[field.datavalue] : "error")
                            .text(obj[field.datacaption] ? obj[field.datacaption] : "error"));
                    });
                }
            }).error(function(error){
                elem.append('<option value="error">Error. Please check console.</option>');
                console.log(JSON.stringify(error));
            });
        }
    }
});
