WEBDOCK.component().register(function(exports){
   
    var vueData = {
        data:{apps:[]},
        methods: {
            navigateApp: navigateApp,
            navigateSubapp: navigateSubapp
        }
    }

    function navigateApp(appKey,value){
        setNavigationTitle(appKey,value);
        navigateTo("#/app/" + appKey);
    }

    function navigateSubapp(appKey,value,path){
        path = String(path || "");
        setNavigationTitle(appKey,value);
        navigateTo(path.indexOf("#/") === 0 ? path : "#/app/" + appKey + "/" + path.replace(/^\/+/,""));
    }

    function setNavigationTitle(appKey,value){
        var titleComponent = exports.getComponent("navigation-title");
        if(titleComponent && typeof titleComponent.setDisplayData === "function"){
            titleComponent.setDisplayData(appKey,value);
        }
    }

    function navigateTo(path){
        var router = exports.getComponent("soss-routes");
        if(router && typeof router.appNavigate === "function"){
            router.appNavigate(path);
        }else{
            window.location.href = path;
        }
    }

    var isAppsLoaded = false;
    var appLoadedCallbacks = [];
    exports.onReady = function(element){
        vueData.el = '#' + $(element).attr('id');
        new Vue(vueData);

        WEBDOCK.callRest("components/object/apps?tags=showindock")
        .success(function(data){
            vueData.data.apps = data.result;
            isAppsLoaded = true;
            appLoadedCallbacks.forEach(function(callback){
                callback(data.result);
            });
            appLoadedCallbacks = [];
        })
        .error(function(){

        });
    }

    exports.getApps = function(callback){
        if (isAppsLoaded)
            callback(vueData.data.apps);
        else
            appLoadedCallbacks.push(callback);
    }

});
