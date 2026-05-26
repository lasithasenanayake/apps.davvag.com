WEBDOCK.component().register(function(exports){
    var scope,servicehandler

    var bindData = {
        submitErrors : [],submitInfo : [],schema:{}
    };

    var vueData =  {
        methods:{
            removegroup:function(item){
                alert("Remove "+item.Name);
            },
            save:function(){
                exports.Complete(bindData.schema);
            },
            close:function(){
                exports.Complete(null);
            },
            setObject:function(item){
                // Implementation for setting object
                openViewObject(bindData.schema.object.permission.defaultWriteObjectID,function(data,shell_popup){
                    //console.log(JSON.stringify(data));
                    bindData.schema.object.permission.defaultWriteObjectID =data;
                    shell_popup.close();
                });
            },
            setViewObject:function(item){
                openViewObject(bindData.schema.permission.defaultWriteObjectID,function(data,shell_popup){
                    //console.log(JSON.stringify(data));
                    bindData.schema.permission.defaultWriteObjectID=data;
                    shell_popup.close();
                });
               
            }
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            service_handler = exports.getComponent("app-handler");
            attribute=exports.getShellComponent("attribute_shell");
            bindData.schema=exports.dataObject;
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
