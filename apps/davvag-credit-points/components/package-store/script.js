WEBDOCK.component().register(function(exports){
    var api,keys={},returnTo='',data={packages:[],busy:0,order:null,errors:[],info:[]};
    exports.vue={data:data,methods:{go:go,buy:buy,money:money},onReady:init};
    function safeReturn(value){return /^#\/app\/(lesson-market-place\/(package\?slug=[a-z0-9-]+|my-enrolments)|davvag-credit-points\/)$/.test(value)?value:'';}
    function init(){api=exports.getComponent('credit-api');returnTo=safeReturn(new URLSearchParams(location.hash.split('?')[1]||'').get('return_to')||'');api.services.Packages({}).then(function(r){if(r.success)data.packages=r.result||[];else fail(msg(r));}).error(function(){fail('Packages could not be loaded.');});}
    function buy(p){if(data.busy)return;data.busy=p.id;data.errors=[];var storageKey='credit-checkout:'+p.id+':'+returnTo;keys[p.id]=keys[p.id]||sessionStorage.getItem(storageKey)||('store-'+p.id+'-'+Date.now());sessionStorage.setItem(storageKey,keys[p.id]);api.services.CreatePurchase({package_id:p.id,idempotency_key:keys[p.id]}).then(function(r){if(!r.success){data.busy=0;return fail(msg(r));}data.order=r.result;api.services.StartCheckout({order_id:data.order.id,return_to:returnTo}).then(function(result){data.busy=0;if(!result.success)return fail(msg(result));if(result.result.checkout_url && /^https:\/\/checkout\.stripe\.com\//.test(result.result.checkout_url))location.href=result.result.checkout_url;else go('purchase-status?order='+data.order.id);}).error(function(){data.busy=0;fail('Checkout could not start. Retry the same order.');});}).error(function(){data.busy=0;fail('Connection interrupted. Retry to recover the same order.');});}
    function money(p){var c=p.currency_config||{},places=Math.max(0,Math.min(6,Number(c.decimalPlaces===undefined?2:c.decimalPlaces))),amount=Number(p.price_minor||0)/Math.pow(10,places);return(c.symbol?c.symbol+' ':'')+amount.toFixed(places)+' '+p.currency;}
    function go(p){location.hash='#/app/davvag-credit-points/'+p;}
    function msg(r){return typeof r.result==='string'?r.result:(r.message||r.result&&r.result.message||'Request failed.');}
    function fail(x){data.errors=[x];data.info=[];}
});
