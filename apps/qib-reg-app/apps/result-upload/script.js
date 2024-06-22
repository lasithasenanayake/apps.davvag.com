WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,attribute,newFile;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},image:null
    };

    var vueData =  {
        methods:{
            submit:submit,
            onFileChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                createImage(files[0]);
            },
            load:function(){
                bindData.data=attribute.get_data();
                
                attribute.services.Save(bindData.data).then(function(r){
                    console.log(JSON.stringify(r));
                }).error(function(e){
                    console.log(JSON.stringify(e));
                });
            },
            popup:function(){
                att_popup=exports.getShellComponent("attribute_shell_popup");
                att_popup.open("attr_lasitha_form",{admin:"0"},function(data){
                    renderDrid(); 

                });
            }
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    function createImage(file) {
        newFile = file;
        var image = new Image();
        var reader = new FileReader();

        reader.onload = function (e) {
            bindData.image = e.target.result;
        };

        reader.readAsDataURL(file);
    }

    function initialize(){
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        
        attribute=exports.getShellComponent("attribute_shell");
        //renderDrid();
        loadValidator();
    }

    function renderDrid() {
        //lockForm(); 
        attribute.renderGrid("attr_lasitha_form","sampleform",[{type:"data",name:"admin",displayname:"ID",style:""},
        {type:"data",name:"name",displayname:"yo man"},
        {type:"button",name:"openalert",fn:"function",caption:"Execute Command",displayname:"Edit",function:function(e){
            item=e.data;
            att_popup=exports.getShellComponent("attribute_shell_popup");
            att_popup.open(e.id,item,function(_d){
                console.log(_d);
            });
        }}],
        "",function(i){
            
            
        });
    }

    function submit(){
        lockForm();
        
            service_handler.services.UploadExcel(newFile).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    alert("Uploaded Successfully");
                }else{
                    alert("Error Uploading");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                unlockForm();
            });

        
    }

    function navigateBack(){

    }

    

    function lockForm(){
        $("#form-details-1 :input").prop("disabled", true);
        $("#form-details-1 :button").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details-1 :input").prop("disabled", false);
        $("#form-details-1 :button").prop("disabled", false);
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
