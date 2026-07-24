WEBDOCK.component().register(function(exports){
    var api,router;var state={authorized:false,items:[],form:blank(),loading:true,saving:false,seeding:false,error:""};var viewState=state;
    exports.vue={data:state,methods:{save:save,seed:seed,edit:edit,reset:reset,navigate:navigate},onReady:function(scope){
        viewState=scope||state;api=exports.getComponent("api");router=exports.getShellComponent("soss-routes");
        viewState.loading=true;viewState.error="";
        if(!api||!api.services){viewState.loading=false;viewState.error="Destination services are unavailable.";return;}
        api.services.Capabilities({}).then(function(r){viewState.authorized=!!(r&&r.success&&r.result&&r.result.administrator);if(viewState.authorized){load();}else{viewState.loading=false;}}).error(function(){viewState.loading=false;viewState.error="Access could not be verified.";});
    }};
    function blank(){return{id:null,name:"",description:"",sort_order:0,is_active:true,icon_key:""};}
    function load(){viewState.loading=true;api.services.GetAmenities({}).then(function(r){viewState.loading=false;if(r&&r.success){replaceItems(viewState.items,r.result||[]);}else{viewState.error=msg(r,"Amenities could not be loaded.");}}).error(function(){viewState.loading=false;viewState.error="Amenities could not be loaded.";});}
    function save(){if(viewState.saving){return;}viewState.saving=true;viewState.error="";api.services.SaveAmenity(JSON.parse(JSON.stringify(viewState.form))).then(function(r){viewState.saving=false;if(r&&r.success){reset();load();}else{viewState.error=msg(r,"Amenity could not be saved.");}}).error(function(){viewState.saving=false;viewState.error="Amenity could not be saved.";});}
    function seed(){if(viewState.seeding){return;}viewState.seeding=true;api.services.SeedReferenceData({}).then(function(r){viewState.seeding=false;if(r&&r.success){replaceItems(viewState.items,(r.result&&r.result.amenities)||[]);}else{viewState.error=msg(r,"Amenities could not be seeded.");}}).error(function(){viewState.seeding=false;viewState.error="Amenities could not be seeded.";});}
    function replaceItems(target,items){target.splice.apply(target,[0,target.length].concat(Array.isArray(items)?items:[]));}
    function edit(item){viewState.form=JSON.parse(JSON.stringify(item));}function reset(){viewState.form=blank();}function navigate(path){if(router&&router.appNavigate){router.appNavigate(path);}else{window.location.hash="#/app/travel-destinations"+path;}}function msg(r,f){return r&&r.result&&r.result.message?r.result.message:f;}
});
