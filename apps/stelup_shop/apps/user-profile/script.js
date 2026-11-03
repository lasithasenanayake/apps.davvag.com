
WEBDOCK.component().register(function(exports){
    var bindData = {
        item:{catogory:"",id:0,title:"",name:"",gender:"",organization:"",email:"",contactno:"",addresss:"",country:"",city:""},
        submitErrors: undefined,
        SearchItem:"",
        items:[],
        items_pending:[],
        items_rejected:[],
        Activities:[],
        Transaction:[],
        Summary:{},
        showSearch:false,
        canManageStripe:false,
        image:'components/dock/soss-uploader/service/get/profile/1',
        appName:"Transactions History",
        activeTab:"#tran",
        detailOpen:false,
        isMobile:window.matchMedia("(max-width: 767.98px)").matches,
        profile:{amount:0,remarks:""},
        profile_policy:{id:1,profilephoto:1,lastseen:1,status:1,read_receipts:1,posts:1},
        loadingApp:false,
        loadingAppError:false,
        submitErrors:[]
    };
    var newFile;
    var viewportQuery = window.matchMedia("(max-width: 767.98px)");
    var detailModal;

    function closeDetails() {
        bindData.detailOpen = false;
        if (bindData.isMobile && detailModal) {
            detailModal.modal("hide");
        }
    }

    function completeResponce() {
        closeDetails();
        bindData.appName = "Transactions History";
        showTab("#tran");
    }

    function showTab(tab) {
        bindData.activeTab = tab;
    }

    function openDetails(tab, title) {
        bindData.appName = title;
        showTab(tab);
        bindData.detailOpen = true;
        if (bindData.isMobile) {
            Vue.nextTick(function () { detailModal.modal("show"); });
        }
    }

    function updateViewport() {
        if (bindData.isMobile && !viewportQuery.matches && detailModal) {
            detailModal.modal("hide");
        }
        bindData.isMobile = viewportQuery.matches;
        if (!bindData.isMobile && detailModal) {
            Vue.nextTick(function () {
                detailModal.css("display", "").removeAttr("aria-hidden");
            });
        }
        if (bindData.isMobile && bindData.detailOpen) {
            Vue.nextTick(function () { detailModal.modal("show"); });
        }
    }

    var vueData = {
        onReady: function(){
            detailModal = $("#stelup-profile-detail");
            detailModal.on("hidden.bs.modal.stelupProfile", function () {
                bindData.detailOpen = false;
            });
            viewportQuery.addEventListener("change", updateViewport);
            exports.getComponent("stripe-api").services.PublicSettings().then(function(r){
                if(r.success){bindData.canManageStripe=!!r.result.can_manage_settings;}
            }).error(function(){});
            initializeComponent();
        },
        beforeDestroy: function () {
            viewportQuery.removeEventListener("change", updateViewport);
            if (detailModal) {
                detailModal.modal("hide");
                detailModal.off(".stelupProfile");
            }
        },
        data:bindData,
        methods: {
            updatepolicy:updatePolicy,
            changeProfilePic:function(){
                cropper1.crope(1,1,function(e){
                    //console.log(e);
                    bindData.image=e.data;
                    newFile=e.fileData;
                    exports.getAppComponent("davvag-tools","davvag-file-uploader", function(uploader){
                        uploader.initialize();
                        var files=[];
                        newFile.name=bindData.item.id;
                        files.push(newFile);
                        uploader.upload(files, "profile", null,function(e){
                            console.log(e);
                        });
                    });
                });

            },
            paybill:function(p){
                $('#modalImagePopup').modal('toggle');
                $('#modalImagePopup').on('hidden.bs.modal', function () {
                    bindData.item.amount=p.amount;
                    var data={profileId:bindData.item.id,email:bindData.item.email,name:bindData.item.name,
                    address:bindData.item.address,city:bindData.item.city,country:bindData.item.country,
                    contactno:bindData.item.contactno,amount:profile.amount,remarks:p.remarks
                    };
                    
                    authhandler.services.CreatePaymentRequest(data).then(function(result){
                        if(result.success){
                            window.location="#/app/davvag-ipg?repid="+result.result.id+"&url=#/app/userapp";
                        }else{
                            bindData.submitErrors.push("Error making the Transaction");
                        }
                    }).error(function(result){
                        bindData.submitErrors.push(result.result.message);
                    });
                });
            },
            showTab:openDetails,
            downloadapp:function(appname,form,data,apptitle){
                bindData.loadingApp=false;
                bindData.loadingAppError=false;
                openDetails("#app", apptitle);
                apploader.downloadAPP(appname,form,"appdock",function(){
                    bindData.loadingApp=true;
                    bindData.loadingAppError=false;
                },function(error){
                    console.log(error);
                    bindData.loadingAppError=true;
                },completeResponce,data);
            },
            hide:closeDetails,
            showMenu:function(a){
                if($(a).hasClass("show")){
                    $(a).removeClass("show");
                    $(a).addClass("toggle");
                }else{
                    $(a).addClass("show");
                    $(a).removeClass("toggle");
                }
                
            },
            setAttribute:function(a,itemprop,newValue){
                $(a).removeClass("show");
                $(a).addClass("toggle");
                bindData.profile_policy[itemprop]=newValue;
                updatePolicy();
            }
            ,
            pay:function(){

            },
            cancelPay:function(){
                $('#modalImagePopup').modal('toggle');
            },
            showPay:function(){
                $('#modalImagePopup').modal('show');
            },
            logout:function(){
                
                authhandler.services.Logout().then(function(result){
                    if(result.result){
                        localStorage.clear();
                        //sessionStorage.clear();
                        pInstance = exports.getShellComponent("soss-routes");
                        pInstance.appNavigate("../login");
                    }else{
                        localStorage.clear();
                        sessionStorage.clear();
                        pInstance = exports.getShellComponent("soss-routes");
                        pInstance.appNavigate("../login");
                    }
                }).error(function(result){
                    //$("#form-reset :input").prop("disabled", false);

                    bindData.submitErrors.push(result.result.message);
                    scope.isBusy = false;
                });
            },
            getProfilebyID:getProfilebyID,
            navigate: function(id){
                handler = exports.getShellComponent("soss-routes");
                handler.appNavigate("../edit?id=" + id);
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
                switch(status){
                    case "ToBeActive":
                        return "primary";
                    break;
                    case "Deactive":
                        return "danger";
                    break;
                    case "Active":
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
            },
            privacy_filter:function(value){
                switch(value){
                    case 0:
                        return "Nobody";
                        break;
                    case 1:
                        return "Everyone";
                        break;
                    case 2:
                        return "Followers";
                        break;
                }
            }

          
        }
    }

    function updatePolicy(){
        bindData.profile_policy.id=bindData.item.id;
        authhandler.services.updatePolicy(bindData.profile_policy).then(function(r){
            if(r.success){
                bindData.profile_policy=r.result;
            }
        }).error(function(er){

        });
    }

    exports.vue = vueData;
    exports.onReady = function(element){
    }
    //var catogoryid ={"Staff",""};
    //var item ={};
    var productHandler;
    var profileHandler;
    var authhandler;
    var pInstance;
    var apploader,cropper1;
    function initializeComponent(){
        exports.getAppComponent("davvag-tools","davvag-img-cropper", function(cropper){
            cropper.initialize(300,300);
            cropper1=cropper;
        });
        profileHandler = exports.getComponent("profile");
        pInstance = exports.getShellComponent("soss-data");
        authhandler = exports.getComponent ("login-handler");
        //userHandler = exports.getComponent("login-handler");
        exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_uploader){
            apploader=_uploader;
            apploader.initialize();
        });
        if(localStorage.profile!=null){
            profile =JSON.parse(localStorage.profile);
            //console.log(profile);
            getProfilebyID(profile.id);
        }
        //console.log(routeData);
    }

    

    function clearProfile(){
        bindData.item={};
        showSearch=false;
    }
    

    function getProfilebyID(id){
        //console.log(bindData.item)
            console.log("items chnaged");
            //bindData.item=response.result[0];
            bindData.image = 'components/dock/soss-uploader/service/get/profile/'+id;
            authhandler.services.ProfileData()
            .then(function(r){
                if(r.success){
                    bindData.Transaction=r.result.ledger;
                    if(r.result.profilestatus!=null){
                        bindData.Summary=r.result.profilestatus;
                    }
                    if(r.result!=null){
                        bindData.item=r.result;
                    }
                    if(r.result.profile_policy!=null){
                        bindData.profile_policy=r.result.profile_policy;
                    }
                    bindData.items=r.result.orders;
                    bindData.items_pending=r.result.order_pending;
                    bindData.items_rejected=r.result.order_rejected;
                }
            })
            .error(function(error){
                console.log(error.responseJSON);
            });
            //image
        
    }


});
