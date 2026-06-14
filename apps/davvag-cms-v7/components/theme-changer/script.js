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
            window.addEventListener("cms-v7-theme-changed", function(event){
                bindData.selected = event.detail.name;
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
        if(window.CMSV7 && window.CMSV7.applyTheme){
            window.CMSV7.applyTheme(name);
        }
    }

    function swatchStyle(theme){
        return {
            "--swatch-a": theme.primary || "#0f766e",
            "--swatch-b": theme.accent || "#e11d48"
        };
    }
});
