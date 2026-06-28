
WEBDOCK.component().register(function(exports, scope){
    var $progress,$progressBar,$closebutton,$modal=[];
    var applist=[];
    var AppIndex=0;
    var AppInstance=[];
    exports.initialize = function(){
        
        //clearCeate();
    }
    var callback=[];
    var errCallback=[],data_collected=[];
    var completed=[];


    exports.launchApp=function(launcherInfo,cb,er,cbcompleted,data){
        if (!launcherInfo) {
            if (er) {
                er("Launcher information is required.");
            }
            return;
        }
        let launcher_data=GetLauncherData(launcherInfo,data);
        let id=safeId((launcherInfo.appcode || "app")+"_"+(launcherInfo.subappcode || "main"));
        window.launcher_data=window.launcher_data?window.launcher_data:{};
        //{:launcher_data}
        
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
            if (!app || !component) {
                return;
            }
            var data=parseDataAttribute($(this).attr("webdock-data"), _data);
            var element_id=safeId(app+"-"+component+"-"+(new Date()).getTime()+"-"+i);
            $(this).attr('id', element_id);
            applist.push({"component":component,"app":app,"data":data,"element_id":element_id,
            "Completed":Completed,"Error":Error,"appComplete":appComplete});
        });

        if (applist.length === 0) {
            if (appComplete) {
                appComplete(_data);
            }
            return;
        }
        loadApp();
    }

    function loadApp(){
        if (!applist[AppIndex]) {
            return;
        }
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
        var wa= document.getElementById(id+"_popup");
        if(wa){
            wa.remove();
        }
        var bodyEt=$("body");
        bodyEt.append("<div id='"+id+"_popup' class='modal fade bd-example-modal-lg' tabindex='-1' role='dialog' aria-labelledby='"+id+"_title' aria-hidden='true'><div class='modal-dialog modal-lg' role='document'><div class='modal-content'><div class='modal-header'> <h5 id='"+id+"_title' class='modal-title'>"+escapeHtml(title || "")+"</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div><div id='"+id+"_window' class='modal-body'></div></div></div></div>");
    }

    function popup_small(id,title){
        var wa= document.getElementById(id+"_popup");
        if(wa){
            wa.remove();
        }
        var bodyEt=$("body");
        bodyEt.append("<div id='"+id+"_popup' class='modal fade' tabindex='-1' role='dialog' aria-labelledby='"+id+"_title' aria-hidden='true'><div class='modal-dialog' role='document'><div class='modal-content'><div class='modal-header'> <h5 id='"+id+"_title' class='modal-title'>"+escapeHtml(title || "")+"</h5><button type='button' class='close' data-dismiss='modal' aria-label='Close'><span aria-hidden='true'>&times;</span></button></div><div id='"+id+"_window' class='modal-body'></div></div></div></div>");
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

    function dispose(id){
        $("#" + id + "_popup").remove();
    }

    function popup_real(id,appId,startupComponent,cb,er,cbcompleted,data){
        
        RenderApplication(appId,startupComponent,id+"_window",cb,er,function(d){
            $modal[id]=$("#"+id+'_popup');
            $modal[id].off('hidden.bs.modal.davvagDownloader').one('hidden.bs.modal.davvagDownloader', function () {
                if(cbcompleted)
                    cbcompleted(d);

                dispose(id);
                
            });
            $modal[id].modal('hide');

            
        },data);
    }

    function GetLauncherData(launcher,data){
        try{
            if (!launcher.inputData) {
                return data || null;
            }
            let data_interpreter=JSON.parse(launcher.inputData);
            let returnData={}
            //{"name":"comments","datatype":"string","mappingfield":""}]
            data_interpreter.forEach(element => {
                returnData[element.name]=data && data[element.mappingfield] !== undefined ? data[element.mappingfield]:null;
            });
            return returnData;
        }catch(e){
            console.log(e);
            return data || null;
        }
    }
    
    
    
    function RenderApplication(appId,startupComponent,id,cb,er,cbcompleted,d){
        callback[id]=cb;
        errCallback[id]=er;
        completed[id]=cbcompleted;
        data_collected[id]=d;
        var leftMenu = exports.getShellComponent("left-menu");
        if (!leftMenu || typeof leftMenu.getApps !== "function") {
            renderLoadError(id, "Application menu service is not available.");
            if (er) {
                er("Application menu service is not available.");
            }
            return;
        }
        leftMenu.getApps(function(apps){
            var renderDiv = $("#" + id);
            renderDiv.empty();
            var appObj = apps && apps[appId] ? apps[appId] : null;
            if(appObj==null){
                renderLoadError(id, "App was not found or permission is missing.");
                if (er) {
                    er("App was not found or permission is missing.");
                }
                return;
            }
            var loadID=safeId("dft_" + id+"_app0001");
            window[loadID]=window[loadID]?window[loadID]:{loading:false,apps:{app:{}}};
            window[loadID].apps[appId]=window[loadID].apps[appId]?window[loadID].apps[appId]:{loading:false,app:{}};
            //var appdock= window[loadID].apps[appId];
            if(window[loadID].loading){
                console.log("Already Same component loading.....");
                return;
            }

            window[loadID].loading=true;
            

            let MemoryApp= WEBDOCK.componentManager.getMemoryApp(appId,startupComponent);
            if(MemoryApp && MemoryApp.results){
                try {
                    
                    renderApp(MemoryApp.results,id,MemoryApp.desc,MemoryApp.instance,appId);
                    window[loadID].loading=false;
                    console.log("Memory Loaded..");
                    return; 
                } catch (error) {
                    renderLoadError(id, "Error loading application from memory.");
                    window[loadID].loading=false;
                    if (er) {
                        er(error);
                    }

                    //console.log("Error Loading from Memory");
                    //alert("App not Loaded or permission Issue");
                }
                
            }
            var ver="9.0";
            //var appObj = apps[appId];
            WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
                WEBDOCK.componentManager.downloadComponents(appId, descriptor,function(){
                    WEBDOCK.componentManager.getOnDemand(appId,descriptor, startupComponent, function(results,desc, instance){
                        if(instance){
                            try {
                                renderApp(results,id,desc,instance,appId);
                                
                            } catch (error) {
                                renderLoadError(id, "App did not load. Please check permission and component registration.");
                                if (er) {
                                    er(error);
                                }

                                //alert("App not Loaded or permission Issue");
                            }
                            
                        }else{
                            renderLoadError(id, "App did not load. Please check permission and component registration.");
                            if (er) {
                                er("App instance was not created.");
                            }

                            //alert("App not Loaded or permission Issue");
                        }
                        window[loadID].loading=false;
                        
                    },appObj.version);
                },appObj.version);       
            },appObj.version);

        });
        //});
    }

    function renderApp(data,id,desc,instance,appid){
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
                    if(completed[id]){
                        var obj={};
                        if (data_collected[id] && typeof data_collected[id] === "object") {
                            Object.assign(obj,data_collected[id]);
                        }
                        instance.dataObject=data_collected[id];
                        instance.Complete=completed[id];
                        instance.renderDiv=renderDiv;
                        instance.vue.onReady(scope,{status:"internalcall",data:data_collected[id],completedEvent:completed,renderDiv:renderDiv});
                    }else{
                        instance.Complete=function(data){
                            if (typeof instance.onStatusChange === "function") {
                                instance.onStatusChange(data);
                            }
                        };
                        instance.renderDiv=renderDiv;
                        instance.vue.onReady(scope,renderDiv);
                    }
                }
            }

            if (canCallOnReady && instance.onReady)
                instance.onReady(renderDiv);
            
                if(callback[id]){
                    callback[id](desc);
                }
        } catch (e){
            console.log ("Error Occured While Loading...");
            console.log (e);
            if(errCallback[id])
                errCallback[id](e);
        }
    }

    function parseDataAttribute(value, fallback) {
        if (!value) {
            return fallback;
        }
        try {
            return JSON.parse(value);
        } catch (e1) {
            try {
                return JSON.parse(decodeURIComponent(value));
            } catch (e2) {
                try {
                    return JSON.parse(window.atob(value));
                } catch (e3) {
                    return fallback;
                }
            }
        }
    }

    function renderLoadError(id, message) {
        $("#" + id).html("<h2 class='davvag-app-downloader-error'>Error Downloading Application</h2><div class='alert alert-danger' role='alert'>" + escapeHtml(message) + "</div>");
    }

    function safeId(value) {
        return String(value || "app").replace(/[^A-Za-z0-9_-]/g, "_");
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    exports.RenderHTML=RenderHTML;
    exports.downloadAPP=RenderApplication;

});
