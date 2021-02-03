WEBDOCK.component().register(function(exports){
    var scope;
    var handler;
    var pInstance, validatorInstance;
    var routeData;
    
    var bindData = {
        product:{name:"",caption:"",title:"",imguri:"",buttoncaption:"",buttonuri:""},
        content:""

    };
    var newfiles=[];
    function createImage(files) {
        //console.log(JSON.stringify(files));
        //if(!newfiles){
        newfiles=[];
        if(files.length>0){
            
            getImage(0,files[0]);
            
        }
        
    }

    function getImage(index,file){
        var reader = new FileReader();
            reader.onload = function (e) {
                //newfiles[index].scr=e.target.result;
                //newfiles[index].name="0";
                //file.name="0";
                newfiles.push(file);
                bindData.product.icon=e.target.result;
            };
        reader.readAsDataURL(file);
    }

    var vueData =   {
        methods: {
            submit: submit,
            gotoUom: gotoUom,
            onFileChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                createImage(files);
            },
            navigateBack: function(){
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
            }
        },
        data : bindData,
        onReady : function(s){
            scope = s;
            handler = exports.getComponent("cms-gapp-handler");
            pInstance = exports.getShellComponent("soss-routes");
            validatorInstance = exports.getShellComponent ("soss-validator");
            routeData = pInstance.getInputData();
            
            
            handler.services.Settings({name:"cms-global"})
            .then(function(result){
                if(result.result){
                    bindData.product=result.result;
                    console.log("body");
                    console.log(bindData.product);
                }
                //gotoUom();
            })
            .error(function(){
                //$('#send').prop('disabled', false);
            });
            loadValidator();
        }
    }

    function loadCategory(scope){
        if (routeData.id)
        handler.transformers.getButtonbyid(routeData.id)
        .then(function(result){
            if (result.result.length !=0){
                bindData.product = result.result[0];
            }
        })
        .error(function(){
    
        });
    }

    var validator;
    function loadValidator(){
        validator = validatorInstance.newValidator (scope);
        //validator.map ("product.Name",true, "You should enter a name");
        //validator.map ("product.url",true, "You should enter a url");
    }

    function submit(){
        $('#send').prop('disabled', true);
        scope.submitErrors = validator.validate(); 
        if (!scope.submitErrors){
            var saveObject={name:"cms-global",body:bindData.product}
            var promiseObj = handler.services.saveSettings(saveObject);
            //else promiseObj = handler.transformers.insertArtical (bindData.product);
                    if(newfiles.length>0){
                        bindData.product.icon="components/davvag-cms/soss-uploader/service/get/assets/"+newfiles[0].name;
                    }
                    
                    
                    promiseObj
                        .then(function(result){
                            if(newfiles.length>0){
                                exports.getAppComponent("davvag-tools","davvag-file-uploader", function(uploader){
                                    uploader.initialize();
                                    uploader.upload_uncompressed(newfiles, "assets", null,function(){
                                        gotoUom();
                                    });
                                });
                            }
                        })
                        .error(function(){
                            $('#send').prop('disabled', false);
                        });
                    

            
        }else{
            $('#send').prop('disabled', false);
        }
    }

    function gotoUom(){
        handler1 = exports.getShellComponent("soss-routes");
        handler1.appNavigate("..");
    }


    exports.vue = vueData;
    exports.onReady = function(element){
        
    }
});
