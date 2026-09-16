WEBDOCK.component().register(function (exports) {
    'use strict';
    var api, slug, key, data = {
        busy: false, error: '', item: null, confirming: false, info: ''
    };
    exports.vue = {
        data: data, methods: {
            load: load, action: action, confirm: confirm, cancel: cancel, buy: buy, signIn: signIn, close: function() {
                data.confirming=false;
            }
            , label: label, learn: learn
        }
        , onReady: function(s, context) {
            api=exports.getComponent('marketplace-api');
            var input=context && context.data || exports.dataObject || {
            };
            if (!input.slug && exports.renderDiv && exports.renderDiv.attr) {
                try {
                    input=JSON.parse(exports.renderDiv.attr('webdock-data') || '{}');
                }
                catch(ignore) {
                }
            }
            slug=input.slug || new URLSearchParams(location.hash.split('?')[1] || '').get('slug') || '';
            key=sessionStorage.getItem('lmp-request:'+slug) || ('lmp-'+Date.now()+'-'+Math.random().toString(36).slice(2));
            sessionStorage.setItem('lmp-request:'+slug, key);
            load();
        }
    };
    function invoke(name, payload, done) {
        if(data.busy)return;
        data.busy=true;
        data.error='';
        if(!api || !api.services || !api.services[name]) {
            data.busy=false;
            data.error='Marketplace service is unavailable. Reload the page.';
            return;
        }
        api.services[name](payload).then(function(r) {
            data.busy=false;
            if(!r.success) {
                data.error=message(r);
                return;
            }
            done(r.result);
        }
        ).error(function() {
            data.busy=false;
            data.error='Connection interrupted. Retry to recover the same request.';
        }
        );
    }
    function message(r) {
        return typeof r.result==='string'?r.result: (r.message || r.result && r.result.message || 'The request could not complete.');
    }
    function load() {
        invoke('Package', {
            slug: slug
        }
        , function(item) {
            data.item=item;
        }
        );
    }
    function route() {
        return '#/app/lesson-market-place/package?slug='+encodeURIComponent(slug);
    }
    function signIn() {
        sessionStorage.setItem('lmp_signin_return', route());
        location.hash='#/app/userapp/login';
    }
    function buy() {
        location.hash='#/app/davvag-credit-points/buy?return_to='+encodeURIComponent(route());
    }
    function learn() {
        var lessons=data.item.terms.lessons;
        if(lessons.length)location.hash='#/app/lesson-manager/learn?course_id='+lessons[0].course_id+'&lesson_id='+lessons[0].id;
    }
    function label() {
        var x=data.item;
        if(!x)return '';
        if(!x.signed_in)return 'Sign in to enrol';
        if(x.attempt) {
            if(x.attempt.status==='active')return 'Continue learning';
            if(x.attempt.status==='pending_approval')return 'Awaiting approval';
            if(['rejected', 'cancelled'].indexOf(x.attempt.status)>=0)return 'Apply again';
        }
        if(x.status!=='published')return 'Enrolment unavailable';
        if(x.terms.approval_required && !x.attempt)return 'Request enrolment';
        if(x.terms.credit_price>0) {
            if(x.balance && x.balance.availableBalance<x.terms.credit_price)return 'Buy credits';
            return 'Confirm enrolment for '+x.terms.credit_price+' credits';
        }
        return 'Enrol free';
    }
    function action() {
        var x=data.item;
        if(!x || data.busy)return;
        if(!x.signed_in)return signIn();
        if(x.attempt && x.attempt.status==='active')return learn();
        if(x.attempt && x.attempt.status==='pending_approval')return;
        if(x.status!=='published')return;
        if(x.attempt && ['rejected', 'cancelled'].indexOf(x.attempt.status)>=0) {
            key='lmp-'+Date.now()+'-'+Math.random().toString(36).slice(2);
            sessionStorage.setItem('lmp-request:'+slug, key);
        }
        if(label()==='Buy credits')return buy();
        // Re-read balance/state immediately before showing a purchase confirmation.
        invoke('Package', {
            slug: slug
        }
        , function(item) {
            data.item=item;
            data.confirming=true;
        }
        );
    }
    function confirm() {
        var x=data.item;
        if(!x || data.busy)return;
        if(x.attempt && x.attempt.status==='awaiting_payment')return pay(x.attempt);
        var previous=x.attempt && ['rejected', 'cancelled'].indexOf(x.attempt.status)>=0?Number(x.attempt.id): 0;
        var operationSlot='lmp-request:'+slug+':'+x.version_id+':'+previous;
        key=sessionStorage.getItem(operationSlot)||key;
        sessionStorage.setItem(operationSlot, key);
        invoke('RequestEnrolment', {
            package_id: Number(x.id), version_id: Number(x.version_id), operation_key: key, previous_attempt_id: previous
        }
        , function(attempt) {
            if(attempt.status==='awaiting_payment' && !x.terms.approval_required)return pay(attempt);
            data.confirming=false;
            data.info=attempt.status==='active'?'You are enrolled.': 'Your request is awaiting staff approval.';
            load();
        }
        );
    }
    function pay(attempt) {
        invoke('ConfirmEnrolment', {
            attempt_id: Number(attempt.id), version_id: Number(attempt.version_id)
        }
        , function() {
            data.confirming=false;
            data.info='Enrolment confirmed. Your lessons are ready.';
            load();
        }
        );
    }
    function cancel() {
        if(!data.item || !data.item.attempt)return;
        invoke('CancelEnrolment', {
            attempt_id: Number(data.item.attempt.id)
        }
        , function() {
            data.confirming=false;
            load();
        }
        );
    }
}
);
