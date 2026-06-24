WEBDOCK.component().register(function(exports){
    var apploader;
    var bindData = {
        site: {
            name: "Davvag CMS v7",
            nav: {links: [], cta: {}}
        },
        initials: "D",
        navItems: [],
        cta: null,
        openMenuId: "",
        userData: null,
        userAccess: ["public", "guest", "anonymous"],
        usingLaunchers: false,
        mobileNavOpen: false,
        Notify: [],
        appTitle: "",
        notificationOpen: false,
        notificationLoading: false,
        notificationError: "",
        loadingAppError: false
    };
    var launcherRequestId = 0;
    var listenersBound = false;

    exports.vue = {
        data: bindData,
        methods: {
            toggleMenu: toggleMenu,
            closeMenu: closeMenu,
            toggleMobileNav: toggleMobileNav,
            closeMobileNav: closeMobileNav,
            closeNavigation: closeNavigation,
            isMenuOpen: isMenuOpen,
            notification: toggleNotification,
            closeNotification: closeNotification,
            downloadapp: downloadapp,
            closeNotifyApp: closeNotifyApp
        },
        onReady: function(){
            loadSiteData();
            loadSession(function(){
                applySiteNavigation();
                loadLauncherNavigation();
                loadNotifications();
            });
            initializeAppLoader();
            bindListeners();
        }
    };

    exports.onReady = function(element){
        mountVue(element);
    };

    function mountVue(element){
        if(typeof Vue === "undefined"){
            return;
        }
        if(!element.attr("id")){
            element.attr("id", "cms_v7_nav_" + new Date().getTime());
        }
        exports.vue.el = "#" + element.attr("id");
        new Vue(exports.vue);
        if(exports.vue.onReady){
            exports.vue.onReady(exports.vue.data, element);
        }
    }

    function bindListeners(){
        if(listenersBound){
            return;
        }
        listenersBound = true;
        window.addEventListener("cms-v7-site-changed", refreshNavigation);
        document.addEventListener("click", function(event){
            if(!closest(event.target, ".cms-v7-nav")){
                closeMenu();
                closeMobileNav();
            }
            if(!closest(event.target, ".cms-v7-notification-bar") && !closest(event.target, ".cms-v7-notification-toggle")){
                closeNotification();
            }
        });
    }

    function refreshNavigation(){
        loadSiteData();
        if(!bindData.usingLaunchers){
            applySiteNavigation();
        }
        loadLauncherNavigation();
    }

    function loadSiteData(){
        var site = window.CMSV7 && window.CMSV7.getSite ? window.CMSV7.getSite() : {};
        if(site && site.name){
            bindData.site = site;
            bindData.site.nav = bindData.site.nav || {links: [], cta: {}};
            bindData.initials = site.name.substring(0, 1).toUpperCase();
            applyCta();
        }
    }

    function loadSession(done){
        var handler = exports.getComponent("auth-handler");
        var token = getCookie("securityToken");
        if(!handler || !handler.services || !handler.services.Session || !token){
            setUserData(null);
            if(done){
                done();
            }
            return;
        }
        handler.services.Session(token)
            .then(function(result){
                setUserData(result && result.result ? result.result : null);
                if(done){
                    done();
                }
            })
            .error(function(){
                setUserData(null);
                if(done){
                    done();
                }
            });
    }

    function setUserData(userData){
        bindData.userData = userData || null;
        bindData.userAccess = getUserAccess(userData);
    }

    function initializeAppLoader(){
        if(!exports.getAppComponent){
            return;
        }
        exports.getAppComponent("davvag-tools", "davvag-app-downloader", function(_uploader){
            apploader = _uploader;
            if(apploader && apploader.initialize){
                apploader.initialize();
            }
        });
    }

    function loadNotifications(){
        var handler = exports.getComponent("auth-handler");
        if(!handler || !handler.services || !handler.services.Notification){
            bindData.Notify = [];
            return;
        }
        bindData.notificationLoading = true;
        bindData.notificationError = "";
        handler.services.Notification()
            .then(function(result){
                bindData.Notify = result && result.result ? result.result : [];
                bindData.notificationLoading = false;
            })
            .error(function(){
                bindData.Notify = [];
                bindData.notificationLoading = false;
                bindData.notificationError = "Unable to load notifications.";
            });
    }

    function toggleNotification(event){
        if(event && event.stopPropagation){
            event.stopPropagation();
        }
        bindData.notificationOpen = !bindData.notificationOpen;
        if(bindData.notificationOpen){
            closeMenu();
            closeMobileNav();
            loadNotifications();
        }
    }

    function closeNotification(){
        bindData.notificationOpen = false;
    }

    function downloadapp(appname, form, data, apptitle, notificationItem){
        var payload = parseNotificationData(data);
        payload.notfy = notificationItem;
        bindData.appTitle = apptitle || "Notification";
        bindData.loadingAppError = false;
        closeNotification();

        if(!apploader || !apploader.downloadAPP){
            bindData.notificationError = "Unable to open notification.";
            return;
        }
        showNotifyApp();
        apploader.downloadAPP(appname, form, "notifyappdock", function(){
        }, function(error){
            if(window.console && console.log){
                console.log(error);
            }
            bindData.loadingAppError = true;
        }, completeResponse, payload);
    }

    function completeResponse(data){
        if(data && data.notfy && data.notfy.id){
            clearNotification(data.notfy);
        }
        if(window.console && console.log){
            console.log(JSON.stringify(data));
        }
    }

    function clearNotification(notificationItem){
        var handler = exports.getComponent("auth-handler");
        if(!handler || !handler.services || !handler.services.ClearNotiifcatiion){
            return;
        }
        handler.services.ClearNotiifcatiion({id: notificationItem.id.toString()})
            .then(function(result){
                if(result.success){
                    removeNotification(notificationItem.id);
                    if(notificationItem.closeapp){
                        closeNotifyApp();
                    }
                }else{
                    alert("Error");
                }
            })
            .error(function(){
            });
    }

    function removeNotification(id){
        var filtered = [];
        var targetId = id.toString();
        for(var i = 0; i < bindData.Notify.length; i++){
            var currentId = bindData.Notify[i].id;
            if(currentId === undefined || currentId === null || currentId.toString() !== targetId){
                filtered.push(bindData.Notify[i]);
            }
        }
        bindData.Notify = filtered;
    }

    function parseNotificationData(data){
        if(!data){
            return {};
        }
        if(typeof data === "object"){
            var copy = {};
            for(var key in data){
                if(Object.prototype.hasOwnProperty.call(data, key)){
                    copy[key] = data[key];
                }
            }
            return copy;
        }
        try{
            return JSON.parse(data);
        }catch(error){
            return {};
        }
    }

    function showNotifyApp(){
        if(typeof $ !== "undefined" && $.fn && $.fn.modal){
            $("#notifyappwindow").modal("show");
        }
    }

    function closeNotifyApp(){
        if(typeof $ !== "undefined" && $.fn && $.fn.modal){
            $("#notifyappwindow").modal("hide");
        }
    }

    function loadLauncherNavigation(){
        var nav = bindData.site.nav || {};
        if(nav.source === "site" || nav.source === "static"){
            bindData.usingLaunchers = false;
            applySiteNavigation();
            return;
        }

        var handler = exports.getComponent("auth-handler");
        if(!handler || !handler.services || !handler.services.Launchers){
            bindData.usingLaunchers = false;
            applySiteNavigation();
            return;
        }

        var requestId = ++launcherRequestId;
        handler.services.Launchers({
            appcode: nav.launcherAppCode || "davvag-cms-v7",
            component: nav.launcherComponent || "nav-bar"
        }).then(function(result){
            if(requestId !== launcherRequestId){
                return;
            }
            var launchers = result && result.success && result.result ? result.result : [];
            if(launchers.length){
                bindData.navItems = filterAllowedItems(normalizeLauncherRoots(launchers));
                bindData.usingLaunchers = true;
                closeMobileNav();
                closeMenu();
                return;
            }
            bindData.usingLaunchers = false;
            if(isLauncherOnly()){
                bindData.navItems = [];
            }else{
                applySiteNavigation();
            }
        }).error(function(){
            if(requestId !== launcherRequestId){
                return;
            }
            bindData.usingLaunchers = false;
            if(isLauncherOnly()){
                bindData.navItems = [];
            }else{
                applySiteNavigation();
            }
        });
    }

    function applySiteNavigation(){
        var nav = bindData.site.nav || {};
        bindData.navItems = filterAllowedItems(normalizeSiteLinks(nav.links || []));
        applyCta();
        closeMobileNav();
        closeMenu();
    }

    function applyCta(){
        var nav = bindData.site.nav || {};
        var cta = nav.cta && nav.cta.label ? normalizeSiteLink(nav.cta, "cta") : null;
        bindData.cta = cta && itemAllowed(cta) ? cta : null;
    }

    function isLauncherOnly(){
        var nav = bindData.site.nav || {};
        return nav.source === "launchers" || nav.source === "launcher" || (nav.dynamic === true && nav.fallback === false);
    }

    function normalizeSiteLinks(links){
        var items = [];
        for(var i = 0; i < links.length; i++){
            items.push(normalizeSiteLink(links[i], i));
        }
        return items;
    }

    function normalizeSiteLink(link, index){
        link = link || {};
        var label = link.label || link.name || link.Caption || link.caption || "";
        var children = normalizeSiteLinks(link.children || link.links || link.items || []);
        return {
            id: link.id || ("site-" + index + "-" + slug(label)),
            label: label,
            url: normalizeUrl(link.url || link.href || "#/"),
            target: link.target || "",
            children: children,
            raw: link
        };
    }

    function normalizeLauncherRoots(launchers){
        var roots = normalizeLauncherLinks(sortByOrder(launchers));
        var items = [];
        for(var i = 0; i < roots.length; i++){
            if(roots[i].children.length){
                items = items.concat(roots[i].children);
            }else{
                items.push(roots[i]);
            }
        }
        return items;
    }

    function normalizeLauncherLinks(launchers){
        var items = [];
        launchers = sortByOrder(launchers || []);
        for(var i = 0; i < launchers.length; i++){
            items.push(normalizeLauncherLink(launchers[i], i));
        }
        return items;
    }

    function normalizeLauncherLink(item, index){
        item = item || {};
        var children = normalizeLauncherLinks(item.Launchers || item.children || []);
        var label = item.name || item.shortname || item.Caption || item.caption || "";
        return {
            id: "launcher-" + (item.bid || index) + "-" + slug(label),
            label: label,
            url: launcherUrl(item),
            target: launcherTarget(item),
            children: children,
            raw: item
        };
    }

    function filterAllowedItems(items){
        var filtered = [];
        for(var i = 0; i < items.length; i++){
            var item = items[i];
            if(!itemAllowed(item)){
                continue;
            }
            item.children = filterAllowedItems(item.children || []);
            if(item.label && (item.url || item.children.length)){
                filtered.push(item);
            }
        }
        return filtered;
    }

    function itemAllowed(item){
        var raw = item.raw || item;
        if(raw.hidden === true || raw.hidden === "true" || raw.visible === false || raw.visible === "false"){
            return false;
        }
        if((raw.auth === true || raw.auth === "true") && !isAuthenticated()){
            return false;
        }
        if((raw.auth === false || raw.auth === "false") && isAuthenticated()){
            return false;
        }
        var allowed = [];
        collectValues(raw.role, allowed);
        collectValues(raw.roles, allowed);
        collectValues(raw.group, allowed);
        collectValues(raw.groups, allowed);
        collectValues(raw.groupid, allowed);
        collectValues(raw.groupId, allowed);
        collectValues(raw.groupIds, allowed);
        collectValues(raw.allowedRoles, allowed);
        collectValues(raw.visibleFor, allowed);
        collectValues(raw.permissions, allowed);
        if(raw.access){
            collectValues(raw.access.role, allowed);
            collectValues(raw.access.roles, allowed);
            collectValues(raw.access.group, allowed);
            collectValues(raw.access.groups, allowed);
            collectValues(raw.access.groupid, allowed);
            collectValues(raw.access.groupId, allowed);
            collectValues(raw.access.groupIds, allowed);
        }
        if(!allowed.length){
            return true;
        }
        for(var i = 0; i < allowed.length; i++){
            var key = normalizeAccessKey(allowed[i]);
            if(key === "*" || key === "all"){
                return true;
            }
            if(bindData.userAccess.indexOf(key) !== -1){
                return true;
            }
        }
        return false;
    }

    function getUserAccess(userData){
        var values = ["public"];
        if(userData){
            values.push("authenticated");
            values.push("user");
            collectValues(userData.userid, values);
            collectValues(userData.userId, values);
            collectValues(userData.groupid, values);
            collectValues(userData.groupId, values);
            collectValues(userData.usergroup, values);
            collectValues(userData.userGroup, values);
            collectValues(userData.role, values);
            collectValues(userData.roles, values);
            collectValues(userData.groups, values);
            collectValues(userData.UserGroups, values);
            if(userData.profile){
                collectValues(userData.profile.groupid, values);
                collectValues(userData.profile.groupId, values);
                collectValues(userData.profile.role, values);
                collectValues(userData.profile.roles, values);
                collectValues(userData.profile.groups, values);
            }
        }else{
            values.push("guest");
            values.push("anonymous");
        }
        var keys = [];
        for(var i = 0; i < values.length; i++){
            var key = normalizeAccessKey(values[i]);
            if(key && keys.indexOf(key) === -1){
                keys.push(key);
            }
        }
        return keys;
    }

    function collectValues(value, values){
        if(value === undefined || value === null || value === ""){
            return;
        }
        if(Array.isArray(value)){
            for(var i = 0; i < value.length; i++){
                collectValues(value[i], values);
            }
            return;
        }
        if(typeof value === "object"){
            if(value.selected && value.selected !== "Y" && value.selected !== true){
                return;
            }
            collectValues(value.groupid, values);
            collectValues(value.groupId, values);
            collectValues(value.role, values);
            collectValues(value.name, values);
            collectValues(value.id, values);
            collectValues(value.code, values);
            collectValues(value.value, values);
            return;
        }
        value.toString().split(/[,|;]/).forEach(function(part){
            if(part.trim()){
                values.push(part.trim());
            }
        });
    }

    function normalizeAccessKey(value){
        return value === undefined || value === null ? "" : value.toString().trim().toLowerCase();
    }

    function isAuthenticated(){
        return bindData.userAccess.indexOf("authenticated") !== -1;
    }

    function launcherUrl(item){
        var url = item.url || item.href || "";
        if(!url && item.appcode){
            url = "#/app/" + item.appcode;
        }
        return normalizeUrl(url, item);
    }

    function launcherTarget(item){
        if(item.target){
            return item.target;
        }
        return item.window_type && item.window_type.indexOf("new-window") !== -1 ? "_blank" : "";
    }

    function normalizeUrl(url, item){
        url = url ? url.toString().trim() : "#/";
        if(!url){
            return "#/";
        }
        if(url.indexOf("#/") === 0){
            return normalizeHashRoute(url, item);
        }
        if(url.indexOf("/#/") !== -1){
            return normalizeHashRoute(url.substring(url.indexOf("/#/") + 1), item);
        }
        if(url.indexOf("#") === 0){
            return url;
        }
        return url;
    }

    function normalizeHashRoute(hash, item){
        if(!hash || hash.indexOf("#/") !== 0){
            return hash;
        }
        var route = hash.substring(2);
        if(route.indexOf("app/") === 0){
            return hash;
        }
        if(item && item.appcode && (route === item.appcode || route.indexOf(item.appcode + "/") === 0)){
            return "#/app/" + route;
        }
        return hash;
    }

    function sortByOrder(items){
        return (items || []).slice().sort(function(a, b){
            var left = parseInt(a && a.order_no !== undefined ? a.order_no : 0, 10) || 0;
            var right = parseInt(b && b.order_no !== undefined ? b.order_no : 0, 10) || 0;
            return left - right;
        });
    }

    function slug(value){
        return (value || "").toString().toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
    }

    function toggleMenu(item, event){
        if(event && event.stopPropagation){
            event.stopPropagation();
        }
        bindData.openMenuId = bindData.openMenuId === item.id ? "" : item.id;
    }

    function closeMenu(){
        bindData.openMenuId = "";
    }

    function toggleMobileNav(event){
        if(event && event.stopPropagation){
            event.stopPropagation();
        }
        bindData.mobileNavOpen = !bindData.mobileNavOpen;
        if(!bindData.mobileNavOpen){
            closeMenu();
        }
    }

    function closeMobileNav(){
        bindData.mobileNavOpen = false;
    }

    function closeNavigation(){
        closeMenu();
        closeMobileNav();
    }

    function isMenuOpen(item){
        return bindData.openMenuId === item.id;
    }

    function closest(target, selector){
        return target && target.closest ? target.closest(selector) : null;
    }

    function getCookie(cname) {
        var name = cname + "=";
        var decodedCookie = decodeURIComponent(document.cookie);
        var ca = decodedCookie.split(";");
        for(var i = 0; i < ca.length; i++) {
            var c = ca[i];
            while(c.charAt(0) === " ") {
                c = c.substring(1);
            }
            if(c.indexOf(name) === 0) {
                return c.substring(name.length, c.length);
            }
        }
        return "";
    }
});
