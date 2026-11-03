WEBDOCK.component().register(function(exports) {
    var api, state= {
        loading:true,busy:false,allowed:false,error:"",saved:false,settings: {
            enabled:false,mode:"test",base_url:window.location.origin+window.location.pathname.replace(/\/(index|admin)\.php$/,"").replace(/\/$/,""),commission_percent:0,service_fee_percent:0,secret_key:"",webhook_secret:""
        }
    }
    ;
    function error(result) {
        var value=result && (result.result || result.message);
        return typeof value==="string"?value:"Unable to load or save Stripe settings.";
    }
    function apply(value) {
        state.settings=Object.assign( {
        }
        ,state.settings,value, {
            secret_key:"",webhook_secret:""
        }
        );
        state.allowed=true;
        state.loading=false;
    }
    exports.vue= {
        data:state,onReady:function() {
            api=exports.getComponent("stripe-api");
            api.services.Settings().then(function(r) {
                state.loading=false;
                if(r.success) {
                    apply(r.result);
                }
                else {
                    state.error=error(r);
                }
            }
            ).error(function() {
                state.loading=false;
                state.error="Administrator access is required, or settings are unavailable.";
            }
            );
        }
        ,methods: {
            save:function() {
                if(state.busy) {
                    return;
                }
                state.error="";
                state.saved=false;
                state.busy=true;
                api.services.SaveSettings(state.settings).then(function(r) {
                    state.busy=false;
                    if(r.success) {
                        apply(r.result);
                        state.saved=true;
                    }
                    else {
                        state.error=error(r);
                    }
                }
                ).error(function() {
                    state.busy=false;
                    state.error="Unable to save Stripe settings.";
                }
                );
            }
        }
    }
    ;
}
);
