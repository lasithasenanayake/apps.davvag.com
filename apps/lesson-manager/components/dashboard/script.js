WEBDOCK.component().register(function(exports){
 var api,router;var data={loading:false,role:'',stats:{},recentLessons:[],recentAttempts:[],studentCourses:[],errors:[],info:[]};
 exports.vue={data:data,methods:{load:load,go:go,seed:seed,statusClass:statusClass},onReady:init};exports.onReady=function(){};
 function init(){api=exports.getComponent('api');router=exports.getShellComponent('soss-routes');load();}
 function load(){messages();data.loading=true;api.services.Dashboard({}).then(function(r){data.loading=false;if(!r.success)return error('Could not load the learning overview.');var x=r.result||{};data.role=x.role||'';data.stats=x.stats||{};data.recentLessons=x.recentLessons||[];data.recentAttempts=x.recentAttempts||[];data.studentCourses=x.studentCourses||[];}).error(function(){data.loading=false;error('Could not load the learning overview.');});}
 function seed(){api.services.SeedDemo({}).then(function(r){if(r.success){info('Starter lesson created.');load();}else error('Create a course and subject in Course Manager first.');});}
 function go(path){if(router&&router.appNavigate)router.appNavigate('/'+path);else location.hash='#/app/lesson-manager/'+path;}
 function statusClass(v){return 'lm-badge '+String(v||'draft').toLowerCase();}function error(v){data.errors=[v];}function info(v){data.info=[v];}function messages(){data.errors=[];data.info=[];}
});
