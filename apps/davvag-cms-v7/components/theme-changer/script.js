WEBDOCK.component().register(function(exports){
    var bindData = {
        open: false,
        selected: "",
        themes: []
    };

    exports.vue = {
        data: bindData,
        methods: {
            selectTheme: selectTheme,
            swatchStyle: swatchStyle
        },
        onReady: function(){
            load();
            window.addEventListener("cms-v7-site-changed", load);
            window.addEventListener("cms-v7-theme-changed", function(event){
                load();
                if(event.detail && event.detail.name){
                    bindData.selected = event.detail.name;
                }
            });
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
            element.attr("id", "cms_v7_theme_" + new Date().getTime());
        }
        exports.vue.el = "#" + element.attr("id");
        new Vue(exports.vue);
        if(exports.vue.onReady){
            exports.vue.onReady(exports.vue.data, element);
        }
    }

    function load(){
        var site = window.CMSV7 && window.CMSV7.getSite ? window.CMSV7.getSite() : {};
        bindData.themes = site.themes || [];
        bindData.selected = localStorage.getItem("cms-v7-theme") || site.theme || (bindData.themes[0] ? bindData.themes[0].name : "");
    }

    function selectTheme(name){
        bindData.selected = name;
        bindData.open = false;
        localStorage.setItem("cms-v7-theme", name);
        if(window.CMSV7 && window.CMSV7.applyTheme){
            window.CMSV7.applyTheme(name);
        }else{
            applyThemeFallback(name);
        }
    }

    function applyThemeFallback(name){
        var theme = null;
        for(var i = 0; i < bindData.themes.length; i++){
            if(bindData.themes[i].name === name){
                theme = bindData.themes[i];
                break;
            }
        }
        if(!theme){
            return;
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

    function swatchStyle(theme){
        return {
            "--swatch-a": theme.primary || "#0f766e",
            "--swatch-b": theme.accent || "#e11d48"
        };
    }
});
