WEBDOCK.component().register(function(exports){
    var bindData = {
        item:{catogory:"Student",id:0,title:"",name:"",gender:"",organization:"",email:"",contactno:"",addresss:"",country:"",city:"dddddddd"},
        submitErrors: undefined,
        SearchItem:"",
        items:[],
        Activities:[],
        Transaction:[],
        Summary:{},
        showSearch:false,
        image:'',
        ActiveEnrolments:[],
        EnrolmentHistory:[]
    };

    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data:bindData,
        methods: {
            getProfilebyID:getProfilebyID,
            showTab:renderDrid,
            navigate: function(id){
                handler = exports.getShellComponent("soss-routes");
                handler.appNavigate("../edit?id=" + id);
            },
            navigatebutton: function(pagev,p){
                //console.log(p);
               
                handler = exports.getShellComponent("soss-routes");
                if(p!=null){
                    handler.appNavigate("../"+pagev+"?id=" + p.id);
                    //addProfileToTmp(p);
                }else{
                    handler.appNavigate("/"+pagev);
                }
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
    var attribute;
    function renderDrid(code,id) {
        //lockForm(); 
        if($("#"+code).hasClass("collapse")){
            //$("#"+code).removeClass("collapse");
            //$("#"+code).addClass("show");
            attribute.renderGrid("attr_lbc_entrollments","grid_"+code,[{type:"data",name:"subject_code",displayname:"Code",attributes:[]},
            {type:"data",name:"subject_name",displayname:"Name"},{type:"data",displayname:"Result",function:function(e){
                //item=e.data;
                if(e.result>0 && e.result<50){
                    return "F";
                }else if (e.result>=50 && e.result<55){
                    return "C-";
                }else if (e.result>=55 && e.result<61){
                    return "C";
                }else if (e.result>=61 && e.result<67){
                    return "C+";
                }else if (e.result>=67 && e.result<73){
                    return "B-";
                }else if (e.result>=73 && e.result<79){
                    return "B";
                }else if (e.result>=79 && e.result<85){
                    return "B+";
                }else if (e.result>=85 && e.result<91){
                    return "A-";
                }else if (e.result>=91 && e.result<96){
                    return "A+";
                }else if (e.result>=96 && e.result<=100){
                    return "A+";
                }
                return "Not Graded Yet";
            }},
            {type:""},
            {type:"button",name:"edit",fn:"function",caption:"Edit",displayname:"",function:function(e){
                item=e.data;
                att_popup=exports.getShellComponent("attribute_shell_popup");
                att_popup.open(e.id,item,function(_d){
                    console.log(_d);
                });
            }}],
            "id:"+id,function(i){
                
                
            });
        }else{
            //$("#"+code).removeClass("show");
            //$("#"+code).addClass("collapse");
        }
        
    }
    
    function initializeComponent(){
        profileHandler = exports.getComponent("profile");
        pInstance = exports.getShellComponent("soss-routes");
        uploaderInstance = exports.getComponent ("soss-uploader");
        attribute=exports.getShellComponent("attribute_shell");
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
                    
                    var query=[{storename:"lbc_course_entrolments_active",search:"profileId:"+id},
                                {storename:"lbc_course_entrolments_history","search":"profileId:"+id}];
                    profileHandler.services.q(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            bindData.ActiveEnrolments=r.result.lbc_course_entrolments_active;
                            bindData.EnrolmentHistory=r.result.lbc_course_entrolments_history;
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
