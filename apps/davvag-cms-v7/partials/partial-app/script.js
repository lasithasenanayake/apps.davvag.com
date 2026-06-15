WEBDOCK.component().register(function(exports){
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
        host.html('<div class="cms-v7-loading">Loading app...</div>');
        if(!appId){
            host.html('<div class="cms-v7-error">App route is missing an app code.</div>');
            return;
        }
        WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
            if(!descriptor){
                host.html('<div class="cms-v7-error">App not found.</div>');
                return;
            }
            var startupComponent = resolveStartupComponent(descriptor, appRoute);
            if(!startupComponent){
                host.html('<div class="cms-v7-error">App startup component is not configured.</div>');
                return;
            }
            var version = descriptor.description ? descriptor.description.version : undefined;
            WEBDOCK.componentManager.downloadComponents(appId, descriptor, function(){
                WEBDOCK.componentManager.getOnDemand(appId, descriptor, startupComponent, function(results, desc, instance){
                    renderAppComponent(host, results, instance, routeContext);
                }, version);
            }, version);
        });
    }

    function resolveStartupComponent(descriptor, appRoute){
        var startupComponent = null;
        if(descriptor.configuration && descriptor.configuration.webdock){
            var webdock = descriptor.configuration.webdock;
            if(webdock.routes && webdock.routes.partials && webdock.routes.partials[normalizeAppRoute(appRoute)]){
                startupComponent = webdock.routes.partials[normalizeAppRoute(appRoute)];
            }
            if(!startupComponent){
                startupComponent = webdock.startupComponent;
            }
        }
        return startupComponent;
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
