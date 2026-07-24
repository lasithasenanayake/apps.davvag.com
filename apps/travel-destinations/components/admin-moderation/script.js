WEBDOCK.component().register(function(exports){
    var api,router;var state={authorized:false,loading:true,error:"",queues:{},actionKey:null,queueDefinitions:[
        {key:"destinations",title:"Destination submissions",method:"ApproveSubmission",actions:[{label:"Approve",method:"ApproveSubmission"},{label:"Return",method:"ReturnSubmission"},{label:"Reject",method:"RejectSubmission"}]},
        {key:"media",title:"Photos",method:"ApproveMedia",actions:[{label:"Approve",status:"Approved"},{label:"Reject",status:"Rejected"}]},
        {key:"reviews",title:"Reviews",method:"ModerateReview",actions:[{label:"Approve",status:"Approved"},{label:"Reject",status:"Rejected"}]},
        {key:"comments",title:"Comments",method:"ModerateComment",actions:[{label:"Approve",status:"Approved"},{label:"Reject",status:"Rejected"}]},
        {key:"conditions",title:"Condition reports",method:"ModerateCondition",actions:[{label:"Approve",status:"Approved"},{label:"Reject",status:"Rejected"}]},
        {key:"reports",title:"User reports",method:"ResolveReport",actions:[{label:"Resolve",status:"Resolved"},{label:"Dismiss",status:"Dismissed"}]}
    ]};var viewState=state;
    exports.vue={data:state,methods:{load:load,navigate:navigate,collection:function(key){return viewState.queues[key]||[];},moderate:moderate},onReady:function(scope){
        viewState=scope||state;api=exports.getComponent("api");router=exports.getShellComponent("soss-routes");if(!api||!api.services){viewState.loading=false;viewState.error="Destination services are unavailable.";return;}
        api.services.Capabilities({}).then(function(r){viewState.authorized=!!(r&&r.success&&r.result&&r.result.administrator);if(viewState.authorized){load();}else{viewState.loading=false;}}).error(function(){viewState.loading=false;viewState.error="Access could not be verified.";});
    }};
    function load(){viewState.loading=true;viewState.error="";api.services.GetModerationQueue({}).then(function(r){viewState.loading=false;if(r&&r.success){viewState.queues=r.result||{};}else{viewState.error=msg(r,"Queues could not be loaded.");}}).error(function(){viewState.loading=false;viewState.error="Queues could not be loaded.";});}
    function moderate(queue,item,action){if(viewState.actionKey){return;}viewState.actionKey=queue.key+"-"+item.id;var method=action.method||queue.method;var payload={id:item.id,status:action.status,reason:"Moderated from review queue.",resolution_notes:"Reviewed by administrator."};api.services[method](payload).then(function(r){viewState.actionKey=null;if(r&&r.success){load();}else{viewState.error=msg(r,"Moderation action failed.");}}).error(function(){viewState.actionKey=null;viewState.error="Moderation action failed.";});}
    function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}function msg(r,f){return r&&r.result&&r.result.message?r.result.message:f;}
});
