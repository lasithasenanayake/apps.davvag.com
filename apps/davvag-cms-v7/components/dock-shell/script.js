WEBDOCK.component().register(function(exports){
    var api;
    var renderDiv;
    var bindData = {
        loading: true,
        error: "",
        mode: "page",
        site: {},
        page: {sections: []}
    };

    var vueData = {
        data: bindData,
        methods: {
            sectionClass: sectionClass
        },
        onReady: function(scope, element){
            renderDiv = element;
            api = exports.getComponent("cms-v7-api");
            setupGlobalApi();
            loadSite(function(){
                window.addEventListener("hashchange", navigate);
                navigate();
            });
        }
    };

    function mountVue(element){
        if(typeof Vue === "undefined"){
            bindData.loading = false;
            bindData.error = "Vue library is not loaded.";
            return;
        }
        var mountElement = element.find(".cms-v7-main").first();
        if(!mountElement.length){
            mountElement = element;
        }
        if(!mountElement.attr("id")){
            mountElement.attr("id", "cms_v7_main_" + new Date().getTime());
        }
        vueData.el = "#" + mountElement.attr("id");
        new Vue(vueData);
        if(vueData.onReady){
            vueData.onReady(vueData.data, element);
        }
    }

    function setupGlobalApi(){
        window.CMSV7 = window.CMSV7 || {};
        window.CMSV7.getSite = function(){
            return bindData.site;
        };
        window.CMSV7.applyTheme = applyTheme;
        window.CMSV7.reload = function(){
            loadSite(navigate);
        };
    }

    function loadSite(done){
        bindData.loading = true;
        api.services.Site().then(function(result){
            bindData.loading = false;
            if(result.success){
                bindData.site = result.result || {};
                document.title = bindData.site.name || "Davvag CMS v7";
                if(bindData.site.favicon){
                    setFavicon(bindData.site.favicon);
                }
                applyTheme(localStorage.getItem("cms-v7-theme") || bindData.site.theme);
                if(done){
                    done();
                }
            }else{
                bindData.error = "Unable to load CMS configuration.";
            }
        }).error(function(){
            bindData.loading = false;
            bindData.error = "Unable to load CMS configuration.";
        });
    }

    function setFavicon(url){
        var link = document.querySelector("link[rel='icon']");
        if(!link){
            link = document.createElement("link");
            link.rel = "icon";
            document.head.appendChild(link);
        }
        link.href = url;
    }

    function normalizeRoute(){
        var hash = window.location.hash || "";
        if(hash.indexOf("#") === 0){
            hash = hash.substring(1);
        }
        if(hash === ""){
            var startup = bindData.site.startup || {mode:"page", route:"/"};
            if(startup.mode === "app" && startup.appCode){
                return "/app/" + startup.appCode + (startup.appRoute || "");
            }
            return startup.route || "/";
        }
        return hash;
    }

    function routeToSlug(route){
        if(route === "/" || route === "/home"){
            return "home";
        }
        return route.replace(/^\/+/, "").replace(/\/+$/, "").replace(/[^A-Za-z0-9\-]+/g, "-").toLowerCase() || "home";
    }

    function navigate(){
        var route = normalizeRoute();
        if(route.indexOf("/app/") === 0){
            renderAppRoute(route);
            return;
        }
        bindData.mode = "page";
        bindData.error = "";
        bindData.loading = true;
        api.services.Page({slug: routeToSlug(route)}).then(function(result){
            bindData.loading = false;
            if(result.success && result.result){
                bindData.page = result.result;
                document.title = (bindData.page.title || bindData.site.name || "Davvag CMS v7");
                $("#cms-v7-app-renderer").empty();
                window.scrollTo(0, 0);
            }else{
                bindData.error = "Page not found.";
                bindData.page = {sections: []};
            }
        }).error(function(){
            bindData.loading = false;
            bindData.error = "Page not found.";
            bindData.page = {sections: []};
        });
    }

    function renderAppRoute(route){
        bindData.mode = "app";
        bindData.loading = false;
        bindData.error = "";
        bindData.page = {sections: []};
        var appRouteData = parseAppRoute(route);
        if(!appRouteData.appId){
            bindData.error = "App route is missing an app code.";
            return;
        }
        renderApp(appRouteData.appId, appRouteData.appRoute, appRouteData);
    }

    function parseAppRoute(route){
        var appPath = (route || "").substring(5);
        var queryString = "";
        var queryIndex = appPath.indexOf("?");
        if(queryIndex !== -1){
            queryString = appPath.substring(queryIndex + 1);
            appPath = appPath.substring(0, queryIndex);
        }
        var pieces = appPath.split("/");
        var appId = pieces.shift();
        var appRoute = normalizeAppRoute("/" + pieces.join("/"));
        return {
            appId: appId,
            appRoute: appRoute,
            queryString: queryString,
            queryParams: parseQuery(queryString),
            routeParams: {}
        };
    }

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

    function parseQuery(queryString){
        var params = {};
        if(!queryString){
            return params;
        }
        queryString.split("&").forEach(function(pair){
            if(!pair){
                return;
            }
            var parts = pair.split("=");
            var key = decodeURIComponent((parts.shift() || "").replace(/\+/g, " "));
            if(!key){
                return;
            }
            params[key] = decodeURIComponent((parts.join("=") || "").replace(/\+/g, " "));
        });
        return params;
    }

    function renderApp(appId, appRoute, routeContext){
        var host = $("#cms-v7-app-renderer");
        host.html('<div class="cms-v7-loading">Loading app...</div>');
        WEBDOCK.componentManager.downloadAppDescriptor(appId, function(descriptor){
            if(!descriptor){
                host.html('<div class="cms-v7-error">App not found.</div>');
                return;
            }
            var startupComponent = null;
            if(descriptor.configuration && descriptor.configuration.webdock){
                var webdock = descriptor.configuration.webdock;
                if(webdock.routes && webdock.routes.partials && webdock.routes.partials[appRoute]){
                    startupComponent = webdock.routes.partials[normalizeAppRoute(appRoute)];
                }
                if(!startupComponent){
                    startupComponent = webdock.startupComponent;
                }
            }
            if(!startupComponent){
                host.html('<div class="cms-v7-error">App startup component is not configured.</div>');
                return;
            }
            WEBDOCK.componentManager.downloadComponents(appId, descriptor, function(){
                WEBDOCK.componentManager.getOnDemand(appId, descriptor, startupComponent, function(results, desc, instance){
                    renderAppComponent(host, results, instance, routeContext);
                }, descriptor.description ? descriptor.description.version : undefined);
            }, descriptor.description ? descriptor.description.version : undefined);
        });
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
        if(instance.vue){
            if(!host.attr("id")){
                host.attr("id", "cms_v7_app_" + new Date().getTime());
            }
            instance.vue.el = "#" + host.attr("id");
            var app = new Vue(instance.vue);
            if(instance.vue.onReady){
                instance.vue.onReady(instance.vue.data, host, routeContext || {});
            }
        }else if(instance.onReady){
            instance.onReady(host, routeContext || {});
        }
    }

    function applyTheme(themeName){
        var themes = bindData.site.themes || [];
        var theme = themes.filter(function(item){ return item.name === themeName; })[0] || themes[0];
        if(!theme){
            return;
        }
        localStorage.setItem("cms-v7-theme", theme.name);
        var root = document.documentElement;
        root.style.setProperty("--cms-bg", theme.background || "#ffffff");
        root.style.setProperty("--cms-surface", theme.surface || "#f6f8fb");
        root.style.setProperty("--cms-text", theme.text || "#18202f");
        root.style.setProperty("--cms-muted", theme.muted || "#647083");
        root.style.setProperty("--cms-primary", theme.primary || "#0f766e");
        root.style.setProperty("--cms-secondary", theme.secondary || "#2563eb");
        root.style.setProperty("--cms-accent", theme.accent || "#e11d48");
        root.style.setProperty("--cms-font", theme.font || "Arial, Helvetica, sans-serif");
        window.dispatchEvent(new CustomEvent("cms-v7-theme-changed", {detail: theme}));
    }

    function sectionClass(section){
        if(section.type === "hero"){
            return "cms-v7-section cms-v7-hero";
        }
        return "cms-v7-section";
    }

    exports.vue = vueData;
    exports.onReady = mountVue;
});
