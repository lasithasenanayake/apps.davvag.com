WEBDOCK.component().register(function(exports){
    var scope, validator_profile, service_handler, call_handler, complete_call;

    function emptyAttribute(){
        return {main_node:"attr", name:"", postworkflow:"", Fields:[]};
    }

    var bindData = {
        submitFieldErrors: [],
        submitErrors: [],
        submitInfo: [],
        att_info: emptyAttribute(),
        data: {},
        valuetype: "java.lang.String",
        primary: false,
        select: {},
        select_values: [],
        field: {},
        fields: [],
        fieldTypes: ["text", "textarea", "select", "checkbox", "date"],
        fieldType: "text",
        datasource: "0",
        workflows: [],
        postInput: [],
        attributes: [],
        attributeSearch: "",
        selectedAttributeId: "",
        selectedWorkflowName: "",
        loadingAttributes: false,
        editingFieldIndex: -1,
        fieldModalTitle: "Add Field",
        fieldActionLabel: "Add"
    };

    var vueData =  {
        methods:{
            submit: submit,
            addField: addField,
            closeField: closeField,
            addNew: addNew,
            addValue: addValue,
            removeValue: removeValue,
            editField: editField,
            populatePostInputs: populatePostInputs,
            selectWorkflow: selectWorkflow,
            loadvalue: loadvalue,
            refreshAttributes: loadAttributes,
            editAttribute: editAttribute,
            newAttribute: newAttribute,
            normalizeAttributeName: normalizeAttributeName
        },
        data: bindData,
        onReady: function(s,c){
            scope=s;
            call_handler=c || {};
            complete_call=call_handler.completedEvent ? call_handler.completedEvent : null;
            initialize(call_handler.data || null);
        },
        computed:{
            filteredAttributes:function(){
                var q = (bindData.attributeSearch || "").toLowerCase();
                if(q === ""){
                    return bindData.attributes;
                }
                return bindData.attributes.filter(function(item){
                    var haystack = [
                        item.id || "",
                        item.main_node || "",
                        item.name || "",
                        item.workflowName || ""
                    ].join(" ").toLowerCase();
                    return haystack.indexOf(q) >= 0;
                });
            },
            isPrimary:function(){
                switch (bindData.valuetype) {
                    case "java.lang.String":
                        if(parseInt(bindData.field.maxlen || 0, 10) > 0 && parseInt(bindData.field.maxlen || 0, 10) < 100){
                            return true;
                        }
                        bindData.primary=false;
                        return false;
                    case "int":
                    case "float":
                        return true;
                    case "java.util.Date":
                        bindData.primary=false;
                        return false;
                    default:
                        bindData.primary=false;
                        return false;
                }
            },
            autoIncrement:function(){
                if((bindData.valuetype === "int" || bindData.valuetype === "float") && isTrue(bindData.primary)){
                    return true;
                }
                bindData.field.autoIncrement=false;
                return false;
            }
        }
    };

    function initialize(incomingData){
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            bindData.submitErrors = ["Service has not loaded. Please check the app-handler component."];
            return;
        }

        loadWorkflows();
        loadAttributes();

        if(incomingData && (incomingData.Fields || incomingData.atrributeFields)){
            hydrateAttribute(incomingData);
        }else if(incomingData && incomingData.id){
            loadAttribute(incomingData.id);
        }else if(incomingData && incomingData.main_node && incomingData.name){
            loadvalue(incomingData.name, incomingData.main_node);
        }else{
            resetAttribute();
        }

        loadValidator();
    }

    function loadWorkflows(){
        service_handler.services.WorkFlows().then(function(r){
            bindData.workflows = r.success ? (r.result || []) : [];
        }).error(function(e){
            console.log(e);
            bindData.workflows = [];
        });
    }

    function loadAttributes(){
        if(!service_handler || !service_handler.services.List){
            return;
        }
        bindData.loadingAttributes = true;
        service_handler.services.List().then(function(r){
            bindData.loadingAttributes = false;
            bindData.attributes = r.success ? (r.result || []) : [];
        }).error(function(){
            bindData.loadingAttributes = false;
            bindData.submitErrors = ["Unable to load attributes."];
        });
    }

    function copyObject(obj, fallback){
        if(obj === null || typeof obj === "undefined"){
            return fallback || {};
        }
        return JSON.parse(JSON.stringify(obj));
    }

    function cleanSegment(value, fallback){
        value = (value || "").toString().trim().replace(/[^A-Za-z0-9_\-]+/g, "_").replace(/^[_\-]+|[_\-]+$/g, "");
        return value === "" ? (fallback || "") : value;
    }

    function normalizeAttributeName(){
        bindData.att_info.main_node = cleanSegment(bindData.att_info.main_node, "attr");
        bindData.att_info.name = cleanSegment(bindData.att_info.name, "");
    }

    function resetAttribute(keepMessages){
        bindData.att_info = emptyAttribute();
        bindData.fields = [];
        bindData.postInput = [];
        bindData.selectedAttributeId = "";
        bindData.selectedWorkflowName = "";
        resetFieldForm();
        if(!keepMessages){
            bindData.submitErrors = [];
            bindData.submitInfo = [];
        }
        createForm(bindData.fields, "sampleForm");
    }

    function hydrateAttribute(attribute){
        var data = copyObject(attribute, emptyAttribute());
        if(!data.Fields && data.atrributeFields){
            data.Fields = data.atrributeFields;
        }
        if(!data.Fields || !Array.isArray(data.Fields)){
            data.Fields = [];
        }
        if(!data.main_node || !data.name){
            fillNameFromId(data);
        }
        if(!data.main_node){
            data.main_node = "attr";
        }
        if(typeof data.postworkflow === "undefined"){
            data.postworkflow = "";
        }

        bindData.att_info = data;
        bindData.fields = data.Fields;
        bindData.selectedAttributeId = data.id || (data.main_node + "_" + data.name);
        bindData.selectedWorkflowName = data.postworkflow && data.postworkflow.name ? data.postworkflow.name : "";
        bindData.postInput = data.postworkflow && data.postworkflow.inputData ? data.postworkflow.inputData : [];
        createForm(bindData.fields, "sampleForm");
    }

    function fillNameFromId(data){
        if(!data.id){
            return;
        }
        var index = data.id.indexOf("_");
        if(index < 0){
            data.name = data.id;
            return;
        }
        data.main_node = data.id.substring(0, index);
        data.name = data.id.substring(index + 1);
    }

    function newAttribute(){
        resetAttribute();
    }

    function editAttribute(item){
        if(!item || !item.id){
            return;
        }
        loadAttribute(item.id);
    }

    function loadvalue(id, mainNode) {
        id = cleanSegment(id, "");
        if(id === ""){
            return;
        }
        loadAttribute(cleanSegment(mainNode || bindData.att_info.main_node, "attr") + "_" + id);
    }

    function loadAttribute(id) {
        var loadService = service_handler.services.Attribute ? service_handler.services.Attribute : service_handler.services.Atrribute;
        if(!loadService){
            bindData.submitErrors = ["Attribute load service is not available."];
            return;
        }

        lockForm();
        bindData.submitErrors = [];
        loadService.call(service_handler.services, {id:id}).then(function(result){
            if(result.success && result.result){
                hydrateAttribute(result.result);
            }else{
                bindData.submitErrors = ["Attribute was not found."];
                resetAttribute(true);
            }
            unlockForm();
        }).error(function(){
            bindData.submitErrors = ["Unable to load attribute."];
            resetAttribute(true);
            unlockForm();
        });
    }

    function resetFieldForm(){
        bindData.field = {req:"0", readonly:"0"};
        bindData.select = {};
        bindData.select_values = [];
        bindData.fieldType = "text";
        bindData.valuetype = "java.lang.String";
        bindData.primary = false;
        bindData.datasource = "0";
        bindData.editingFieldIndex = -1;
        bindData.fieldModalTitle = "Add Field";
        bindData.fieldActionLabel = "Add";
        bindData.submitFieldErrors = [];
    }

    function addField(){
        resetFieldForm();
        $('#modalFieldPopup').modal('show');
    }

    function closeField(){
        $('#modalFieldPopup').modal('toggle');
        resetFieldForm();
    }

    function editField(index){
        if(!bindData.fields[index]){
            return;
        }

        var field = copyObject(bindData.fields[index], {});
        bindData.editingFieldIndex = index;
        bindData.fieldModalTitle = "Edit Field";
        bindData.fieldActionLabel = "Update";
        bindData.submitFieldErrors = [];
        bindData.field = field;
        bindData.fieldType = field.type || "text";
        bindData.valuetype = field.type === "date" ? "java.util.Date" : (field.valuetype || "java.lang.String");
        bindData.primary = isTrue(field.primary);
        bindData.datasource = field.datasource ? "1" : "0";
        bindData.select_values = field.choices && Array.isArray(field.choices) ? copyObject(field.choices, []) : [];
        bindData.select = {};
        $('#modalFieldPopup').modal('show');
    }

    function validateField(f){
        var errors = [];
        var fieldName = cleanSegment(f.name, "");
        if(!f.label || f.label.toString().trim() === ""){
            errors.push("Field label is required.");
        }
        if(fieldName === ""){
            errors.push("Field name is required.");
        }else if(!/^[A-Za-z_][A-Za-z0-9_]*$/.test(fieldName)){
            errors.push("Field name must start with a letter or underscore and contain only letters, numbers, and underscores.");
        }

        bindData.fields.forEach(function(element, index){
            if(index !== bindData.editingFieldIndex && element.name === fieldName){
                errors.push("Duplicate field. Field name must be unique.");
            }
        });

        if(bindData.fieldType === "date"){
            bindData.valuetype = "java.util.Date";
        }

        if(bindData.fieldType !== "date" && !bindData.valuetype){
            errors.push("Value type is required.");
        }

        if(bindData.valuetype === "java.lang.String"){
            var maxLength = parseInt(f.maxlen || 0, 10);
            if(!maxLength || maxLength < 1){
                errors.push("Max length is required for string fields.");
            }
        }

        if(bindData.fieldType === "select"){
            if(isTrue(bindData.datasource)){
                if(!f.datasource || !f.datavalue || !f.datacaption){
                    errors.push("Data source, value, and caption are required for data-source selects.");
                }
            }else if(bindData.select_values.length === 0){
                errors.push("Add at least one select value.");
            }
        }

        if(bindData.fieldType === "checkbox" && (typeof f.truevalue === "undefined" || typeof f.falsevalue === "undefined" || f.truevalue === "" || f.falsevalue === "")){
            errors.push("True value and false value are required for checkbox fields.");
        }

        return errors;
    }

    function addNew(f){
        bindData.submitFieldErrors = validateField(f);
        if(bindData.submitFieldErrors.length > 0){
            return;
        }

        var newField = {
            type: bindData.fieldType,
            valuetype: bindData.fieldType === "date" ? "java.util.Date" : bindData.valuetype,
            primary: isTrue(bindData.primary)
        };

        for(var pname in bindData.field){
            if(bindData.field.hasOwnProperty(pname)){
                newField[pname] = bindData.field[pname];
            }
        }

        newField.name = cleanSegment(newField.name, "");
        newField.label = (newField.label || "").toString().trim();
        newField.req = isTrue(newField.req) ? "1" : "0";
        newField.readonly = isTrue(newField.readonly) ? "1" : "0";
        newField.autoIncrement = isTrue(newField.autoIncrement);

        if(newField.valuetype === "java.lang.String"){
            newField.maxlen = parseInt(newField.maxlen, 10).toString();
        }

        if(bindData.fieldType === "select"){
            if(isTrue(bindData.datasource)){
                delete newField.choices;
            }else{
                newField.choices = copyObject(bindData.select_values, []);
                delete newField.datasource;
                delete newField.query;
                delete newField.datavalue;
                delete newField.datacaption;
            }
        }else{
            delete newField.choices;
            delete newField.datasource;
            delete newField.query;
            delete newField.datavalue;
            delete newField.datacaption;
        }

        if(bindData.fieldType !== "checkbox"){
            delete newField.truevalue;
            delete newField.falsevalue;
        }

        if(bindData.editingFieldIndex > -1){
            bindData.fields.splice(bindData.editingFieldIndex, 1, newField);
        }else{
            bindData.fields.push(newField);
        }
        bindData.att_info.Fields = bindData.fields;
        resetFieldForm();
        createForm(bindData.fields, "sampleForm");
        $('#modalFieldPopup').modal('toggle');
    }

    function addValue(f){
        bindData.submitFieldErrors = [];
        var value = f && f.value ? f.value.toString().trim() : "";
        var caption = f && f.caption ? f.caption.toString().trim() : "";
        if(value === "" || caption === ""){
            bindData.submitFieldErrors.push("Choice value and caption are required.");
            return;
        }

        bindData.select_values.forEach(function(element){
            if(element.sel === value){
                bindData.submitFieldErrors.push("Duplicate field value.");
            }
        });
        if(bindData.submitFieldErrors.length > 0){
            return;
        }

        var newField = {label: caption, sel: value};
        bindData.select_values.push(newField);
        bindData.field.choices = copyObject(bindData.select_values, []);
        bindData.select = {};
    }

    function removeValue(x){
        for(var i = bindData.select_values.length - 1; i >= 0; i--){
            if(bindData.select_values[i] === x || bindData.select_values[i].sel === x.sel){
                bindData.select_values.splice(i, 1);
            }
        }
        bindData.field.choices = copyObject(bindData.select_values, []);
    }

    function populatePostInputs(data){
        if(data){
            var workflow = copyObject(data, {});
            if(!workflow.inputData && workflow.inpuData){
                workflow.inputData = workflow.inpuData;
            }
            if(!workflow.inputData || !Array.isArray(workflow.inputData)){
                workflow.inputData = [];
            }
            bindData.att_info.postworkflow = workflow;
            bindData.selectedWorkflowName = workflow.name || "";
            bindData.postInput = workflow.inputData;
        }else{
            bindData.att_info.postworkflow = "";
            bindData.selectedWorkflowName = "";
            bindData.postInput = [];
        }
    }

    function selectWorkflow(name){
        if(!name){
            populatePostInputs(null);
            return;
        }
        for(var i = 0; i < bindData.workflows.length; i++){
            if(bindData.workflows[i].name === name){
                populatePostInputs(bindData.workflows[i]);
                return;
            }
        }
    }

    function getInputType(valueType){
        switch(valueType){
            case "int":
            case "float":
                return "number";
            case "java.util.Date":
                return "date";
            default:
                return "text";
        }
    }

    function createForm(arr,id){
        var $formTmp = $('<form class="form-horizontal form-bordered"></form>');

        if(!Array.isArray(arr) || arr.length === 0){
            $("#" + id).html('<div class="attribute-empty-preview">No fields added.</div>');
            return;
        }

        arr.forEach(function(obj, idx) {
            var $fieldSet = $('<div class="form-group"></div>');
            var $label = $('<label class="col-sm-3 control-label"></label>').text(obj.label || obj.name || "");
            var $inputWrap = $('<div class="col-sm-6"></div>');
            var inputType = obj.type;

            $fieldSet.append($label);

            switch (inputType){
                case 'text':
                    $inputWrap.append(flagInput($('<input class="form-control">').attr({type:getInputType(obj.valuetype), id:obj.name, name:obj.name}), obj));
                    break;
                case 'textarea':
                    $inputWrap.append(flagInput($('<textarea class="form-control" rows="4" cols="50"></textarea>').attr({id:obj.name, name:obj.name}), obj));
                    break;
                case 'select':
                    var $select = flagInput($('<select class="form-control"></select>').attr({id:obj.name, name:obj.name}), obj);
                    if(obj.datasource){
                        $select.append($('<option></option>').attr('value', '').text('Data source: ' + obj.datasource));
                    }else{
                        addOptions($select, obj.choices);
                    }
                    $inputWrap.append($select);
                    break;
                case 'checkbox':
                    $inputWrap.append(flagInput($('<input class="attribute-checkbox-preview">').attr({
                        type:'checkbox',
                        id:obj.name,
                        name:obj.name,
                        'true-value':obj.truevalue,
                        'false-value':obj.falsevalue
                    }), obj));
                    break;
                case 'date':
                    $inputWrap.append(flagInput($('<input class="form-control">').attr({type:'date', id:obj.name, name:obj.name}), obj));
                    break;
                default:
                    $inputWrap.append($('<p class="form-control-static text-danger"></p>').text('Unsupported field type: ' + inputType));
                    break;
            }

            $fieldSet.append($inputWrap);

            var $actions = $('<div class="col-sm-3 attribute-field-actions"></div>');
            $('<button type="button" class="btn btn-default btn-sm">Edit</button>').on('click', function(){
                editField(idx);
            }).appendTo($actions);
            $('<button type="button" class="btn btn-danger btn-sm">Delete</button>').on('click', function(){
                removeField(idx);
            }).appendTo($actions);
            if(isTrue(obj.primary)){
                $actions.append(' ');
                $actions.append($('<span class="label label-info">Primary</span>'));
            }
            $fieldSet.append($actions);
            $formTmp.append($fieldSet);
        });

        $("#" + id).empty().append($formTmp.children());

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
    }

    function removeField(id){
        if(!bindData.fields[id]){
            return;
        }
        if(!(bindData.fields[id].syskey ? bindData.fields[id].syskey : false)){
            if (id > -1) {
                bindData.fields.splice(id, 1);
            }
        }
        bindData.att_info.Fields = bindData.fields;
        createForm(bindData.fields, "sampleForm");
    }

    window.removexdr001 = removeField;

    function isTrue(value){
        return value === true || value === 1 || value === "1" || value === "true";
    }

    function validateAttribute(){
        normalizeAttributeName();
        var errors = [];
        if(!bindData.att_info.name){
            errors.push("Attribute form name is required.");
        }
        if(!bindData.att_info.main_node){
            errors.push("Main node is required.");
        }
        if(bindData.fields.length === 0){
            errors.push("Add at least one field.");
        }
        return errors;
    }

    function submit(){
        bindData.submitErrors = validateAttribute();
        bindData.submitInfo = [];
        if(bindData.submitErrors.length > 0){
            return;
        }

        lockForm();
        bindData.att_info.Fields = bindData.fields;
        if(bindData.att_info.postworkflow && bindData.postInput.length > 0){
            bindData.att_info.postworkflow.inputData = bindData.postInput;
            delete bindData.att_info.postworkflow.inpuData;
        }else if(!bindData.selectedWorkflowName){
            bindData.att_info.postworkflow = "";
        }

        service_handler.services.Save(bindData.att_info).then(function(result){
            if(result.success){
                bindData.submitInfo = ["Attribute saved."];
                hydrateAttribute(result.result);
                loadAttributes();
                if(complete_call){
                    complete_call(result.result);
                }
            }else{
                bindData.submitErrors = ["Error saving attribute."];
            }
            unlockForm();
        }).error(function(result){
            bindData.submitErrors = [result && result.responseJSON ? result.responseJSON.result : "Error saving attribute."];
            unlockForm();
        });
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
        var validatorInstance = exports.getShellComponent("soss-validator");
        if(!validatorInstance || !validatorInstance.newValidator){
            return;
        }
        validator_profile = validatorInstance.newValidator(scope);
        validator_profile.map("att_info.name", true, "Please enter attribute name");
    }

    exports.vue = vueData;
    exports.onReady = function(){

    };

});
