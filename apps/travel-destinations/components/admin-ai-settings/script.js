WEBDOCK.component().register(function (exports) {
    var api, router;
    var state = {authorized:false, loading:true, saving:false, error:"", notice:"", agents:[], form:blank()};
    var viewState = state;
    exports.vue = {
        data:state,
        methods:{save:save,navigate:navigate},
        onReady:function (scope) {
            viewState=scope||state;api=exports.getComponent("api");router=exports.getShellComponent("soss-routes");
            if(!api||!api.services){viewState.loading=false;viewState.error="Destination services are unavailable.";return;}
            load();
        }
    };
    function blank(){return{id:null,provider:"ai-agent-creator",agent_code:"",enabled:false,fill_empty_only:true,minimum_confidence:0.75};}
    function load(){
        viewState.loading=true;viewState.error="";
        api.services.GetAdminAiSettings({}).then(function(r){
            viewState.loading=false;if(!r||!r.success){viewState.error=message(r,"AI settings could not be loaded.");return;}
            viewState.authorized=true;replaceItems(viewState.agents,r.result&&r.result.agents);viewState.form=Object.assign(blank(),r.result&&r.result.settings||{});
        }).error(function(r){viewState.loading=false;viewState.error=message(r,"Administrator access is required.");});
    }
    function save(){
        if(viewState.saving){return;}viewState.saving=true;viewState.error="";viewState.notice="";
        api.services.SaveAiSettings(JSON.parse(JSON.stringify(viewState.form))).then(function(r){
            viewState.saving=false;if(!r||!r.success){viewState.error=message(r,"AI settings could not be saved.");return;}
            replaceItems(viewState.agents,r.result&&r.result.agents);viewState.form=Object.assign(blank(),r.result&&r.result.settings||{});viewState.notice="AI destination autofill settings were saved.";
        }).error(function(r){viewState.saving=false;viewState.error=message(r,"AI settings could not be saved.");});
    }
    function replaceItems(target,items){target.splice.apply(target,[0,target.length].concat(Array.isArray(items)?items:[]));}
    function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}
    function message(r,fallback){if(!r){return fallback;}if(typeof r.result==="string"&&r.result){return r.result;}if(r.result&&r.result.message){return r.result.message;}if(r.message){return r.message;}if(r.responseJSON){return message(r.responseJSON,fallback);}return fallback;}
});
