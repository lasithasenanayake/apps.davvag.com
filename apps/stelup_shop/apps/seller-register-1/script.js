WEBDOCK.component().register(function(exports) {
    var api, state= {
        loading:true,busy:false,error:"",step:1,done:false,csrf:"",profile: {
            attributes: {
            }
        }
        ,settings: {
        }
        ,connection: {
        }
    }
    ;
    function fail(r) {
        var value=r && (r.result || r.message);
        state.error=typeof value==="string"?value:"Unable to update seller registration. Please try again.";
        state.busy=false;
    }
    function refresh() {
        state.error="";
        state.busy=true;
        api.services.SellerStatus().then(function(r) {
            state.loading=false;
            state.busy=false;
            if(r.success) {
                state.profile=r.result.profile;
                state.profile.attributes=state.profile.attributes|| {
                }
                ;
                state.settings=r.result.settings;
                state.connection=r.result.connection|| {
                }
                ;
                state.csrf=r.result.csrf;
                if(r.result.connection_error) {
                    state.error=r.result.connection_error;
                }
            }
            else {
                fail(r);
            }
        }
        ).error(function() {
            state.loading=false;
            fail();
        }
        );
    }
    function save(done) {
        state.busy=true;
        state.error="";
        api.services.SellerProfile( {
            csrf:state.csrf,profile:state.profile
        }
        ).then(function(r) {
            if(r.success) {
                state.profile=r.result;
                done();
            }
            else {
                fail(r);
            }
        }
        ).error(function() {
            fail();
        }
        );
    }
    exports.vue= {
        data:state,onReady:function() {
            api=exports.getComponent("stripe-api");
            if(window.location.hash.indexOf("stripe=")!==-1) {
                state.step=3;
            }
            refresh();
        }
        ,computed: {
            connected:function() {
                return !!(this.connection.account_id && this.connection.charges_enabled && this.connection.payouts_enabled);
            }
        }
        ,methods: {
            refresh:refresh,next:function() {
                if(state.busy) {
                    return;
                }
                if(state.step<3) {
                    save(function() {
                        state.step++;
                        state.busy=false;
                    }
                    );
                }
                else {
                    state.busy=true;
                    api.services.CompleteSeller( {
                        csrf:state.csrf,profile:state.profile
                    }
                    ).then(function(r) {
                        state.busy=false;
                        if(r.success) {
                            localStorage.profile=JSON.stringify(r.result);
                            state.done=true;
                        }
                        else {
                            fail(r);
                        }
                    }
                    ).error(function() {
                        fail();
                    }
                    );
                }
            }
            ,connect:function() {
                if(state.busy) {
                    return;
                }
                save(function() {
                    api.services.Connect( {
                        csrf:state.csrf
                    }
                    ).then(function(r) {
                        if(r.success) {
                            window.location.assign(r.result.url);
                        }
                        else {
                            fail(r);
                        }
                    }
                    ).error(function() {
                        fail();
                    }
                    );
                }
                );
            }
        }
    }
    ;
}
);
