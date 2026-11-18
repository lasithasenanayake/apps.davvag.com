const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const apps = path.resolve(__dirname, '../..');
function harness(route = '#/app/learners-circle/') {
    const exported = {}, calls = [], embeds = [], loads = [];
    const location = {hash: route, href: 'http://localhost/' + route};
    const document = {documentElement: {contains: () => true}, createElement: () => ({setAttribute(k,v){this[k]=v;}})};
    const loader = {RenderHTML(...args) { embeds.push(args); }};
    const api = {services: {LearnerOverview(payload) { calls.push(payload); return {then(fn){loads.push(fn);return {error(){}};}}; }}};
    const context = {WEBDOCK:{component:()=>({register:fn=>fn(exported)})}, location, document, sessionStorage:{setItem(){}}, $:x=>x};
    vm.runInNewContext(fs.readFileSync(path.join(apps,'learners-circle/components/workspace/script.js'),'utf8'),context);
    exported.getAppComponent = (app, component, done) => done(component === 'api' ? api : loader);
    exported.vue.onReady();
    return {exported, d:exported.vue.data, m:exported.vue.methods, calls, embeds, loads, location};
}
test('dashboard loads the server overview without client profile overrides',()=>{
    const h=harness();assert.equal(h.d.active,'dashboard');assert.equal(h.calls.length,1);assert.equal(Object.keys(h.calls[0]).length,0);
    const response={learner:{id:7,name:'Learner'},stats:{courses:1},courses:[]};h.loads[0]({success:true,result:response});assert.equal(h.d.overview,response);
    assert.match(h.m.courseLink({id:4,current_lesson:{id:57}}),/^#\/app\/learners-circle\/learn\?course_id=4&lesson_id=57$/);
});
test('delayed dashboard responses cannot overwrite a later request',()=>{
    const h=harness();h.m.load();h.loads[1]({success:true,result:{learner:{name:'Fresh'}}});h.loads[0]({success:true,result:{learner:{name:'Old'}}});assert.equal(h.d.overview.learner.name,'Fresh');
});
test('learner routes reuse source components and recommendation filtering',()=>{
    for(const [route,app,component,key] of [['learn','lesson-manager','learn','learn'],['my-enrolments','lesson-market-place','my-enrolments','enrolments'],['catalog','lesson-market-place','catalog','catalog'],['package','lesson-market-place','package-card','package']]){
        const h=harness('#/app/learners-circle/'+route);assert.equal(h.d.active,route);assert.equal(h.calls.length,0);
        const element={innerHTML:'',querySelectorAll:()=>[],appendChild(node){this.child=node;}};
        h.exported.vue.directives.circleContent.inserted(element,{value:key});assert.equal(element.child['webdock-app'],app);assert.equal(element.child['webdock-component'],component);
        assert.equal(h.embeds[0][4].exclude_enrolled,key==='catalog'?true:undefined);
        const current=h.embeds[0][5].isCurrent;assert.equal(current(),true);h.exported.vue.directives.circleContent.unbind(element);assert.equal(current(),false);
    }
});
test('leaving an embedded view destroys its child Vue instance once',()=>{
    const h=harness();let destroyed=0;const child={__vue__:{$destroy(){destroyed++;}}};
    const element={querySelectorAll:()=>[child,child],innerHTML:'content'};h.exported.vue.directives.circleContent.unbind(element);assert.equal(destroyed,1);assert.equal(element.innerHTML,'');
});
test('marketplace catalog receives the personalized filter only when requested',()=>{
    for(const enabled of [true,false]){
        const e={},payloads=[];const location={hash:'#/app/learners-circle/catalog'};
        vm.runInNewContext(fs.readFileSync(path.join(apps,'lesson-market-place/components/catalog/script.js'),'utf8'),{WEBDOCK:{component:()=>({register:fn=>fn(e)})},location});
        e.dataObject={exclude_enrolled:enabled};e.getComponent=()=>({services:{Catalog(payload){payloads.push(payload);return{then(done){done({success:true,result:{items:[],total:0}});return{error(){}};}};}}});
        e.vue.onReady();assert.equal(payloads[0].exclude_enrolled,enabled);e.vue.methods.open({slug:'bible'});assert.equal(location.hash,'#/app/learners-circle/package?slug=bible');
    }
});
test('package and enrolment links retain Learners Circle while original routes keep working',()=>{
    const e={},location={hash:'#/app/learners-circle/my-enrolments'};
    vm.runInNewContext(fs.readFileSync(path.join(apps,'lesson-market-place/components/my-enrolments/script.js'),'utf8'),{WEBDOCK:{component:()=>({register:fn=>fn(e)})},location});
    e.vue.methods.open({slug:'bible'});assert.equal(location.hash,'#/app/learners-circle/package?slug=bible');location.hash='#/app/lesson-market-place/my-enrolments';e.vue.methods.open({slug:'bible'});assert.equal(location.hash,'#/app/lesson-market-place/package?slug=bible');
});

test('catalog remount ignores old results and restores the standalone catalog filter',()=>{
    const e={},requests=[];
    vm.runInNewContext(fs.readFileSync(path.join(apps,'lesson-market-place/components/catalog/script.js'),'utf8'),{WEBDOCK:{component:()=>({register:fn=>fn(e)})},location:{hash:'#/app/learners-circle/catalog'}});
    e.getComponent=()=>({services:{Catalog(payload){const r={payload};requests.push(r);return{then(done){r.done=done;return{error(){}};}};}}});
    e.dataObject={exclude_enrolled:true};e.vue.onReady();e.vue.beforeDestroy();e.vue.onReady(null,{appId:'lesson-market-place'});
    assert.equal(requests[0].payload.exclude_enrolled,true);assert.equal(requests[1].payload.exclude_enrolled,false);
    requests[1].done({success:true,result:{items:[{id:2}],total:1}});requests[0].done({success:true,result:{items:[{id:1}],total:1}});
    assert.equal(e.vue.data.items[0].id,2);
});

test('an active package continues learning within Learners Circle',()=>{
    const e={},location={hash:'#/app/learners-circle/package?slug=bible'};
    vm.runInNewContext(fs.readFileSync(path.join(apps,'lesson-market-place/components/package-card/script.js'),'utf8'),{WEBDOCK:{component:()=>({register:fn=>fn(e)})},location,URLSearchParams,sessionStorage:{getItem(){return null;},setItem(){}}});
    e.getComponent=()=>({services:{Package(){return{then(done){done({success:true,result:{terms:{lessons:[{id:56,course_id:4}]}}});return{error(){}};}};}}});
    e.vue.onReady();e.vue.methods.learn();assert.equal(location.hash,'#/app/learners-circle/learn?course_id=4&lesson_id=56');
});
