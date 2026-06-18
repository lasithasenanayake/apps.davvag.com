WEBDOCK.component().register(function(exports){
    var api;
    var bindData = {
        tab: "site",
        loading: false,
        error: "",
        message: "",
        site: emptySite(),
        pages: [],
        assets: [],
        selectedPage: emptyPage(),
        selectedTheme: null,
        selectedThemeIndex: 0,
        sectionAnimations: [
            {value: "none", label: "None"},
            {value: "fade", label: "Fade"},
            {value: "fade-up", label: "Fade up"},
            {value: "slide-left", label: "Slide left"},
            {value: "slide-right", label: "Slide right"},
            {value: "zoom", label: "Zoom"},
            {value: "pop", label: "Pop"}
        ],
        heroModes: [
            {value: "auto-fade", label: "Auto fade"},
            {value: "auto-slide", label: "Auto slide"},
            {value: "auto-zoom", label: "Auto zoom"},
            {value: "manual", label: "Manual"},
            {value: "stack", label: "Stacked"}
        ],
        themeFields: [
            {key: "background", label: "Background", type: "color"},
            {key: "surface", label: "Surface", type: "color"},
            {key: "text", label: "Text", type: "color"},
            {key: "muted", label: "Muted text", type: "color"},
            {key: "primary", label: "Primary", type: "color"},
            {key: "secondary", label: "Secondary", type: "color"},
            {key: "accent", label: "Accent", type: "color"}
        ]
    };

    exports.vue = {
        data: bindData,
        methods: {
            reload: reload,
            saveSite: saveSite,
            addNavLink: addNavLink,
            addFooterLink: addFooterLink,
            addTheme: addTheme,
            removeTheme: removeTheme,
            selectTheme: selectTheme,
            themeDot: themeDot,
            newPage: newPage,
            loadPage: loadPage,
            savePage: savePage,
            normalizePagePath: normalizePagePath,
            sectionTypeChanged: sectionTypeChanged,
            addSection: addSection,
            duplicateSection: duplicateSection,
            moveItem: moveItem,
            removeItem: removeItem,
            uploadAsset: uploadAsset,
            isImage: isImage,
            fileExt: fileExt
        },
        watch: {
            selectedTheme: {
                deep: true,
                handler: previewTheme
            }
        },
        onReady: function(){
            waitForApi(0);
        }
    };

    exports.cmsV7OwnMount = true;
    exports.onReady = function(element){
        mountVue(element);
    };

    function mountVue(element){
        if(typeof Vue === "undefined"){
            bindData.error = "Vue library is not loaded.";
            return;
        }
        if(!element.attr("id")){
            element.attr("id", "cms_v7_settings_" + new Date().getTime());
        }
        exports.vue.el = "#" + element.attr("id");
        new Vue(exports.vue);
        if(exports.vue.onReady){
            exports.vue.onReady(exports.vue.data, element);
        }
    }

    function waitForApi(attempt){
        if(api && api.services){
            reload();
            return;
        }
        if(exports.getAppComponent){
            exports.getAppComponent(exports.getAppId(), "settings-api", function(component){
                api = component;
                if(api && api.services){
                    reload();
                    return;
                }
                retryWaitForApi(attempt);
            });
            return;
        }
        retryWaitForApi(attempt);
    }

    function retryWaitForApi(attempt){
        if(attempt >= 25){
            setError("Settings service is still loading. Please refresh the page.");
            return;
        }
        window.setTimeout(function(){
            waitForApi(attempt + 1);
        }, 120);
    }

    function emptySite(){
        return {
            name: "Davvag CMS v7",
            tagline: "",
            logo: "",
            favicon: "assets/davvag-cms-v7/favicon.svg",
            theme: "clean",
            startup: {mode: "page", route: "/"},
            nav: {variant: "clean", links: [], cta: {label: "", url: ""}},
            footer: {variant: "simple", copyright: "", links: []},
            themes: [defaultTheme("clean", "Clean")]
        };
    }

    function emptyPage(){
        return {
            slug: "home",
            path: "/",
            title: "Home",
            status: "published",
            sections: []
        };
    }

    function defaultTheme(name, label){
        return {
            name: name,
            label: label,
            background: "#ffffff",
            surface: "#f6f8fb",
            text: "#18202f",
            muted: "#647083",
            primary: "#0f766e",
            secondary: "#2563eb",
            accent: "#e11d48",
            font: "Arial, Helvetica, sans-serif"
        };
    }

    function reload(){
        clearStatus();
        loadSite();
        loadPages();
        loadAssets();
    }

    function loadSite(){
        if(!api || !api.services){
            waitForApi(0);
            return;
        }
        bindData.loading = true;
        api.services.Site().then(function(response){
            bindData.loading = false;
            if(response.success){
                bindData.site = normalizeSite(response.result || {});
                var index = themeIndex(bindData.site.theme);
                selectTheme(index >= 0 ? index : 0);
            }else{
                setError("Unable to load CMS settings.");
            }
        }).error(function(){
            bindData.loading = false;
            setError("Unable to load CMS settings.");
        });
    }

    function loadPages(){
        if(!api || !api.services){
            waitForApi(0);
            return;
        }
        api.services.Pages().then(function(response){
            if(response.success){
                bindData.pages = response.result || [];
                if(bindData.pages.length && (!bindData.selectedPage || !bindData.selectedPage.slug)){
                    loadPage(bindData.pages[0].slug);
                }
                if(bindData.pages.length && bindData.selectedPage.slug === "home" && bindData.selectedPage.sections.length === 0){
                    loadPage(bindData.pages[0].slug);
                }
            }
        }).error(function(){
            setError("Unable to load pages.");
        });
    }

    function loadAssets(){
        if(!api || !api.services){
            waitForApi(0);
            return;
        }
        api.services.Assets().then(function(response){
            if(response.success){
                bindData.assets = response.result || [];
            }
        }).error(function(){
            setError("Unable to load assets.");
        });
    }

    function normalizeSite(site){
        var base = emptySite();
        var normalized = merge(base, site || {});
        normalized.startup = normalized.startup || {mode: "page", route: "/"};
        normalized.nav = normalized.nav || {};
        normalized.nav.links = arrayOrEmpty(normalized.nav.links);
        normalized.nav.cta = normalized.nav.cta || {label: "", url: ""};
        normalized.footer = normalized.footer || {};
        normalized.footer.links = arrayOrEmpty(normalized.footer.links);
        normalized.themes = arrayOrEmpty(normalized.themes);
        if(!normalized.themes.length){
            normalized.themes.push(defaultTheme("clean", "Clean"));
        }
        if(!normalized.theme){
            normalized.theme = normalized.themes[0].name;
        }
        return normalized;
    }

    function merge(base, source){
        var output = JSON.parse(JSON.stringify(base));
        for(var key in source){
            if(Object.prototype.hasOwnProperty.call(source, key)){
                output[key] = source[key];
            }
        }
        return output;
    }

    function arrayOrEmpty(value){
        return Object.prototype.toString.call(value) === "[object Array]" ? value : [];
    }

    function saveSite(){
        clearStatus();
        var payload = cleanSiteForSave();
        api.services.SaveSite(payload).then(function(response){
            if(response.success){
                bindData.site = normalizeSite(response.result || payload);
                selectTheme(themeIndex(bindData.site.theme));
                setMessage("Settings saved.");
                notifyPublicDock();
            }else{
                setError("Unable to save settings.");
            }
        }).error(function(error){
            setError(readServiceError(error, "Unable to save settings."));
        });
    }

    function cleanSiteForSave(){
        var payload = clone(bindData.site);
        delete payload.pages;
        payload.nav = payload.nav || {};
        payload.nav.links = cleanLinks(payload.nav.links);
        payload.nav.cta = payload.nav.cta || {label: "", url: ""};
        payload.footer = payload.footer || {};
        payload.footer.links = cleanLinks(payload.footer.links);
        payload.themes = cleanThemes(payload.themes);
        if(!payload.theme && payload.themes.length){
            payload.theme = payload.themes[0].name;
        }
        payload.startup = payload.startup || {mode: "page", route: "/"};
        if(payload.startup.mode !== "app"){
            payload.startup.mode = "page";
            payload.startup.route = payload.startup.route || "/";
        }else{
            payload.startup.appRoute = payload.startup.appRoute || "/";
        }
        return payload;
    }

    function cleanLinks(links){
        return arrayOrEmpty(links).filter(function(link){
            return link && (link.label || link.url);
        }).map(function(link){
            var item = clone(link);
            item.label = item.label || "";
            item.url = item.url || "";
            return item;
        });
    }

    function cleanThemes(themes){
        var cleaned = arrayOrEmpty(themes).map(function(theme, index){
            var item = defaultTheme(theme.name || ("theme-" + (index + 1)), theme.label || theme.name || ("Theme " + (index + 1)));
            for(var key in theme){
                if(Object.prototype.hasOwnProperty.call(theme, key)){
                    item[key] = theme[key];
                }
            }
            item.name = cleanSlug(item.name, "theme-" + (index + 1));
            return item;
        });
        return cleaned.length ? cleaned : [defaultTheme("clean", "Clean")];
    }

    function addNavLink(){
        bindData.site.nav.links.push({label: "New link", url: "#/"});
    }

    function addFooterLink(){
        bindData.site.footer.links.push({label: "New link", url: "#/"});
    }

    function addTheme(){
        var count = bindData.site.themes.length + 1;
        bindData.site.themes.push(defaultTheme("theme-" + count, "Theme " + count));
        selectTheme(bindData.site.themes.length - 1);
    }

    function removeTheme(){
        if(bindData.site.themes.length <= 1){
            setError("At least one theme is required.");
            return;
        }
        bindData.site.themes.splice(bindData.selectedThemeIndex, 1);
        selectTheme(Math.max(0, bindData.selectedThemeIndex - 1));
    }

    function selectTheme(index){
        if(index < 0 || index >= bindData.site.themes.length){
            index = 0;
        }
        bindData.selectedThemeIndex = index;
        bindData.selectedTheme = bindData.site.themes[index] || null;
        if(bindData.selectedTheme && bindData.selectedTheme.name){
            bindData.site.theme = bindData.selectedTheme.name;
        }
        previewTheme(bindData.selectedTheme);
    }

    function themeIndex(name){
        for(var i = 0; i < bindData.site.themes.length; i++){
            if(bindData.site.themes[i].name === name){
                return i;
            }
        }
        return -1;
    }

    function themeDot(theme){
        return {
            background: "linear-gradient(135deg, " + (theme.primary || "#0f766e") + ", " + (theme.accent || "#e11d48") + ")"
        };
    }

    function previewTheme(theme){
        if(!theme){
            return;
        }
        if(theme.name){
            bindData.site.theme = theme.name;
            localStorage.setItem("cms-v7-theme", theme.name);
        }
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

    function newPage(){
        bindData.selectedPage = decoratePage({
            slug: "new-page",
            path: "/new-page",
            title: "New Page",
            status: "draft",
            sections: [defaultSection("hero")]
        });
        bindData.tab = "pages";
    }

    function loadPage(slug){
        clearStatus();
        api.services.Page({slug: slug}).then(function(response){
            if(response.success && response.result){
                bindData.selectedPage = decoratePage(response.result);
            }else{
                setError("Unable to load page.");
            }
        }).error(function(error){
            setError(readServiceError(error, "Unable to load page."));
        });
    }

    function savePage(){
        clearStatus();
        var page = cleanPageForSave(bindData.selectedPage);
        if(page === null){
            return;
        }
        if(!page.slug || !page.title){
            setError("Page slug and title are required.");
            return;
        }
        api.services.SavePage(page).then(function(response){
            if(response.success){
                bindData.selectedPage = decoratePage(response.result || page);
                setMessage("Page saved and route updated.");
                loadPages();
                notifyPublicDock();
            }else{
                setError("Unable to save page.");
            }
        }).error(function(error){
            setError(readServiceError(error, "Unable to save page."));
        });
    }

    function decoratePage(page){
        var decorated = clone(page || emptyPage());
        decorated.sections = arrayOrEmpty(decorated.sections);
        for(var i = 0; i < decorated.sections.length; i++){
            var section = decorated.sections[i];
            applySectionDefaults(section);
            if(section.type === "features"){
                section.items = arrayOrEmpty(section.items);
                section.itemsText = JSON.stringify(section.items, null, 4);
            }
        }
        return decorated;
    }

    function cleanPageForSave(page){
        var payload = clone(page || emptyPage());
        var invalid = false;
        payload.slug = cleanSlug(payload.slug, "page");
        payload.path = payload.path || "/" + payload.slug;
        if(payload.path.charAt(0) !== "/"){
            payload.path = "/" + payload.path;
        }
        payload.sections = arrayOrEmpty(payload.sections).map(function(section){
            var item = clone(section);
            if(item.type === "features"){
                item.items = parseItems(item.itemsText, item.items);
                if(item.items === null){
                    invalid = true;
                }
            }
            delete item.itemsText;
            return item;
        });
        return invalid ? null : payload;
    }

    function parseItems(text, fallback){
        if(!text){
            return arrayOrEmpty(fallback);
        }
        try {
            var parsed = JSON.parse(text);
            return arrayOrEmpty(parsed);
        } catch (error) {
            setError("Feature items JSON is invalid.");
            return null;
        }
    }

    function normalizePagePath(){
        var slug = cleanSlug(bindData.selectedPage.slug, "page");
        bindData.selectedPage.slug = slug;
        if(slug === "home"){
            bindData.selectedPage.path = "/";
        }else{
            bindData.selectedPage.path = "/" + slug;
        }
    }

    function addSection(type){
        bindData.selectedPage.sections.push(defaultSection(type));
    }

    function sectionTypeChanged(section){
        applySectionDefaults(section);
        if(section.type === "features" && !section.itemsText){
            section.items = arrayOrEmpty(section.items);
            if(!section.items.length){
                section.items = defaultSection("features").items;
            }
            section.itemsText = JSON.stringify(section.items, null, 4);
        }
        if(section.type === "html" && !section.html){
            section.html = "<div><h2>Custom HTML</h2><p>Edit this block.</p></div>";
        }
    }

    function duplicateSection(index){
        bindData.selectedPage.sections.splice(index + 1, 0, clone(bindData.selectedPage.sections[index]));
    }

    function defaultSection(type){
        var section = {
            type: type,
            animation: "fade-up",
            eyebrow: "",
            title: type.charAt(0).toUpperCase() + type.substring(1),
            body: "",
            image: "",
            primaryLabel: "",
            primaryUrl: "",
            secondaryLabel: "",
            secondaryUrl: ""
        };
        applySectionDefaults(section);
        if(type === "features"){
            section.items = [
                {title: "First feature", body: "Describe a benefit."},
                {title: "Second feature", body: "Describe another benefit."}
            ];
            section.itemsText = JSON.stringify(section.items, null, 4);
        }
        if(type === "html"){
            section.html = "<div><h2>Custom HTML</h2><p>Edit this block.</p></div>";
        }
        return section;
    }

    function applySectionDefaults(section){
        section.animation = section.animation || "fade-up";
        if(section.type === "hero"){
            section.heroMode = section.heroMode || "auto-fade";
            section.rotationSeconds = section.rotationSeconds || 6;
        }
    }

    function moveItem(items, index, direction){
        var next = index + direction;
        if(!items || next < 0 || next >= items.length){
            return;
        }
        var item = items.splice(index, 1)[0];
        items.splice(next, 0, item);
    }

    function removeItem(items, index){
        if(items){
            items.splice(index, 1);
        }
    }

    function uploadAsset(event){
        clearStatus();
        var file = event.target.files && event.target.files[0];
        if(!file){
            return;
        }
        var reader = new FileReader();
        reader.onload = function(){
            api.services.UploadAsset({filename: file.name, dataUrl: reader.result}).then(function(response){
                if(response.success){
                    setMessage("Asset uploaded.");
                    loadAssets();
                }else{
                    setError("Unable to upload asset.");
                }
                event.target.value = "";
            }).error(function(error){
                setError(readServiceError(error, "Unable to upload asset."));
                event.target.value = "";
            });
        };
        reader.onerror = function(){
            setError("Unable to read selected file.");
            event.target.value = "";
        };
        reader.readAsDataURL(file);
    }

    function isImage(name){
        return /\.(png|jpg|jpeg|gif|webp|svg)$/i.test(name || "");
    }

    function fileExt(name){
        var parts = (name || "").split(".");
        return parts.length > 1 ? parts.pop() : "file";
    }

    function cleanSlug(value, fallback){
        value = (value || "").toString().toLowerCase().replace(/[^a-z0-9\-]+/g, "-").replace(/^\-+|\-+$/g, "");
        return value || fallback;
    }

    function clone(value){
        return JSON.parse(JSON.stringify(value));
    }

    function clearStatus(){
        bindData.error = "";
        bindData.message = "";
    }

    function setError(value){
        bindData.error = value;
        bindData.message = "";
    }

    function setMessage(value){
        bindData.message = value;
        bindData.error = "";
    }

    function readServiceError(error, fallback){
        if(error && error.responseJSON && error.responseJSON.result){
            return error.responseJSON.result;
        }
        return fallback;
    }

    function notifyPublicDock(){
        if(window.CMSV7 && window.CMSV7.reload){
            window.CMSV7.reload();
        }
    }
});
