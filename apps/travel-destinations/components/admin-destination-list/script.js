WEBDOCK.component().register(function(exports){
    var api,router;var state={authorized:false,items:[],status:"",statuses:["Draft","Pending Review","Returned for Changes","Approved","Rejected","Published","Archived"],loading:true,error:"",actionId:null};var viewState=state;
    exports.vue={data:state,methods:{navigate:navigate,load:load,edit:edit,transition:transition,locked:function(item){return viewState.actionId===item.id;}},onReady:function(scope){
        viewState=scope||state;api=exports.getComponent("api");router=exports.getShellComponent("soss-routes");
        viewState.loading=true;viewState.error="";
        if(!api||!api.services){viewState.loading=false;viewState.error="Destination services are unavailable.";return;}
        api.services.Capabilities({}).then(function(r){
            viewState.authorized=!!(r&&r.success&&r.result&&r.result.administrator);
            if(viewState.authorized){load();}else{viewState.loading=false;}
        }).error(function(){viewState.loading=false;viewState.error="Access could not be verified.";});
    }};
    function load(){
        if(viewState.loading&&viewState.items.length){return;}
        viewState.loading=true;viewState.error="";
        api.services.ListAdminDestinations({status:viewState.status,page:0,pageSize:100}).then(function(r){
            viewState.loading=false;
            if(r&&r.success){replaceItems(viewState.items,responseItems(r));}
            else{viewState.error=msg(r,"Destinations could not be loaded.");}
        }).error(function(){viewState.loading=false;viewState.error="Destinations could not be loaded.";});
    }
    function responseItems(response){
        var payload=response&&response.result!==undefined?response.result:response;
        if(payload&&payload.result!==undefined&&!payload.items){payload=payload.result;}
        if(Array.isArray(payload)){return payload;}
        return payload&&Array.isArray(payload.items)?payload.items:[];
    }
    function replaceItems(target,items){target.splice.apply(target,[0,target.length].concat(items));}
    function transition(item,method){if(viewState.actionId){return;}viewState.actionId=item.id;api.services[method]({id:item.id,reason:"Updated from destination administration."}).then(function(r){viewState.actionId=null;if(r.success){load();}else{viewState.error=msg(r,"Status could not be changed.");}}).error(function(){viewState.actionId=null;viewState.error="Status could not be changed.";});}
    function edit(item){navigate("/submit?id="+encodeURIComponent(item.id));}function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}function msg(r,f){return r&&r.result&&r.result.message?r.result.message:f;}
});
