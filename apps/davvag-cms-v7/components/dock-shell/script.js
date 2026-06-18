WEBDOCK.component().register(function(exports){
    var api;
    var renderDiv;
    var bindData = {
        loading: true,
        error: "",
        mode: "page",
        site: {},
        page: {sections: []},
        renderBlocks: []
    };
    var heroTimers = [];

    var vueData = {
        data: bindData,
        methods: {
            sectionClass: sectionClass,
            heroCarouselClass: heroCarouselClass,
            heroSlideClass: heroSlideClass,
            isHeroSlideActive: isHeroSlideActive,
            selectHeroSlide: selectHeroSlide,
            nextHeroSlide: nextHeroSlide,
            previousHeroSlide: previousHeroSlide
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
                notifySiteChanged();
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

    function notifySiteChanged(){
        window.dispatchEvent(new CustomEvent("cms-v7-site-changed", {detail: bindData.site}));
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
                preparePage();
                $("#cms-v7-app-renderer").empty();
                window.scrollTo(0, 0);
            }else{
                bindData.error = "Page not found.";
                bindData.page = {sections: []};
                preparePage();
            }
        }).error(function(){
            bindData.loading = false;
            bindData.error = "Page not found.";
            bindData.page = {sections: []};
            preparePage();
        });
    }

    function renderAppRoute(route){
        bindData.mode = "app";
        bindData.loading = false;
        bindData.error = "";
        bindData.page = {sections: []};
        preparePage();
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
        var classes = "cms-v7-section cms-v7-animate-" + animationName(section);
        if(section.type === "hero"){
            classes += " cms-v7-hero";
        }
        return classes;
    }

    function preparePage(){
        clearHeroTimers();
        bindData.renderBlocks = buildRenderBlocks(bindData.page.sections || []);
        startHeroTimers();
    }

    function buildRenderBlocks(sections){
        var blocks = [];
        var index = 0;
        while(index < sections.length){
            var section = normalizeSection(sections[index]);
            if(section.type === "hero"){
                var slides = [];
                while(index < sections.length && sections[index].type === "hero"){
                    slides.push(normalizeSection(sections[index]));
                    index++;
                }
                if(slides.length > 1 && heroMode(slides[0]) !== "stack"){
                    blocks.push({
                        type: "heroCarousel",
                        id: "hero-carousel-" + blocks.length,
                        slides: slides,
                        activeIndex: 0,
                        mode: heroMode(slides[0]),
                        animation: animationName(slides[0]),
                        intervalMs: rotationMs(slides[0])
                    });
                }else{
                    for(var s = 0; s < slides.length; s++){
                        blocks.push({type: "section", id: "section-" + blocks.length, section: slides[s]});
                    }
                }
                continue;
            }
            blocks.push({type: "section", id: "section-" + blocks.length, section: section});
            index++;
        }
        return blocks;
    }

    function normalizeSection(section){
        section = section || {};
        section.animation = section.animation || "fade-up";
        if(section.type === "hero"){
            section.heroMode = section.heroMode || "auto-fade";
            section.rotationSeconds = section.rotationSeconds || 6;
        }
        return section;
    }

    function startHeroTimers(){
        for(var i = 0; i < bindData.renderBlocks.length; i++){
            startHeroTimer(bindData.renderBlocks[i]);
        }
    }

    function startHeroTimer(block){
        if(!block || block.type !== "heroCarousel" || block.mode === "manual"){
            return;
        }
        var timer = window.setInterval(function(){
            nextHeroSlide(block);
        }, block.intervalMs);
        heroTimers.push(timer);
    }

    function clearHeroTimers(){
        for(var i = 0; i < heroTimers.length; i++){
            window.clearInterval(heroTimers[i]);
        }
        heroTimers = [];
    }

    function heroCarouselClass(block){
        return "cms-v7-section cms-v7-hero cms-v7-hero-carousel cms-v7-hero-carousel-" + carouselMode(block) + " cms-v7-animate-" + (block.animation || "fade-up");
    }

    function heroSlideClass(block, index){
        return "cms-v7-hero-slide" + (isHeroSlideActive(block, index) ? " active" : "");
    }

    function isHeroSlideActive(block, index){
        return block.activeIndex === index;
    }

    function selectHeroSlide(block, index){
        if(!block || !block.slides || !block.slides.length){
            return;
        }
        if(index < 0){
            index = block.slides.length - 1;
        }
        if(index >= block.slides.length){
            index = 0;
        }
        block.activeIndex = index;
    }

    function nextHeroSlide(block){
        selectHeroSlide(block, block.activeIndex + 1);
    }

    function previousHeroSlide(block){
        selectHeroSlide(block, block.activeIndex - 1);
    }

    function heroMode(section){
        var mode = (section.heroMode || "auto-fade").toString();
        if(mode === "fade" || mode === "auto"){
            return "auto-fade";
        }
        if(mode === "slide"){
            return "auto-slide";
        }
        if(mode === "zoom"){
            return "auto-zoom";
        }
        return mode;
    }

    function carouselMode(block){
        var mode = block && block.mode ? block.mode : "auto-fade";
        return mode.replace(/^auto-/, "");
    }

    function animationName(section){
        return (section && section.animation ? section.animation : "fade-up").toString().replace(/[^a-z0-9\-]/g, "") || "fade-up";
    }

    function rotationMs(section){
        var seconds = parseFloat(section.rotationSeconds);
        if(isNaN(seconds) || seconds < 2){
            seconds = 6;
        }
        if(seconds > 30){
            seconds = 30;
        }
        return seconds * 1000;
    }

    exports.vue = vueData;
    exports.onReady = mountVue;
});
