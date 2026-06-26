WEBDOCK.component().register(function(exports){
    var permittedApps = null;
    var permittedAppsLoading = false;
    var permittedAppsCallbacks = [];
    var appRenderRequestId = 0;

    var vueData = {
        onReady: function(scope, renderDiv, variables){
            var routeParams = variables && variables.routeParams ? variables.routeParams : {};
            var appName = routeParams.appName || "";
            var appRoute = normalizeAppRoute(routeParams.appRoute || "/");
            if(appRoute === ""){
                appRoute = "/";
            }
            renderApp(appName, appRoute, renderDiv, {
                appId: appName,
                appRoute: appRoute,
                routeParams: routeParams,
                queryParams: variables && variables.queryParams ? variables.queryParams : {}
            });
        }
    };

    exports.vue = vueData;
    exports.onReady = function(){};

    function normalizeAppRoute(route){
        route = route || "/";
        var queryIndex = route.indexOf("?");
        if(queryIndex !== -1){
            route = route.substring(0, queryIndex);
        }
        if(route.charAt(0) !== "/"){
            route = "/" + route;
        }
        route = route.replace(/\/+$/, "");
        return route || "/";
    }

    function renderApp(appId, appRoute, host, routeContext){
        host = host && host.length ? host : $(".cms-v7-legacy-app-route").first();
        var requestId = ++appRenderRequestId;
        host.html('<div class="cms-v7-loading">Loading app...</div>');
        if(!appId){
            host.html('<div class="cms-v7-error">App route is missing an app code.</div>');
            return;
        }
        loadPermittedApps(function(apps){
            if(!isCurrentAppRender(requestId)){
                return;
            }
            var appObj = findPermittedApp(apps, appId);
            if(!appObj){
                host.html('<div class="cms-v7-error">App not found or you do not have permission.</div>');
                return;
            }
            var requestedVersion = resolveAppVersion(appObj);
            WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
                if(!isCurrentAppRender(requestId)){
                    return;
                }
                if(!descriptor){
                    host.html('<div class="cms-v7-error">App not found.</div>');
                    return;
                }
                var webdock = resolveWebdockConfig(descriptor, appObj);
                var startupComponent = resolveStartupComponent(webdock, appRoute);
                if(!startupComponent){
                    host.html('<div class="cms-v7-error">App startup component is not configured.</div>');
                    return;
                }
                var version = resolveAppVersion(appObj, descriptor);
                WEBDOCK.componentManager.downloadComponents(appId, descriptor, function(){
                    preloadWebdockComponents(appId, descriptor, webdock, version, startupComponent, function(){
                        if(!isCurrentAppRender(requestId)){
                            return;
                        }
                        WEBDOCK.componentManager.getOnDemand(appId, descriptor, startupComponent, function(results, desc, instance){
                            if(!isCurrentAppRender(requestId)){
                                return;
                            }
                            try {
                                renderAppComponent(host, results, instance, routeContext);
                            } catch(error) {
                                host.html('<div class="cms-v7-error">App could not be loaded.</div>');
                                if(window.console && console.log){
                                    console.log(error);
                                }
                            }
                        }, version);
                    });
                }, version);
            }, requestedVersion);
        });
    }

    function loadPermittedApps(callback){
        if(window.apps){
            permittedApps = window.apps;
            callback(permittedApps);
            return;
        }
        if(permittedApps){
            callback(permittedApps);
            return;
        }
        permittedAppsCallbacks.push(callback);
        if(permittedAppsLoading){
            return;
        }
        permittedAppsLoading = true;
        if(!WEBDOCK.callRest){
            finishPermittedAppsLoad({});
            return;
        }
        WEBDOCK.callRest("components/object/apps?tags=showincms")
            .success(function(data){
                finishPermittedAppsLoad(data && data.result ? data.result : {});
            })
            .error(function(){
                finishPermittedAppsLoad({});
            });
    }

    function finishPermittedAppsLoad(apps){
        permittedApps = apps || {};
        window.apps = permittedApps;
        permittedAppsLoading = false;
        var callbacks = permittedAppsCallbacks.slice();
        permittedAppsCallbacks = [];
        for(var i = 0; i < callbacks.length; i++){
            callbacks[i](permittedApps);
        }
    }

    function findPermittedApp(apps, appId){
        if(!apps || !appId){
            return null;
        }
        if(apps[appId]){
            return apps[appId];
        }
        for(var key in apps){
            if(Object.prototype.hasOwnProperty.call(apps, key)){
                var item = apps[key];
                if(key === appId || item && (item.appcode === appId || item.appCode === appId || item.id === appId || item.name === appId)){
                    return item;
                }
            }
        }
        return null;
    }

    function resolveWebdockConfig(descriptor, appObj){
        if(descriptor && descriptor.configuration && descriptor.configuration.webdock){
            return descriptor.configuration.webdock;
        }
        if(descriptor && descriptor.config && descriptor.config.webdock){
            return descriptor.config.webdock;
        }
        if(appObj && appObj.config && appObj.config.webdock){
            return appObj.config.webdock;
        }
        if(appObj && appObj.configuration && appObj.configuration.webdock){
            return appObj.configuration.webdock;
        }
        return null;
    }

    function resolveStartupComponent(webdock, appRoute){
        if(!webdock){
            return "";
        }
        var partials = webdock.routes && webdock.routes.partials ? webdock.routes.partials : {};
        var normalizedRoute = normalizeAppRoute(appRoute);
        if(partials[normalizedRoute]){
            return partials[normalizedRoute];
        }
        if(partials[appRoute]){
            return partials[appRoute];
        }
        for(var route in partials){
            if(Object.prototype.hasOwnProperty.call(partials, route) && normalizeAppRoute(route) === normalizedRoute){
                return partials[route];
            }
        }
        return webdock.startupComponent || "";
    }

    function resolveAppVersion(appObj, descriptor){
        if(appObj && appObj.version){
            return appObj.version;
        }
        if(appObj && appObj.description && appObj.description.version){
            return appObj.description.version;
        }
        if(descriptor && descriptor.description && descriptor.description.version){
            return descriptor.description.version;
        }
        return undefined;
    }

    function preloadWebdockComponents(appId, descriptor, webdock, version, startupComponent, done){
        var components = normalizeComponentList(webdock && webdock.onLoad ? webdock.onLoad : []);
        var index = 0;
        function next(){
            if(index >= components.length){
                done();
                return;
            }
            var component = components[index++];
            if(!component || component === startupComponent || !descriptor.components || !descriptor.components[component]){
                next();
                return;
            }
            try {
                WEBDOCK.componentManager.getOnDemand(appId, descriptor, component, function(){
                    next();
                }, version);
            } catch(error) {
                if(window.console && console.log){
                    console.log(error);
                }
                next();
            }
        }
        next();
    }

    function normalizeComponentList(value){
        if(!value){
            return [];
        }
        if(typeof value === "string"){
            value = [value];
        }
        if(Object.prototype.toString.call(value) !== "[object Array]"){
            return [];
        }
        var output = [];
        for(var i = 0; i < value.length; i++){
            var component = value[i];
            if(component && typeof component === "object"){
                component = component.name || component.component || component.id || "";
            }
            component = component ? component.toString() : "";
            if(component && output.indexOf(component) === -1){
                output.push(component);
            }
        }
        return output;
    }

    function isCurrentAppRender(requestId){
        return requestId === appRenderRequestId;
    }

    function renderAppComponent(host, data, instance, routeContext){
        var view = "";
        if(data){
            for(var i = 0; i < data.length; i++){
                if(data[i].object.type === "mainView"){
                    view = data[i].object.view;
                }
            }
        }
        if(!view || !instance){
            host.html('<div class="cms-v7-error">App could not be loaded.</div>');
            return;
        }
        host.html(view);
        if(instance.onLoad){
            instance.onLoad(instance);
        }
        if(instance.cmsV7OwnMount && instance.onReady){
            instance.onReady(host, routeContext || {});
            return;
        }
        if(instance.vue && typeof Vue !== "undefined"){
            if(!host.attr("id")){
                host.attr("id", "cms_v7_legacy_app_" + new Date().getTime());
            }
            instance.vue.el = "#" + host.attr("id");
            new Vue(instance.vue);
            if(instance.vue.onReady){
                instance.vue.onReady(instance.vue.data, host, routeContext || {routeParams: {}, queryParams: {}});
            }
        }else if(instance.onReady){
            instance.onReady(host, routeContext || {});
        }
    }
});
