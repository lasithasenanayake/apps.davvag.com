WEBDOCK.component().register(function (exports) {
    var api, router;
    var state = {authorized:false, loading:true, saving:false, testing:false, error:"", notice:"", preview:null, form:blank()};
    var viewState = state;
    exports.vue = {
        data:state,
        methods:{save:save,testWeather:testWeather,navigate:navigate},
        onReady:function (scope) {
            viewState=scope||state;api=exports.getComponent("api");router=exports.getShellComponent("soss-routes");
            if(!api||!api.services){viewState.loading=false;viewState.error="Destination services are unavailable.";return;}
            load();
        }
    };
    function blank(){return{id:null,provider:"open_meteo",enabled:false,forecast_days:3,temperature_unit:"celsius",wind_speed_unit:"kmh",license_confirmed:false,cache_minutes:60};}
    function load(){
        viewState.loading=true;viewState.error="";
        api.services.GetAdminWeatherSettings({}).then(function(r){
            viewState.loading=false;if(!r||!r.success){viewState.error=message(r,"Weather settings could not be loaded.");return;}
            viewState.authorized=true;viewState.form=Object.assign(blank(),r.result||{});
        }).error(function(r){viewState.loading=false;viewState.error=message(r,"Administrator access is required.");});
    }
    function save(){
        if(viewState.saving){return;}viewState.saving=true;viewState.error="";viewState.notice="";
        api.services.SaveWeatherSettings(JSON.parse(JSON.stringify(viewState.form))).then(function(r){
            viewState.saving=false;if(!r||!r.success){viewState.error=message(r,"Weather settings could not be saved.");return;}
            viewState.form=Object.assign(blank(),r.result||{});viewState.notice="Weather settings were saved. Successful forecasts are cached for one hour.";
        }).error(function(r){viewState.saving=false;viewState.error=message(r,"Weather settings could not be saved.");});
    }
    function testWeather(){
        var destinationId=Number(viewState.form.test_destination_id||0);if(!destinationId){viewState.error="Enter a published destination ID to test.";return;}
        if(viewState.testing){return;}viewState.testing=true;viewState.error="";viewState.notice="";viewState.preview=null;
        api.services.GetDestinationWeather({destinationId:destinationId}).then(function(r){
            viewState.testing=false;if(!r||!r.success){viewState.error=message(r,"Weather could not be loaded.");return;}
            viewState.preview=r.result||null;if(!viewState.preview.available){viewState.error=viewState.preview.message||"Weather is not available for that destination.";}else{viewState.notice="Weather provider response loaded successfully.";}
        }).error(function(r){viewState.testing=false;viewState.error=message(r,"Weather could not be loaded.");});
    }
    function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}
    function message(r,fallback){return r&&r.result&&r.result.message?r.result.message:fallback;}
});
