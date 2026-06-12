WEBDOCK.component().register(function(exports){
    var handler;
    var bindData = {
        items: [],
        form: emptyForm(),
        submitErrors: [],
        submitInfo: []
    };

    function emptyForm(){
        return {id:0, code:"", name:"", description:"", status:"active", isDefault:"N", sortOrder:0};
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
        handler = exports.getComponent("profile-catogory-handler");
        load();
    }

    function load(){
        handler.services.List().then(function(response){
            if(response.success){
                bindData.items = response.result || [];
            }
        }).error(function(){
            bindData.submitErrors = ["Unable to load profile catogories."];
        });
    }

    function validate(){
        var errors = [];
        if(!bindData.form.name || bindData.form.name.trim() === ""){
            errors.push("Catogory name is required.");
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
                bindData.submitInfo = ["Profile catogory saved."];
                clear();
                load();
            }else{
                bindData.submitErrors = ["Unable to save profile catogory."];
            }
        }).error(function(error){
            bindData.submitErrors = [error.responseJSON ? error.responseJSON.result : "Unable to save profile catogory."];
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
