WEBDOCK.component().register(function(exports){
    var bindData = {
        site: {
            name: "Davvag CMS v7",
            nav: {links: [], cta: {}}
        },
        initials: "D"
    };

    exports.vue = {
        data: bindData,
        onReady: function(){
            load();
            window.addEventListener("cms-v7-theme-changed", load);
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

    function load(){
        var site = window.CMSV7 && window.CMSV7.getSite ? window.CMSV7.getSite() : {};
        if(site && site.name){
            bindData.site = site;
            bindData.site.nav = bindData.site.nav || {links: [], cta: {}};
            bindData.initials = site.name.substring(0, 1).toUpperCase();
        }
    }
});
