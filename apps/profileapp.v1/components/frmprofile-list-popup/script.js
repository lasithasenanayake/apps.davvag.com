WEBDOCK.component().register(function(exports){
    var bindData = {
        submitErrors: undefined,
        SearchItem:"",
        SearchColumn:"name",
        items:undefined,
        image:'',
        Message:'Please start by searching the profile or creating a new profile',
        Launchers:[]
    };

    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data:bindData,
        methods: {
            searchItems:searchItems,
            appLauncher:appLauncher,
            clear:function(){
                localStorage.removeItem("tmpprofiles");
                bindData.items=[];
                bindData.Message='Please start by searching the profile or creating a new profile';
            },
            select:function(p){
                exports.Complete(p);
            },
            navigate: function(pagev,p){
                //console.log(p);
               
                var handler = exports.getShellComponent("soss-routes");
                if(p!=null){
                    handler.appNavigate("/"+pagev+"?id=" + p.id);
                    addProfileToTmp(p);
                }else{
                    handler.appNavigate("/"+pagev);
                }
            },status:function(status){
                switch((status?status:'active').toString().toLowerCase()){
                    case "tobeactive":
                        return "primary";
                    break;
                    case "tobeactivated":
                        return "primary";
                        break;
                    case "inactive":
                        return "warning";
                    break;
                    case "void":
                        return "danger";
                    break;
                    case "active":
                        return "success";
                    break;
                    default:
                        return "warning";
                    break;
                }
            }
        }
    }

    function addProfileToTmp(p){
        var profiles=getTmpProfiles();
        var additem=true;
        profiles.forEach(element => {
            if(element.id==p.id){
                element=p;
                additem=false;
                return;
            }
        });
        if(additem){
            profiles.push(p);
        }
        localStorage.setItem("tmpprofiles",JSON.stringify(profiles));
    }

    function getTmpProfiles(){
        var value = localStorage.getItem("tmpprofiles");
        if(!value){
            return [];
        }
        try{
            var profiles = JSON.parse(value);
            return Array.isArray(profiles) ? profiles : [];
        }catch(e){
            localStorage.removeItem("tmpprofiles");
            return [];
        }
    }
    
    exports.vue = vueData;
    exports.onReady = function(element){
    }
    //var catogoryid ={"Staff",""};
    //var item ={};
    
    var profileHandler;

    function appLauncher(launcher,data){
        if(typeof apploader !== "undefined" && apploader.launchApp){
            apploader.launchApp(launcher,function(){},function(e){
                console.log(e);
            },function(){},data);
        }
    }

    function initializeComponent(){
        profileHandler = exports.getComponent("profile");
        var handler  = exports.getShellComponent("auth-handler");
       
        handler.services.Launchers({appcode:"profileapp",component:"frmprofile-list"})
            .then(function(r){
                if(r.success){
                    bindData.Launchers=r.result;
                }
            }).error(function(e){

        });

        var profiles = getTmpProfiles();
        if(profiles.length !== 0){
            bindData.items=profiles;
            bindData.Message="";
        }
        
    }

    

    

    function searchItems(columncode,columnvalue){
        //console.log(encodeURI(columncode+":"+columnvalue))
        WEBDOCK.freezeUiComponent("soss-routes",true); 
        bindData.Message="Searching Profiles Please wait....";
        profileHandler.services.SearchV1({column:columncode,value:columnvalue})
        .then(function(response){
            if(response.success){
                //console
                //bindData.item.id=response.result.result.generatedId;
                bindData.items=[];
                if(response.result.length!=0){
                    response.result.forEach(element => {
                        bindData.items.push({
                            name:element.name,
                            id:element.id,
                            image:"components/dock/soss-uploader/service/get/profile/"+element.id,
                            email:element.email,
                            contactno:element.contactno,
                            organization:element.organization,
                            city:element.city,
                            country:element.country,
                            Status:element.Status
                        })
                    });
                    
                    bindData.Message="";
                    WEBDOCK.freezeUiComponent("soss-routes",false); 
                    //console.log(JSON.stringify(bindData.items));
                }else{
                    bindData.Message="No profiles Found for "+columncode+" = "+ columnvalue;
                    WEBDOCK.freezeUiComponent("soss-routes",false); 
                }
            }else{
                alert (response.error);
                WEBDOCK.freezeUiComponent("soss-routes",false); 
            }
        })
        .error(function(error){
            alert (error.responseJSON ? error.responseJSON.result : "Search failed");
            WEBDOCK.freezeUiComponent("soss-routes",false); 
        });
    }


});
