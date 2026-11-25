// Compile and render VNodes with the installed Vue 2.0.3. This is not browser/layout QA.
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const crypto = require('crypto');
const root = process.cwd();
const app = path.resolve(__dirname, '..');
const sandbox = { module: {exports:{}}, exports:{}, console, setTimeout, clearTimeout,
    document: {createElement() {return {set innerHTML(v) {this._html=v;this.textContent=v.replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&amp;/g,'&').replace(/&quot;/g,'"');},get innerHTML(){return this._html;}};}},
};
vm.createContext(sandbox);
vm.runInContext(fs.readFileSync(path.join(root,'davvag-core/localhost/apps/dock/shell/soss-routes-vue/vue.min.js'),'utf8'),sandbox);
const Vue=sandbox.module.exports;
if(Vue.version!=='2.0.3')throw Error('Review the installed Vue version before changing this compatibility test.');
sandbox.window={};sandbox.location={hash:'#/app/davvag-events/dashboard',pathname:'/davvag-core/'};
sandbox.URLSearchParams=URLSearchParams;sandbox.Uint8Array=Uint8Array;sandbox.crypto=crypto.webcrypto;sandbox.Intl=Intl;
vm.runInContext(fs.readFileSync(path.join(app,'components/event-style/events-ui.js'),'utf8'),sandbox);
const manifest=JSON.parse(fs.readFileSync(path.join(app,'app.json'),'utf8'));
let count=0;
for(const [screen,entry] of Object.entries(manifest.components)){
    if(entry.type!=='component'||screen==='event-style')continue;
    const exports={};sandbox.window.DavvagEventsUI(exports,screen.replace(/-view$/,''));
    const source=fs.readFileSync(path.join(app,'components',screen,'partial.html'),'utf8');
    const compiled=Vue.compile(source);
    if(compiled.render.toString().length<200)throw Error('Template compilation failed: '+screen);
    const options=exports.vue;options.render=compiled.render;options.staticRenderFns=compiled.staticRenderFns;
    const instance=new Vue(options);instance.loading=false;
    instance.chooseTemplate({occasion:'wedding',heading:'A fictional gathering',message:'Welcome',design:{accent:'#264b42',background:'#f7faf8',font:'serif',cover:'',sections:['message','details','agenda','rsvp','seating','greetings','media','tickets']}});
    instance.session={actor:{id:'101'},setup:{stripe:false,drive:false,smtp:false}};
    instance.event={id:'fixture',role:'owner',draft:instance.form,status:'published',parties:[],members:[],checkins:[],summary:{title:'Fixture',rsvp:{Attending:2},parties:1,remaining:10,seated:0,checkins:0,pendingGreetings:0}};
    instance.report={jobs:[],audit:[]};
    const vnode=instance._render();if(!vnode||vnode.tag!=='section')throw Error('VNode render failed: '+screen);
    if(screen==='editor-view'){
        instance.templates=[{id:'wedding-v1',version:1,occasion:'wedding',name:'Garden',active:true,custom:false,heading:'Welcome',message:'Join us',html:'<section><h1>{{title}}</h1></section>',css:'h1{color:var(--accent)}',design:instance.form.design}];
        instance.form.templateId='wedding-v1';instance.form.templateVersion=1;
        for(const step of ['details','design','review']){instance.editorStep=step;instance.session.actor.admin=true;instance.templateBuilderOpen=true;const rendered=instance._render();if(!rendered||rendered.tag!=='section')throw Error('Editor step failed: '+step);}
    }
    count++;
}
console.log(count+' route templates compiled and rendered as VNodes with Vue '+Vue.version+'.');
