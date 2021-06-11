WEBDOCK.component().register(function(exports){
    
    var pInstance = exports.getShellComponent("soss-routes");
    var routeSettings = pInstance.getSettings();

    var vueData =  {
        methods:{
        },
        data :{
            appName:undefined
        },
        deferRendering : true,
        onReady: function(s, renderDiv, variables){
            var appName = variables.routeParams.appName;
            var subRoute = variables.routeParams.appRoute;
            downloadApp(appName,subRoute);
        }
    } 

    exports.vue = vueData;
    exports.onReady = function(){
        
    }
    var appCallBack=null;
    exports.openAPP=function(appId,startupComponent,callBack){
        //renderDiv.empty();
        //showLoadingBar(renderDiv);
        appCallBack=callBack;
        $('#appPopup0001').modal({backdrop: 'static', keyboard: false});
       /* WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
            WEBDOCK.componentManager.downloadComponents(appId, descriptor,function(){
                WEBDOCK.componentManager.getOnDemand(appId,descriptor, startupComponent, function(results,desc, instance){
                    renderApp(results,desc,instance);
                    WEBDOCK.freezeUiComponent("left-menu",false);
                },appObj.version);
            },appObj.version);       
        },appObj.version);*/
    }

    exports.closeAPP=function(results){
        if(appCallBack){
            appCallBack(results);
        }
        $('#appPopup0001').modal('toggle');
    }

    function downloadApp(appId,subRoute){
        var leftMenu = exports.getComponent("left-menu");
        WEBDOCK.freezeUiComponent("left-menu",true);
        leftMenu.getApps(function(apps){
            var appObj = apps[appId];
            var startupComponent;
            var renderDiv = $("#" + routeSettings.routes.renderDiv);
            var loadID=routeSettings.routes.renderDiv+"_app";
            renderDiv.empty();
            //window[routeSettings.routes.renderDiv]=window[routeSettings.routes.renderDiv]?window[routeSettings.routes.renderDiv]:{apps:{},loading:false};
            window[loadID]=window[loadID]?window[loadID]:{loading:false,apps:{app:{}}};
            

            window[loadID].apps[appId]=window[loadID].apps[appId]?window[loadID].apps[appId]:{loading:false,app:{}};
            var appdock= window[loadID].apps[appId];
            if(window[loadID].loading){
                console.log("Already Same component loading.....");
                return;
            }
            if(window[routeSettings.routes.renderDiv])
                delete(window[routeSettings.routes.renderDiv]);
            window[loadID].loading=true;
            if(appObj==null){
                showAPPIssue(renderDiv);
                window[loadID].loading=false;
                return;
            }
            if (appObj.config.webdock)
            if (appObj.config.webdock.routes)
            if (appObj.config.webdock.routes.partials)
            if (appObj.config.webdock.routes.partials[subRoute])
                startupComponent = appObj.config.webdock.routes.partials[subRoute];
    
            if (!startupComponent)
                startupComponent = appObj.config.webdock.startupComponent;
            
            
            appdock.app[startupComponent]=appdock.app[startupComponent]?appdock.app[startupComponent]:{};

            showLoadingBar(renderDiv);
            
            if(appdock.app[startupComponent].results){
                try {
                    
                    renderApp(appdock.app[startupComponent].results,appdock.app[startupComponent].desc,appdock.app[startupComponent].instance);
                    window[loadID].loading=false;
                    return; 
                } catch (error) {
                    alert("App not Loaded or permission Issue");
                }
                
            }

            WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
                WEBDOCK.componentManager.downloadComponents(appId, descriptor,function(){
                    WEBDOCK.componentManager.getOnDemand(appId,descriptor, startupComponent, function(results,desc, instance){
                        
                        if(instance){
                            try {
                                renderApp(results,desc,instance);
                                appdock.app[startupComponent].results=results;
                                appdock.app[startupComponent].desc=desc;
                                appdock.app[startupComponent].instance=instance;
                                window[loadID].apps[appId]=appdock;
                                
                            } catch (error) {
                                alert("App not Loaded or permission Issue");
                            }
                            
                        }else{
                            alert("App not Loaded or permission Issue");
                            //console.log("Reloading... site please wait.NUll Exception APPID:" +compAppId)
                            //location.reload();
                        }
                        window[loadID].loading=false;
                        WEBDOCK.freezeUiComponent("left-menu",false);
                    },appObj.version);
                },appObj.version);       
            },appObj.version);
        });
        
    }

    function showLoadingBar(renderDiv){
        renderDiv.append('<div id="preloader"><div id="status"><i class="fa fa-spinner fa-spin"></i></div></div>');
    }

    function showAPPIssue(renderDiv){
        renderDiv.append('<div class="page-wrap d-flex flex-row align-items-center"><div class="container"><div class="row justify-content-center"><div class="col-md-12 text-center"><span class="display-1 d-block">404</span><div class="mb-4 lead">The page you are looking for was not found.</div><a href="javascript:window.history.back();" class="btn btn-link">Back to Home</a></div></div></div></div>');
    }

    function showAPPErrors(renderDiv){
        renderDiv.html('<div class="page-wrap d-flex flex-row align-items-center"><div class="container"><div class="row justify-content-center"><div class="col-md-12 text-center"><span class="display-1 d-block">404</span><div class="mb-4 lead">The page you are looking for Has a Error Please try to </div><a href="javascript:location.reload();" class="btn btn-link">Reload Page</a></div></div></div></div>');
    }

    function renderApp(data,desc,instance){
        var renderDiv = $("#" + routeSettings.routes.renderDiv);
        renderDiv.empty();
        try {
            

            var vueData, view;        
            for (var i=0;i<data.length;i++)
            if (data[i].object.type === "mainView")
                view = data[i].object.view;

           
            //renderDiv.append("<div class='modal fade' id='appPopup0001' role='dialog' tabindex='-1'  style='overflow-x: auto;overflow-y: auto;width:100%;'><div class='modal-dialog modal-dialog-centered' role='document'><div class='modal-content' style='overflow-x: auto;overflow-y: auto;'><div class='modal-header'><h1>Appname</h1></div><div id='appbody' class='modal-body'>{{appbody}}}</div></div>");
            if (!instance)
                return;


            if (instance.onLoad)
                instance.onLoad(instance);
            
            renderDiv.html(view);
            renderDiv.attr("style", "animation: fadein 0.2s;padding-top: 0px;");
            var canCallOnReady = true;
            if (instance.vue){
                if (!$(renderDiv).attr('id'))
                    $(renderDiv).attr('id', "sossroutes_" + (new Date()).getTime() );

                instance.vue.el = '#' + $(renderDiv).attr('id');
                new Vue(instance.vue);
                scope = instance.vue.data;
                canCallOnReady = false;

                if (instance.vue.onReady){
                    instance.vue.onReady(scope,renderDiv);
                }
            }

            if (canCallOnReady && instance.onReady)
                instance.onReady(renderDiv);
            
        } catch (e){
            showAPPErrors(renderDiv);
            console.log ("Error Occured While Loading...");
            console.log (e);
        }
    }
    
});
