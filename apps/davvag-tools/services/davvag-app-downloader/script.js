
WEBDOCK.component().register(function(exports, scope){
    var $progress,$progressBar,$closebutton,$modal;
    var applist=[];
    var AppIndex=0;
    exports.initialize = function(){
        
        //clearCeate();
    }
    var callback;
    var errCallback,completed,data_collected;


    exports.launchApp=function(launcherInfo,cb,er,cbcompleted,data){
        let launcher_data=GetLauncherData(launcherInfo,data);
        window.launcher_data=window.launcher_data?window.launcher_data:{};
        //{:launcher_data}
        let id=launcherInfo.appcode+"_"+launcherInfo.subappcode;
        window.launcher_data[id]=launcher_data;
        if(launcherInfo.applicationtype=="application"){
            switch(launcherInfo.window_type){
                case "popup":
                    popup_small(id,launcherInfo.name);
                    Popup(id,launcherInfo.appcode,launcherInfo.subappcode,cb,er,cbcompleted,launcher_data);
                    break;
                case "popup-lock":
                    popup_small(id,launcherInfo.name);
                    Popup_lock(id,launcherInfo.appcode,launcherInfo.subappcode,cb,er,cbcompleted,launcher_data);
                    break;
                case "popup-large":
                    popup_large(id,launcherInfo.name);
                    Popup(id,launcherInfo.appcode,launcherInfo.subappcode,cb,er,cbcompleted,launcher_data);
                    break;
                case "popup-lock-large":
                    popup_large(id,launcherInfo.name);
                    Popup_lock(id,launcherInfo.appcode,launcherInfo.subappcode,cb,er,cbcompleted,launcher_data);
                    break;
                case "web-uri-new-window":
                    window.open(
                        window.location.protocol+'//'+window.location.host+window.location.pathname+"#/app/"+launcherInfo.appcode+"/"+launcherInfo.subappcode,
                        '_blank' // <- This is what makes it open in a new window.
                    );
                    break;
                default:
                    window.location="#/app/"+launcherInfo.appcode+"/"+launcherInfo.subappcode;
                    break;

            }
        }else{
            window.location=launcherInfo.url;
        }
    }

    function RenderHTML(element,Completed,Error,appComplete,_data){
        AppIndex=0;
        applist=[];
        
        element.find("[webdock-app]").each(function(i,el){
           
            var component = $(this).attr("webdock-component");
            var app=$(this).attr("webdock-app");
            var data=$(this).attr("webdock-data")?JSON.parse(btoa($(this).attr("webdock-data"))):_data;
            var element_id=app+"-"+component+(new Date()).getTime();
            $(this).attr('id', element_id);
            applist.push({"component":component,"app":app,"data":data,"element_id":element_id,
            "Completed":Completed,"Error":Error,"appComplete":appComplete});
        });

        loadApp();
    }

    function loadApp(){
        RenderApplication(applist[AppIndex].app,applist[AppIndex].component,applist[AppIndex].element_id,function(r){
            AppIndex++;
            if(applist.length>AppIndex){
                loadApp();
            }
        },function(e){
            AppIndex++;
            if(applist.length>AppIndex){
                loadApp();
            }
        },applist[AppIndex].appComplete,applist[AppIndex].data);
    }

    function popup_large(id,title){
        wa= document.getElementById(id+"_popup");
        if(wa){
            wa.remove();
        }
        bodyEt=$("body");
        bodyEt.append("<div id='"+id+"_popup' class='modal fade bd-example-modal-lg' tabindex='-1' role='dialog' aria-labelledby='"+id+"_popup' aria-hidden='true'><div class='modal-dialog modal-lg' role='document'><div class='modal-content'><div class='modal-header'> <h5 id='"+id+"_title' class='modal-title' id='modalLabel'>"+title+"</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div><div id='"+id+"_window' class='modal-body'></div></div></div>");
    }

    function popup_small(id,title){
        wa= document.getElementById(id+"_popup");
        if(wa){
            wa.remove();
        }
        bodyEt=$("body");
        bodyEt.append("<div id='"+id+"_popup' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='"+id+"_popup' aria-hidden='true'><div class='modal-dialog' role='document'><div class='modal-content'><div class='modal-header'> <h5 id='"+id+"_title' class='modal-title' id='modalLabel'>"+title+"</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div><div id='"+id+"_window' class='modal-body'></div></div></div>");
    }

    function Popup(id,appId,startupComponent,cb,er,cbcompleted,data){
        popup_real(id,appId,startupComponent,cb,er,cbcompleted,data);
        $("#"+id+'_popup').modal('show');
        //modal
    }

    function Popup_lock(id,appId,startupComponent,cb,er,cbcompleted,data){
        popup_real(id,appId,startupComponent,cb,er,cbcompleted,data);
        $("#"+id+'_popup').modal({backdrop: 'static', keyboard: false});
        
    }

    function popup_real(id,appId,startupComponent,cb,er,cbcompleted,data){
        
        RenderApplication(appId,startupComponent,id+"_window",cb,er,function(d){
            $modal=$("#"+id+'_popup');
            $modal.on('hidden.bs.modal', function () {
                if(cbcompleted)
                    cbcompleted(d);
            });
            $modal.modal('toggle');

            
        },data);
    }

    function GetLauncherData(launcher,data){
        try{
            let data_interpreter=JSON.parse(launcher.inputData);
            let returnData={}
            //{"name":"comments","datatype":"string","mappingfield":""}]
            data_interpreter.forEach(element => {
                returnData[element.name]=data[element.mappingfield]?data[element.mappingfield]:null;
            });
            return returnData;
        }catch(e){
            console.log(e);
            return null;
        }
    }
    
    
    
    function RenderApplication(appId,startupComponent,id,cb,er,cbcompleted,d){
        callback=cb;
        errCallback=er;
        completed=cbcompleted;
        data_collected=d;
        var leftMenu = exports.getShellComponent("left-menu");
        leftMenu.getApps(function(apps){
            var renderDiv = $("#" + id);
            renderDiv.empty();
            var appObj = apps[appId];
            if(appObj==null){
                console.log("Error Downloading Application (Check Permission).....");
                //var renderDiv = $("#" + id);
                renderDiv.html('<h2 class="alert-danger">Error Downloading Application</h2><div class="alert alert-danger" role="alert">App Null Exception.</div>');
                return;
            }
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
                    renderDiv.html('<h2>Error Downloading Application</h2><div class="alert alert-danger" role="alert">Error Loading from Memory.</div>');

                    //console.log("Error Loading from Memory");
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
                                renderDiv.html('<h2>Error Downloading Application</h2><div class="alert alert-danger" role="alert">App not Loaded or permission Issue.</div>');

                                //alert("App not Loaded or permission Issue");
                            }
                            
                        }else{
                            renderDiv.html('<h2>Error Downloading Application</h2><div class="alert alert-danger" role="alert">App not Loaded or permission Issue.</div>');

                            //alert("App not Loaded or permission Issue");
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
                    $(renderDiv).attr('id', "davvag_app_" + (new Date()).getTime() );

                instance.vue.el = '#' + $(renderDiv).attr('id');
                new Vue(instance.vue);
                scope = instance.vue.data;
                canCallOnReady = false;

                if (instance.vue.onReady){
                    if(completed){
                        var obj={};
                        Object.assign(obj,data_collected);
                        instance.dataObject=obj;
                        instance.Complete=completed;
                        instance.renderDiv=renderDiv;
                        instance.vue.onReady(scope,{status:"internalcall",data:obj,completedEvent:completed,renderDiv:renderDiv});
                    }else{
                        instance.Complete=function(data){
                            instance.onStatusChange(data);
                        };
                        instance.renderDiv=renderDiv;
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
            if(errCallback)
                errCallback(e);
        }
    }
    exports.RenderHTML=RenderHTML;
    exports.downloadAPP=RenderApplication;

});
