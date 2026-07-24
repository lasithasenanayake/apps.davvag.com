WEBDOCK.component().register(function (exports) {
    var api, maps, router, rootElement, preview;
    var state = {
        authorized:false, loading:true, saving:false, testing:false, error:"", notice:"",
        form:blank()
    };
    var viewState = state;
    exports.vue = {
        data:state,
        methods:{save:save,testMap:testMap,navigate:navigate},
        onReady:function (scope, element) {
            viewState=scope||state; rootElement=element;
            api=exports.getComponent("api"); maps=exports.getComponent("google-map-runtime"); router=exports.getShellComponent("soss-routes");
            if(!api||!api.services){viewState.loading=false;viewState.error="Destination services are unavailable.";return;}
            load();
        }
    };
    function blank(){
        return{enabled:false,api_key:"",has_api_key:false,map_id:"",language:"en",region:"LK",default_latitude:7.8731,default_longitude:80.7718,default_zoom:8,enable_geocoding:false,encryption_ready:false};
    }
    function load(){
        viewState.loading=true;viewState.error="";
        api.services.GetAdminMapSettings({}).then(function(r){
            viewState.loading=false;
            if(!r||!r.success){viewState.error=message(r,"Map settings could not be loaded.");return;}
            viewState.authorized=true;viewState.form=Object.assign(blank(),r.result||{}, {api_key:""});
        }).error(function(r){viewState.loading=false;viewState.error=message(r,"Administrator access is required.");});
    }
    function save(){
        if(viewState.saving){return;}
        viewState.saving=true;viewState.error="";viewState.notice="";
        api.services.SaveMapSettings(JSON.parse(JSON.stringify(viewState.form))).then(function(r){
            viewState.saving=false;
            if(!r||!r.success){viewState.error=message(r,"Map settings could not be saved.");return;}
            viewState.form=Object.assign(blank(),r.result||{}, {api_key:""});
            viewState.notice="Google Maps settings were saved. The API key remains encrypted on the server.";
        }).error(function(r){viewState.saving=false;viewState.error=message(r,"Map settings could not be saved.");});
    }
    function testMap(){
        if(viewState.testing){return;}
        viewState.testing=true;viewState.error="";viewState.notice="";
        api.services.GetMapConfiguration({}).then(function(r){
            if(!r||!r.success||!r.result||!r.result.enabled){
                viewState.testing=false;viewState.error="Save and enable a valid Google Maps key before testing.";return;
            }
            var container=find("[data-google-preview]");
            maps.createMap(container,r.result,{center:r.result.defaultCenter,zoom:r.result.defaultZoom,points:[{latitude:r.result.defaultCenter.lat,longitude:r.result.defaultCenter.lng,name:"Default map centre"}]})
                .then(function(result){preview=result;viewState.testing=false;viewState.notice="Google Maps loaded successfully with the saved settings.";})
                .catch(function(error){viewState.testing=false;viewState.error=error.message||"Google Maps could not be loaded.";});
        }).error(function(r){viewState.testing=false;viewState.error=message(r,"Saved map settings could not be loaded.");});
    }
    function find(selector){
        if(rootElement&&rootElement.find){return rootElement.find(selector)[0];}
        return document.querySelector(selector);
    }
    function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}
    function message(r,fallback){return r&&r.result&&r.result.message?r.result.message:fallback;}
});
