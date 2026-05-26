WEBDOCK.component().register(function(exports){
    var scope,servicehandler

    var bindData = {
        submitErrors : [],submitInfo : [],schemas:[],data:[],searchQuery:""
    };

    var vueData =  {
        methods:{
            filterSchemas:function(value){
                if(bindData.searchQuery==null || bindData.searchQuery=="")
                    bindData.schemas = bindData.data;
                else
                    bindData.schemas = bindData.data.filter(item => item.Name.toLowerCase().includes(bindData.searchQuery.toLowerCase()));
            },
            editSchema:function(item){
                openAppPopup("davvag-app-manager","schema-file",item,function(data,form){
                    bindData.schemas=[];
                    if(data!=null){
                        
                        for(var i=0;i<bindData.data.length;i++){
                            if(bindData.data[i].Name==data.Name){
                                bindData.data[i]=data;
                                bindData.data[i].updated=true;
                                //this.$set(bindData.schemas[i], 'updated', true);
                                break;
                            }
                        }
                        
                    }
                    bindData.schemas=bindData.data;
                    form.close();
                },"Schema File",false,true);
                //alert("Edit "+item.Name);
            }   
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            service_handler = exports.getComponent("app-handler");
            attribute=exports.getShellComponent("attribute_shell");
            service_handler.services.Schemas().then(function(result){
                bindData.data = result.result;
                bindData.schemas = result.result;
                
            }).error(function(){
            
            });
            
        }
    }

    

    

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
