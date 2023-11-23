WEBDOCK.component().register(function(exports){
    var bindData = {
        item:{catogory:"Student",id:0,title:"",name:"Loading ....",gender:"m",organization:"",email:"",contactno:"",addresss:"",country:"",city:""},
        submitErrors: undefined,
        SearchItem:"",
        items:[],
        Activities:[],
        Transaction:[],
        Summary:{},
        showSearch:false,
        image:''
    };

    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data:bindData,
        methods: {
            getProfilebyID:getProfilebyID,
            navigate: function(id){
                handler = exports.getShellComponent("soss-routes");
                handler.appNavigate("../edit?id=" + id);
            },
            navigatebutton: function(pagev,p){
                //console.log(p);
               
                handler = exports.getShellComponent("soss-routes");
                if(p!=null){
                    handler.appNavigate("../"+pagev+"?id=" + p.id);
                    addProfileToTmp(p);
                }else{
                    handler.appNavigate("/"+pagev);
                }
            },
            openAttribuePopup:function(item){
                attribute=exports.getShellComponent("attribute_shell_popup");
                attribute.open("attr_profile_service_activation",item,function(data){
                    item.status=data.status;
                });
            },
            navigatepage: function(pagev,p){
                //console.log(p);
                //addProfileToTmp(p);
                handler = exports.getShellComponent("soss-routes");
                if(p!=null){
                    handler.appNavigate("../"+pagev+"?tid=" + p);
                }else{
                    handler.appNavigate("/"+pagev);
                }
            },
            status:function(status){
                switch((status?status:'active').toString().toLowerCase()){
                    case "tobeactive":
                        return "primary";
                    break;
                    case "tobeactivated":
                        return "pramary";
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
        },
        filters: {
            currency: function (value) {
              if (!value) return ''
              value = value.toString()
              return parseFloat(value).toFixed(2);
            }
          
        }
    }

    exports.vue = vueData;
    exports.onReady = function(element){
    }
    //var catogoryid ={"Staff",""};
    //var item ={};
    var productHandler;
    var profileHandler;
    var uploaderInstance;
    var pInstance;

    function initializeComponent(){
        profileHandler = exports.getComponent("profile");
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        if(routeData!=null){
            getProfilebyID(routeData.id)
        }
        console.log(routeData);
    }

    

    function clearProfile(){
        bindData.item={};
        showSearch=false;
    }
    

    function getProfilebyID(id){
        console.log(bindData.item)
        profileHandler.services.Search({q:"id:"+id})
        .then(function(response){
            console.log(JSON.stringify(response));
            if(response.success){
                if(response.result.length!=0){
                    console.log("items chnaged");
                    bindData.item=response.result[0];
                    bindData.image = 'components/dock/soss-uploader/service/get/profile/'+bindData.item.id;
                    
                    var query=[{storename:"profilestatus",search:"profileid:"+id},
                                {storename:"ledger","search":"profileid:"+id},
                                {storename:"profileservices","search":"profileId:"+id}];
                    profileHandler.services.q(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            bindData.Transaction=r.result.ledger;
                            if(r.result.profilestatus.length!=0){
                                bindData.Summary=r.result.profilestatus[0];
                            }
                            bindData.items=r.result.profileservices;
                        }
                    })
                    .error(function(error){
                        console.log(error.responseJSON);
                    });
                    //image
                }else{
                    clearProfile();
                }
            }else{
                alert (response.error);
            }
        })
        .error(function(error){
            alert (error.responseJSON.result);
            console.log(error.responseJSON);
        });
    }


});
