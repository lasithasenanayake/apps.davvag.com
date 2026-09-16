WEBDOCK.component().register(function(exports) {
    'use strict';
    var api, d= {
        busy: false, error: '', items: [], total: 0, offset: 0, search: '', status: ''
    };
    exports.vue= {
        data: d, methods: {
            load: load, page: page, open: open
        }
        , onReady: function() {
            api=exports.getComponent('marketplace-api');
            load();
        }
    };
    function call(name, payload, done) {
        if(d.busy)return;
        d.busy=true;
        d.error='';
        if(!api||!api.services||!api.services[name]) {
            d.busy=false;
            d.error='Service unavailable. Reload the page.';
            return;
        }
        api.services[name](payload).then(function(r) {
            d.busy=false;
            if(!r.success) {
                d.error=typeof r.result==='string'?r.result: (r.message||r.result&&r.result.message||'Request failed.');
                return;
            }
            done(r.result);
        }
        ).error(function() {
            d.busy=false;
            d.error='Connection interrupted. Retry the same action.';
        }
        );
    }
    function load() {
        call('MyEnrolments', {
            limit: 20, offset: d.offset, search: d.search, status: d.status
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
        location.hash='#/app/lesson-market-place/package?slug='+encodeURIComponent(item.slug);
    }
}
);
