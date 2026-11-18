const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {JSDOM,VirtualConsole}=require('jsdom');
const apps=path.resolve(__dirname,'../..');
const html=fs.readFileSync(path.join(apps,'learners-circle/components/workspace/partial.html'),'utf8');
const script=fs.readFileSync(path.join(apps,'learners-circle/components/workspace/script.js'),'utf8');
const vue=fs.readFileSync(path.join(apps,'davvag-cms-v7/shell/soss-routes-vue/vue.min.js'),'utf8');
async function mount(t,courses){
    const errors=[],embeds=[];
    const dom=new JSDOM('<!doctype html><html><body><div id="app">'+html+'</div></body></html>',{url:'http://localhost/#/app/learners-circle/',runScripts:'outside-only',virtualConsole:new VirtualConsole().on('jsdomError',e=>errors.push(e))});
    t.after(()=>dom.window.close());const w=dom.window;w.eval(vue);
    const OriginalFunction=w.Function;w.Function=function(...args){try{return OriginalFunction(...args);}catch(error){errors.push(error);throw error;}};
    const overview={learner:{id:7,name:'Learner'},stats:{courses:courses.length,total_lessons:2,completed_lessons:1,completion_percentage:50,earned_marks:8,maximum_marks:10,marks_percentage:80},courses};
    const e={
        getAppComponent(app,component,done){
            if(component==='api'){
                done({services:{LearnerOverview(){
                    return {then(fn){fn({success:true,result:overview});return {error(){}};}};
                }}});
            }else{
                done({RenderHTML(...args){embeds.push(args);}});
            }
        }
    };
    w.$=x=>x;w.WEBDOCK={component:()=>({register:fn=>fn(e)})};w.eval(script);
    new w.Vue({el:'#app',data:e.vue.data,methods:e.vue.methods,computed:e.vue.computed,directives:e.vue.directives});e.vue.onReady();
    for(let i=0;i<5;i++)await new Promise(setImmediate);
    return{w,errors,embeds};
}
test('production Vue compiles the dashboard with progress and marks',async t=>{
    const h=await mount(t,[{id:4,code:'BIBLE',title:'Bible study',teacher_names:[],course_status:'in_progress',total_lessons:2,completed_lessons:1,completion_percentage:50,total_marks:8,maximum_marks:10,marks_percentage:80,pending_quizzes:1,pending_assignments:0,current_lesson:{id:57}}]);
    assert.deepEqual(h.errors,[]);assert.match(h.w.document.body.textContent,/Bible study/);assert.match(h.w.document.body.textContent,/8 \/ 10 · 80%/);assert.equal(h.w.document.querySelectorAll('[role="progressbar"]').length,2);assert.equal(h.embeds.length,1);assert.equal(h.embeds[0][4].exclude_enrolled,true);
});
test('new learner empty state compiles and keeps package discovery available',async t=>{
    const h=await mount(t,[]);assert.deepEqual(h.errors,[]);assert.match(h.w.document.body.textContent,/Your next chapter starts here/);assert.equal(h.embeds.length,1);assert.ok(h.w.document.querySelector('a[href="#/app/learners-circle/catalog"]'));
});
