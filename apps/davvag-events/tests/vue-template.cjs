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
    if(screen==='seating-view'){
        instance.sections=[{id:'front',label:'Front',color:'#446688'}];
        instance.tables=['round','oval','square','rectangle','theatre'].map((shape,i)=>({id:'table'+i,number:i+1,label:shape==='theatre'?'Row A':'Table '+(i+1),shape,sectionId:'front',capacity:2,x:240+i*320,y:240,rotation:0,vip:false,seats:[{id:'seat'+i+'a',label:'1',accessible:true},{id:'seat'+i+'b',label:'2',accessible:false}]}));
        instance.selectedTableId='table0';instance.guests=[{id:'g1',name:'Guest One',group:'Family',rsvp:'Attending',accessible:false}];instance.groupRules=[{id:'family',label:'Family',color:'#994455',sectionId:'front',keepTogether:true}];
        for(const modal of ['','table','rows','section','group','assign']){instance.seatingModal=modal;const rendered=instance._render();if(!rendered||rendered.tag!=='section')throw Error('Seating popup failed: '+modal);}
        instance.seatingModal='';instance.planExpanded=true;instance.seatingView='map';
        function findSvg(node){if(!node)return null;if(node.tag==='svg')return node;for(const child of node.children||[]){const found=findSvg(child);if(found)return found;}return null;}
        const svg=findSvg(instance._render());if(!svg||!svg.data.attrs.viewBox||svg.data.attrs.viewbox)throw Error('SVG requires a case-correct viewBox attribute');
        if(svg.data.attrs.viewBox!==instance.planBox||svg.data.attrs.preserveAspectRatio!=='xMidYMid meet')throw Error('SVG fit binding failed');
        if(svg.data.style.width!=='100%'||svg.data.style.height!=='100%')throw Error('SVG needs an explicit width and height');
        instance.zoom=2;const zoomed=findSvg(instance._render());if(zoomed.data.style.width!=='200%'||zoomed.data.style.height!=='200%')throw Error('Zoom must scale both canvas dimensions');
        instance.planExpanded=false;instance.seatingView='list';if(instance._render().tag!=='section')throw Error('Seating list failed');
    }
    count++;
}
console.log(count+' route templates compiled and rendered as VNodes with Vue '+Vue.version+'.');
