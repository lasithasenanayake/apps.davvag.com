WEBDOCK.component().register(function(exports){
    var api,router;var state={items:[],loading:true,error:""};
    exports.vue={data:state,methods:{navigate:navigate,edit:edit,view:view,editable:editable},onReady:function(scope){
        api=exports.getComponent("api");router=exports.getShellComponent("soss-routes");
        scope.loading=true;scope.error="";
        if(!api||!api.services){scope.loading=false;scope.error="Destination services are unavailable.";return;}
        api.services.GetMySubmissions({page:0,pageSize:100}).then(function(r){
            scope.loading=false;
            if(r&&r.success){
                replaceItems(scope.items,responseItems(r));
            }else{
                scope.error=msg(r,"Sign in to view your submissions.");
            }
        }).error(function(){scope.loading=false;scope.error="Submissions could not be loaded.";});
    }};
    function responseItems(response){
        var payload=response&&response.result!==undefined?response.result:response;
        if(payload&&payload.result!==undefined&&!payload.items){payload=payload.result;}
        if(Array.isArray(payload)){return payload;}
        return payload&&Array.isArray(payload.items)?payload.items:[];
    }
    function replaceItems(target,items){target.splice.apply(target,[0,target.length].concat(items));}
    function editable(item){return ["Draft","Returned for Changes"].indexOf(item.status)>=0;}function edit(item){navigate("/submit?id="+encodeURIComponent(item.id));}function view(item){navigate("/place?id="+encodeURIComponent(item.id));}
    function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}function msg(r,f){return r&&r.result&&r.result.message?r.result.message:f;}
});
