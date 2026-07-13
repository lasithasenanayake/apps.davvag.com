WEBDOCK.component().register(function(exports){
    var availableApps = {};
    var searchableApps = [];
    var appsLoaded = false;
    var closeTimer;

    var vueData = {
        data:{
            userData: {
                email : "Loading"
            },
            appData : {
                title:""
            },
            appKey : "",
            searchQuery: "",
            searchResults: [],
            searchOpen: false,
            searchMessage: "",
            activeSearchIndex: -1
        },
        methods: {
            signout: signout,
            submitSearch: submitSearch,
            updateSearch: updateSearch,
            openSearch: openSearch,
            closeSearch: closeSearch,
            dismissSearch: dismissSearch,
            moveSearchSelection: moveSearchSelection,
            launchSearchResult: launchSearchResult,
            userInitial: userInitial
        }
    };

    function loadApps(){
        var leftMenu = exports.getComponent("left-menu");
        if(!leftMenu || typeof leftMenu.getApps !== "function"){
            vueData.data.searchMessage = "App search is unavailable.";
            return;
        }

        leftMenu.getApps(function(apps){
            availableApps = apps || {};
            searchableApps = buildSearchIndex(availableApps);
            appsLoaded = true;
            updateSearch();
        });
    }

    function buildSearchIndex(apps){
        var index = [];
        Object.keys(apps || {}).forEach(function(appKey){
            var app = apps[appKey] || {};
            var title = app.title || appKey;
            var icon = app.icon ? "assets/" + appKey + "/" + app.icon : "";

            index.push({
                appKey: appKey,
                appData: app,
                title: title,
                subtitle: appKey,
                icon: icon,
                path: "#/app/" + appKey,
                searchText: normalizeSearch(title + " " + appKey)
            });

            var subapps = app.config && app.config.dock && Array.isArray(app.config.dock.subapps) ? app.config.dock.subapps : [];
            subapps.forEach(function(subapp){
                if(!subapp || !subapp.name || !subapp.path){
                    return;
                }
                index.push({
                    appKey: appKey,
                    appData: app,
                    title: subapp.name,
                    subtitle: title + " · " + appKey,
                    icon: icon,
                    path: subappPath(appKey,subapp.path),
                    searchText: normalizeSearch(subapp.name + " " + title + " " + appKey)
                });
            });
        });
        return index;
    }

    function subappPath(appKey,path){
        path = String(path || "");
        if(path.indexOf("#/") === 0){
            return path;
        }
        return "#/app/" + appKey + "/" + path.replace(/^\/+/,"");
    }

    function normalizeSearch(value){
        return String(value || "").toLowerCase().replace(/\s+/g," ").trim();
    }

    function userInitial(){
        var email = vueData.data.userData && vueData.data.userData.email;
        return email ? String(email).charAt(0).toUpperCase() : "U";
    }

    function updateSearch(){
        var query = normalizeSearch(vueData.data.searchQuery);
        vueData.data.activeSearchIndex = -1;

        if(!query){
            vueData.data.searchResults = [];
            vueData.data.searchMessage = "";
            vueData.data.searchOpen = false;
            return;
        }

        vueData.data.searchOpen = true;
        if(!appsLoaded){
            vueData.data.searchResults = [];
            vueData.data.searchMessage = "Loading apps...";
            return;
        }

        vueData.data.searchResults = searchableApps
            .filter(function(item){
                return item.searchText.indexOf(query) !== -1;
            })
            .sort(function(a,b){
                var aStarts = a.searchText.indexOf(query) === 0 ? 0 : 1;
                var bStarts = b.searchText.indexOf(query) === 0 ? 0 : 1;
                if(aStarts !== bStarts){
                    return aStarts - bStarts;
                }
                return a.title.localeCompare(b.title);
            })
            .slice(0,8);
        vueData.data.searchMessage = vueData.data.searchResults.length ? "" : "No matching apps found.";
    }

    function openSearch(){
        if(closeTimer){
            window.clearTimeout(closeTimer);
            closeTimer = null;
        }
        if(normalizeSearch(vueData.data.searchQuery)){
            updateSearch();
        }
    }

    function closeSearch(){
        closeTimer = window.setTimeout(function(){
            vueData.data.searchOpen = false;
            vueData.data.activeSearchIndex = -1;
        },150);
    }

    function dismissSearch(){
        vueData.data.searchOpen = false;
        vueData.data.activeSearchIndex = -1;
    }

    function moveSearchSelection(direction){
        var results = vueData.data.searchResults;
        if(!results.length){
            return;
        }
        vueData.data.searchOpen = true;
        var nextIndex = vueData.data.activeSearchIndex + direction;
        if(nextIndex < 0){
            nextIndex = results.length - 1;
        }else if(nextIndex >= results.length){
            nextIndex = 0;
        }
        vueData.data.activeSearchIndex = nextIndex;
    }

    function submitSearch(event){
        if(event){
            event.preventDefault();
        }
        var results = vueData.data.searchResults;
        if(!results.length){
            updateSearch();
            return;
        }
        var index = vueData.data.activeSearchIndex >= 0 ? vueData.data.activeSearchIndex : 0;
        launchSearchResult(results[index]);
    }

    function launchSearchResult(result){
        if(!result){
            return;
        }
        dismissSearch();
        vueData.data.searchQuery = "";

        var titleComponent = exports.getComponent("navigation-title");
        if(titleComponent && typeof titleComponent.setDisplayData === "function"){
            titleComponent.setDisplayData(result.appKey,result.appData);
        }

        var router = exports.getComponent("soss-routes");
        if(router && typeof router.appNavigate === "function"){
            router.appNavigate(result.path);
        }else{
            window.location.href = result.path;
        }
    }

    function signout(){
        var handler  = exports.getComponent("auth-handler");
        handler.services.logout().then(function(result){
            if(result.result){
                localStorage.clear();
                sessionStorage.clear();
                window.location = window.location.href.split('#')[0];
            }else{
                alert ("error");
            }
        }).error(function(){
            alert ("error");
        });
    }

    exports.onReady = function(element){
        vueData.el = '#' + $(element).attr('id');
        var handler  = exports.getComponent("auth-handler");
        handler.services.Session(getCookie("securityToken"))
            .then(function(result){
                if(result.result!=null){
                    vueData.data.userData=result.result;
                }else{
                    signout();
                }
            })
            .error(function(){
                signout();
            });

        new Vue(vueData);
        loadApps();
    };

    function getCookie(cname) {
        var name = cname + "=";
        var decodedCookie = decodeURIComponent(document.cookie);
        var ca = decodedCookie.split(';');
        for(var i = 0; i <ca.length; i++) {
            var c = ca[i];
            while (c.charAt(0) == ' ') {
                c = c.substring(1);
            }
            if (c.indexOf(name) == 0) {
                return c.substring(name.length, c.length);
            }
        }
        return "";
    }

    exports.setDisplayData = function(appKey,appData) {
        vueData.data.appData = appData;
        vueData.data.appKey = appKey;
    };
});
