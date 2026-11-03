WEBDOCK.component().register(function(exports) {
    var api, cart, state= {
        profile:localStorage.profile?JSON.parse(localStorage.profile): {
        }
        ,quote:null,quoteLoading:true,isBusy:false,submitErrors:[]
    }
    ;
    function fail(r) {
        var value=r && (r.result || r.message);
        state.submitErrors=[typeof value==="string"?value:"Unable to complete checkout. Please try again."];
        state.isBusy=false;
        state.quoteLoading=false;
    }
    function refreshQuote() {
        state.submitErrors=[];
        state.quoteLoading=true;
        state.quote=null;
        if(!cart) {
            fail( {
                result:"Your checkout cart is empty. Return to your cart and select a shop."
            }
            );
            return;
        }
        api.services.Quote( {
            cart:cart
        }
        ).then(function(r) {
            state.quoteLoading=false;
            if(r.success) {
                state.quote=r.result;
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
            try {
                cart=JSON.parse(sessionStorage.tmpstorecheckout||"null");
            }
            catch(e) {
                cart=null;
            }
            refreshQuote();
        }
        ,filters: {
            money:function(v) {
                return(Number(v)||0).toFixed(2);
            }
        }
        ,methods: {
            refreshQuote:refreshQuote,submitPurchase:function() {
                if(state.isBusy||!state.quote) {
                    return;
                }
                state.isBusy=true;
                state.submitErrors=[];
                var payload=Object.assign( {
                }
                ,state.profile, {
                    data:cart,quote_token:state.quote.quote_token
                }
                );
                exports.getComponent("productsvr").services.Checkout(payload).then(function(r) {
                    if(r.success && r.result.checkout_url) {
                        window.location.assign(r.result.checkout_url);
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
    }
    ;
}
);
