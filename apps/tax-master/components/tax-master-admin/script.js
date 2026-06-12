WEBDOCK.component().register(function(exports){
    var handler;
    var bindData = {
        items: [],
        form: emptyForm(),
        submitErrors: [],
        submitInfo: []
    };

    function emptyForm(){
        return {id:0, code:"", name:"", description:"", rate:0, taxType:"percentage", applyTo:"invoice", isDefault:"N", status:"active", sortOrder:0};
    }

    var vueData = {
        data: bindData,
        methods: {
            save: save,
            edit: edit,
            clear: clear,
            archive: archive,
            navigateBack: navigateBack
        },
        onReady: function(){
            initialize();
        }
    };

    exports.vue = vueData;
    exports.onReady = function(){};

    function initialize(){
        handler = exports.getComponent("tax-master-handler");
        load();
    }

    function load(){
        handler.services.List().then(function(response){
            if(response.success){
                bindData.items = response.result || [];
            }
        }).error(function(){
            bindData.submitErrors = ["Unable to load tax mappings."];
        });
    }

    function validate(){
        var errors = [];
        if(!bindData.form.name || bindData.form.name.trim() === ""){
            errors.push("Tax name is required.");
        }
        if(bindData.form.rate === "" || isNaN(parseFloat(bindData.form.rate))){
            errors.push("Tax rate is required.");
        }
        return errors;
    }

    function save(){
        bindData.submitErrors = validate();
        bindData.submitInfo = [];
        if(bindData.submitErrors.length){
            return;
        }
        handler.services.Save(bindData.form).then(function(response){
            if(response.success){
                bindData.submitInfo = ["Tax mapping saved."];
                clear();
                load();
            }else{
                bindData.submitErrors = ["Unable to save tax mapping."];
            }
        }).error(function(error){
            bindData.submitErrors = [error.responseJSON ? error.responseJSON.result : "Unable to save tax mapping."];
        });
    }

    function edit(item){
        bindData.form = JSON.parse(JSON.stringify(item));
    }

    function archive(item){
        var copy = JSON.parse(JSON.stringify(item));
        copy.status = "inactive";
        handler.services.Save(copy).then(function(response){
            if(response.success){
                load();
            }
        });
    }

    function clear(){
        bindData.form = emptyForm();
    }

    function navigateBack(){
        var route = exports.getShellComponent("soss-routes");
        route.appNavigate("..");
    }
});
