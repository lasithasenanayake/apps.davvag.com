WEBDOCK.component().register(function(exports) {
    'use strict';
    var requestId=0;
    var api, d= {
        busy: false, error: '', items: [], total: 0, offset: 0, search: '', status: '', excludeEnrolled: false
    };
    exports.vue= {
        data: d, methods: {
            load: load, page: page, open: open
        }
        , onReady: function(scope, context) {
            var input=context && context.data || (!context || !context.appId ? exports.dataObject : null) || {};
            d.excludeEnrolled=input.exclude_enrolled===true;
            requestId++;
            d.busy=false;
            d.items=[];
            d.total=0;
            d.offset=0;
            api=exports.getComponent('marketplace-api');
            load();
        }, beforeDestroy: function() { requestId++; d.busy=false; }
    };
    function call(name, payload, done) {
        if(d.busy)return;
        var current=++requestId;
        d.busy=true;
        d.error='';
        if(!api||!api.services||!api.services[name]) {
            d.busy=false;
            d.error='Service unavailable. Reload the page.';
            return;
        }
        api.services[name](payload).then(function(r) {
            if(current!==requestId)return;
            d.busy=false;
            if(!r.success) {
                d.error=typeof r.result==='string'?r.result: (r.message||r.result&&r.result.message||'Request failed.');
                return;
            }
            done(r.result);
        }
        ).error(function() {
            if(current!==requestId)return;
            d.busy=false;
            d.error='Connection interrupted. Retry the same action.';
        }
        );
    }
    function load() {
        call('Catalog', {
            limit: 20, offset: d.offset, search: d.search, status: d.status, exclude_enrolled: d.excludeEnrolled
        }
        , function(r) {
            d.items=r.items;
            d.total=r.total;
        }
        );
    }
    function page(direction) {
        d.offset=Math.max(0, d.offset+direction*20);
        load();
    }
    function open(item) {
        var app=/^#\/app\/learners-circle(?:\/|\?|$)/.test(location.hash)?'learners-circle':'lesson-market-place';
        location.hash='#/app/'+app+'/package?slug='+encodeURIComponent(item.slug);
    }
}
);
