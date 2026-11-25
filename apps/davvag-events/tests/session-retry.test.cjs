const fs=require('fs'),vm=require('vm'),crypto=require('crypto'),path=require('path');
const app=path.resolve(__dirname,'..');
let sessionCalls=0,listCalls=0;
function result(value){const chain={then(fn){fn({success:true,result:value});return chain;},error(){return chain;}};return chain;}
const api={services:{Session(){sessionCalls++;return result(sessionCalls===1?{actor:null,csrf:'fixture',templates:[],identityError:{code:'PROFILE_REQUIRED',message:'Your signed-in account has no accessible linked DAVVAG profile.'}}:{actor:{id:'1'},csrf:'fixture',templates:[],identityError:null});},ListEvents(){listCalls++;return result({events:[],nextOffset:null});}}};
const sandbox={window:{},location:{hash:'#/app/davvag-events/dashboard'},URLSearchParams,Uint8Array,crypto:crypto.webcrypto,document:{addEventListener(){},removeEventListener(){}},setTimeout,clearTimeout,console};
vm.createContext(sandbox);vm.runInContext(fs.readFileSync(path.join(app,'components/event-style/events-ui.js'),'utf8'),sandbox);
const exportsObject={getComponent(){return api;},getShellComponent(){return null;}};
sandbox.window.DavvagEventsUI(exportsObject,'dashboard');
(async()=>{exportsObject.vue.onReady(null);await new Promise(setImmediate);if(exportsObject.vue.data.error.indexOf('signed-in account')<0)throw Error('Specific profile error was hidden.');await exportsObject.vue.methods.refresh();if(sessionCalls!==2||listCalls!==1||exportsObject.vue.data.error!=='')throw Error('Retry did not reload the authenticated session.');console.log('PASS specific profile failure is displayed');console.log('PASS Retry reloads Session and then the dashboard');})().catch(error=>{console.error(error);process.exitCode=1;});
