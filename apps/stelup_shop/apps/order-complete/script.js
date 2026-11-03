WEBDOCK.component().register(function(exports) {
    var state= {
        loading:true,error:"",payment:null
    }
    ,api,token;
    function verify() {
        state.loading=true;
        state.error="";
        if(!token) {
            state.loading=false;
            state.error="No Stripe checkout reference was provided.";
            return;
        }
        api.services.PaymentStatus( {
            token:token
        }
        ).then(function(r) {
            state.loading=false;
            if(!r.success) {
                state.error=typeof r.result==="string"?r.result:"Unable to verify payment. Please try again.";
                return;
            }
            state.payment=r.result;
            if(r.result.paid) {
                var seen=JSON.parse(sessionStorage.stelupPaidTokens||"[]");
                if(seen.indexOf(token)===-1) {
                    var purchased=r.result.quote;
                    var items=JSON.parse(sessionStorage.items||"[]");
                    items=items.filter(function(item) {
                        if(String(item.storeid)!==String(purchased.storeid)) {
                            return true;
                        }
                        var row=purchased.items.filter(function(p) {
                            return String(p.itemid)===String(item.itemid);
                        }
                        )[0];
                        if(!row) {
                            return true;
                        }
                        item.qty=Math.max(0,Number(item.qty)-row.qty);
                        return item.qty>0;
                    }
                    );
                    sessionStorage.items=JSON.stringify(items);
                    seen.push(token);
                    sessionStorage.stelupPaidTokens=JSON.stringify(seen.slice(-20));
                    sessionStorage.removeItem("tmpstorecheckout");
                }
            }
        }
        ).error(function() {
            state.loading=false;
            state.error="Unable to verify payment. Please try again.";
        }
        );
    }
    exports.vue= {
        data:state,onReady:function() {
            api=exports.getComponent("stripe-api");
            var hash=window.location.hash;
            token=new URLSearchParams(hash.indexOf("?")===-1?"":hash.substring(hash.indexOf("?")+1)).get("checkout_token");
            verify();
        }
        ,filters: {
            money:function(v) {
                return(Number(v)||0).toFixed(2);
            }
        }
        ,methods: {
            verify:verify
        }
    }
    ;
}
);
