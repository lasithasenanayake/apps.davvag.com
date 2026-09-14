
WEBDOCK.component().register(function(exports, scope){
    var $progress,$progressBar,$closebutton,$modal=[];
    var embedId=0;
    var descriptorRequests=Object.create(null);
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

    function RenderHTML(element,Completed,Error,appComplete,_data,options){
        var AppIndex=0;
        var applist=[];
        
        element.find("[webdock-component]").each(function(i,el){
           
            var component = String($(this).attr("webdock-component") || "").trim();
            var app=String($(this).attr("webdock-app") || "").trim();
            if (!component) {
                return;
            }
            var data=parseDataAttribute($(this).attr("webdock-data"), _data);
            var element_id=safeId(app+"-"+component+"-"+(new Date()).getTime()+"-"+(++embedId));
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

        // Each HTML section owns its queue, including when sections load concurrently.
        function loadApp(){
            if (!applist[AppIndex] || options && options.isCurrent && !options.isCurrent()) {
                return;
            }
            var item = applist[AppIndex];
            RenderApplication(item.app,item.component,item.element_id,function(r){
                if (Completed) {
                    Completed(r);
                }
                AppIndex++;
                loadApp();
            },function(e){
                if (Error) {
                    Error(e);
                }
                AppIndex++;
                loadApp();
            },item.appComplete,item.data,options);
        }
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
    
    
    
    // Share in-flight descriptor lookups across concurrent component-only embeds.
    // The framework owns the completed descriptor cache.
    function downloadDescriptor(appId, version, done){
        if(descriptorRequests[appId]){
            descriptorRequests[appId].push(done);
            return;
        }
        descriptorRequests[appId] = [done];
        WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
            var callbacks = descriptorRequests[appId];
            delete descriptorRequests[appId];
            callbacks.forEach(function(callback){ callback(descriptor); });
        }, version);
    }

    function resolveComponentApp(apps, component, isCurrent, done){
        var appIds = Object.keys(apps || {});
        var matches = [];
        var failed = false;
        var index = 0;
        function next(){
            if(!isCurrent()){
                return;
            }
            if(index >= appIds.length){
                if(failed){
                    done("Unable to inspect available apps. Try again or specify webdock-app.");
                }else if(matches.length > 1){
                    done('Component "' + component + '" exists in multiple apps (' + matches.map(function(match){ return match.appId; }).join(", ") + '). Specify webdock-app.');
                }else if(matches.length === 0){
                    done('No accessible CMS app provides component "' + component + '". Check the component name, showincms tag and user-group access.');
                }else{
                    done(null, matches[0].appId, matches[0].descriptor);
                }
                return;
            }
            var appId = appIds[index++];
            downloadDescriptor(appId, apps[appId] && apps[appId].version, function(descriptor){
                if(!descriptor || !descriptor.components){
                    failed = true;
                }else if(Object.prototype.hasOwnProperty.call(descriptor.components, component)){
                    matches.push({appId: appId, descriptor: descriptor});
                }
                next();
            });
        }
        next();
    }

    function RenderApplication(appId,startupComponent,id,cb,er,cbcompleted,d,options){
        callback[id]=cb;
        errCallback[id]=er;
        completed[id]=cbcompleted;
        data_collected[id]=d;
        // CMS uses its own API-backed app list; existing dock callers keep left-menu.
        var provider = options && options.getApps ? options
            : window.CMSV7 && typeof window.CMSV7.getApps === "function" ? window.CMSV7
            : exports.getShellComponent("left-menu");
        if(!provider || typeof provider.getApps !== "function"){
            fail("Application list service is not available.");
            return;
        }
        provider.getApps(function(apps){
            if(!isCurrent()){
                return;
            }
            $("#" + id).empty();
            if(appId){
                if(!apps || !Object.prototype.hasOwnProperty.call(apps, appId)){
                    fail('App "' + appId + '" is not in the available app list. Check its showincms tag and user-group access.');
                    return;
                }
                downloadDescriptor(appId, apps[appId].version, function(descriptor){
                    load(appId, apps[appId], descriptor);
                });
            }else{
                resolveComponentApp(apps, startupComponent, isCurrent, function(error, resolvedAppId, descriptor){
                    if(error){
                        fail(error);
                        return;
                    }
                    load(resolvedAppId, apps[resolvedAppId], descriptor);
                });
            }
        });

        function load(resolvedAppId, appObj, descriptor){
            if(!isCurrent()){
                return;
            }
            if(!descriptor || !descriptor.components || !Object.prototype.hasOwnProperty.call(descriptor.components, startupComponent)){
                fail("App component is not registered.");
                return;
            }
            $("#" + id).attr("webdock-app", resolvedAppId);
            var version = descriptor.description && descriptor.description.version || appObj.version;
            WEBDOCK.componentManager.downloadComponents(resolvedAppId, descriptor, function(){
                if(!isCurrent()){
                    return;
                }
                WEBDOCK.componentManager.getOnDemand(resolvedAppId, descriptor, startupComponent, function(results, desc, instance){
                    if(!isCurrent()){
                        return;
                    }
                    if(!instance){
                        fail("App instance was not created.");
                        return;
                    }
                    renderApp(results, id, desc, instance, resolvedAppId);
                }, version);
            }, version);
        }
        function fail(message){
            if(!isCurrent()){
                return;
            }
            renderLoadError(id, message);
            if(er){
                er(message);
            }
        }
        function isCurrent(){
            return !!document.getElementById(id) && (!options || !options.isCurrent || options.isCurrent());
        }
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
