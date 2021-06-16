
WEBDOCK.component().register(function(exports, scope){
    var $progress,$progressBar,$closebutton,$modal;
    exports.initialize = function(){
        
        //clearCeate();
    }
    var callback;
    var errCallback,completed,data_collected;

    
    exports.downloadAPP=function(appId,startupComponent,id,cb,er,cbcompleted,d){
        callback=cb;
        errCallback=er;
        completed=cbcompleted;
        data_collected=d;
        var leftMenu = exports.getShellComponent("left-menu");
        leftMenu.getApps(function(apps){
            var appObj = apps[appId];
            
            var loadID="dft_" + id+"_app0001";
            window[loadID]=window[loadID]?window[loadID]:{loading:false,apps:{app:{}}};
            window[loadID].apps[appId]=window[loadID].apps[appId]?window[loadID].apps[appId]:{loading:false,app:{}};
            //var appdock= window[loadID].apps[appId];
            if(window[loadID].loading){
                console.log("Already Same component loading.....");
                return;
            }

            window[loadID].loading=true;
            

            var MemoryApp= WEBDOCK.componentManager.getMemoryApp(appId,startupComponent);
            if(MemoryApp){
                try {
                    
                    renderApp(MemoryApp.results,id,MemoryApp.desc,MemoryApp.instance);
                    window[loadID].loading=false;
                    console.log("Memory Loaded..");
                    return; 
                } catch (error) {
                    console.log("Error Loading from Memory");
                    //alert("App not Loaded or permission Issue");
                }
                
            }
            ver="9.0";
            //var appObj = apps[appId];
            WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
                WEBDOCK.componentManager.downloadComponents(appId, descriptor,function(){
                    WEBDOCK.componentManager.getOnDemand(appId,descriptor, startupComponent, function(results,desc, instance){
                        if(instance){
                            try {
                                renderApp(results,id,desc,instance);
                                
                            } catch (error) {
                                alert("App not Loaded or permission Issue");
                            }
                            
                        }else{
                            alert("App not Loaded or permission Issue");
                        }
                        window[loadID].loading=false;
                        
                    },appObj.version);
                },appObj.version);       
            },appObj.version);

        });
        //});
    }

    function renderApp(data,id,desc,instance){
        try {
            var renderDiv = $("#" + id);
            renderDiv.empty();

            var vueData, view;        
            for (var i=0;i<data.length;i++)
            if (data[i].object.type === "mainView")
                view = data[i].object.view;

            renderDiv.html(view);
            renderDiv.attr("style", "animation: fadein 0.2s;padding-top: 0px;");
            //renderDiv.append("<div class='modal fade' id='appPopup0001' role='dialog' tabindex='-1'  style='overflow-x: auto;overflow-y: auto;width:100%;'><div class='modal-dialog modal-dialog-centered' role='document'><div class='modal-content' style='overflow-x: auto;overflow-y: auto;'><div class='modal-header'><h1>Appname</h1></div><div id='appbody' class='modal-body'>{{appbody}}}</div></div>");
            if (!instance)
                return;

            if (instance.onLoad)
                instance.onLoad(instance);
            
            var canCallOnReady = true;
            if (instance.vue){
                if (!$(renderDiv).attr('id'))
                    $(renderDiv).attr('id', "sossroutes_" + (new Date()).getTime() );

                instance.vue.el = '#' + $(renderDiv).attr('id');
                new Vue(instance.vue);
                scope = instance.vue.data;
                canCallOnReady = false;

                if (instance.vue.onReady){
                    if(completed){
                        instance.vue.onReady(scope,{status:"internalcall",data:data_collected,completedEvent:completed,renderDiv:renderDiv});
                    }else{
                        instance.vue.onReady(scope,renderDiv);
                    }
                }
            }

            if (canCallOnReady && instance.onReady)
                instance.onReady(renderDiv);
            
            callback(desc);
        } catch (e){
            console.log ("Error Occured While Loading...");
            console.log (e);
            errCallback(e);
        }
    }

});
