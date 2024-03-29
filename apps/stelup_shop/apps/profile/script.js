WEBDOCK.component().register(function(exports){
    var page=0;
    var size=12;
    var menuhandler,profilehandler,apploader;
    
    //var q
    //document.body.addEventListener('scroll', loadproducts);
   

    var bindData={
        products:[],
        product:{caption:""},
        q:"",
        loading:false,
        noproducts:false,
        allloaded:false,
        itemCount:0,
        profile:localStorage.profile!=null?JSON.parse(localStorage.profile):{id:0},
        showstore:false,
        showshare:false,
        user:{},
        tid:1,
        appTitle:"",
        appIcon:""
    };

    var items=[];
    if(sessionStorage.items){           
        items=JSON.parse(sessionStorage.items);
        for (var i=0;i<items.length;i++){
            var item = items[i];
            bindData.itemCount += item.qty;
        }
        //services.topMenu.additems(undefined,bindData.itemCount);              
    }

    function loadProfile(){
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        idx=routeData.id?routeData.id:bindData.profile.id;
        if(idx==0){
            window.location="#/app/userapp?u=#app/stelup_shop/profile";
        }
        //bindData.loading=true;
        profilehandler.services.Profile({id:idx}).then(function(a){
            if(a.success){
                bindData.user=a.result;
                loadproducts();
            }else{
                console.log(a);
            }
        }).error(function(e){
            console.log(e);
        })
    }
   
    
    //var firstLoad=true;
    function loadproducts(){
       if(bindData.loading)
        return;
        
        
            //var query=[{storename:"products",search:""}];
        if(profilehandler){
            
            try{
                bindData.loading=true;
                profilehandler.services.allProducts({page:page.toString(), size:size.toString(),pid:bindData.profile==null?"0":bindData.profile.id.toString(),id:bindData.user.id.toString(),tid:bindData.tid.toString()})
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                if(r.result.length==0){
                                    
                                    bindData.allloaded=true;
                                }else{
                                    bindData.allloaded=false;
                                }
                                
                                var i;
                                for (i = 0; i < r.result.length; i++) {
                                    //text += cars[i] + "<br>";
                                    bindData.products.push(r.result[i]);
                                }
                                if(bindData.products.length==0){
                                    bindData.noproducts=true;
                                }else{
                                    bindData.noproducts=false;
                                }
                                //
                                page=page+bindData.products.length;
                                bindData.loading=false;
                            }
                            //firstLoad=false;
                        })
                        .error(function(error){
                            //bindData.products=[];
                            bindData.loading=false;
                            bindData.allloaded=false;
                            //page=
                            console.log(error.responseJSON);
                    });
                
            }catch(e){
                console.log(e);
            }finally{
                //bindData.loading=false;
            }
            
        }
    }

    function goLogin(){
        $('#modalImagePopup').modal('hide');
        $('#modalImagePopup').on('hidden.bs.modal', function (e) {
            window.location="#/app/userapp/?u=#/app/stelup_shop";
        });
    }
    
    function completeResponce(d){
        if(d.method){
            switch(d.method){
                case "url_redirect":
                    
                    $('#modalappwindow').on('hidden.bs.modal', function () {
                        // do something…
                        window.location=d.url;
                      });
                      $('#modalappwindow').modal('toggle');
                    break;
                case "app_open":
                    $('#modalappwindow').on('hidden.bs.modal', function () {
                        // do something…
                        $('#modalappwindow').unbind();
                        bindData.appIcon=d.appicon;
                        bindData.appTitle=d.apptitle;
                        $('#modalappwindow').modal('toggle');
                        apploader.downloadAPP(d.appname,d.form,"appdock",function(d){
                            
                        },function(e){
                            console.log(e);
                            bindData.loadingAppError=true;
                        },completeResponce,d.data);
                      });
                      $('#modalappwindow').modal('toggle');
                      break;
                case "add_item":
                      additem(d.item,d.isOrder);
                      $('#modalappwindow').modal('toggle');
                      break;
            }
        }
        //console.log(e);
    }
    
    function additem(itemx,isOrder){
        item=JSON.parse(JSON.stringify(itemx));
        item.qty=1;
        items=[];
        if(sessionStorage.items){           
            items=JSON.parse(sessionStorage.items);
        }
        x=0;
        for(i in items){
            if(items[i].itemid===item.itemid){
                items[i].qty++;
                items[i].isOrder = isOrder;
                sessionStorage.items=JSON.stringify(items);
                bindData.itemCount =0;
                for (var i=0;i<items.length;i++){
                    var it = items[i];
                    bindData.itemCount += it.qty;
                }
                
                return;
            }
            x++;
        }
        
        item.isOrder = isOrder;
        items.push(item);
        sessionStorage.items=JSON.stringify(items);
        bindData.itemCount =0;
        for (var i=0;i<items.length;i++){
            var it = items[i];
            bindData.itemCount += it.qty;
        }
        //services.topMenu.additems(item,bindData.itemCount);
        
    }

    var vueData =  {
        methods:{
            downloadapp:function(appname,form,data,apptitle,appicon){
                //$('#decker1100').addClass("profile-content-show");
                bindData.appIcon=appicon;
                bindData.appTitle=apptitle;
                $('#modalappwindow').modal('toggle');
                apploader.downloadAPP(appname,form,"appdock",function(d){
                    
                },function(e){
                    console.log(e);
                    bindData.loadingAppError=true;
                },completeResponce,data);
            },close: function(){
                //bindData.product=p;
                $('#modalappwindow').modal('toggle');
            },
        loadPd:function(i){
            page=0;
            bindData.tid=i;
            bindData.products=[];
            loadproducts();
        },
        edit:function(i){
            $('#modalImagePopup').modal('toggle');
            $('#modalImagePopup').on('hidden.bs.modal', function (e) {
                window.location="#/app/stelup_shop/itemonboard?id="+i.itemid.toString();
            });
            //window.location="#/app/stelup_shop/itemonboard?id="+i.itemid.toString();
        },
        marksold:function(i){
            i.showonstore="N";
            menuhandler.services.SaveProduct(i).then(function(r){
                //bindData.data.showonstore="N";
                filteredItems = bindData.products.filter(function(a) {
                    if(a.itemid!==i.itemid)
                        return a;
                });
                bindData.products=filteredItems==null?[]:filteredItems;
                $('#modalImagePopup').modal('toggle');
            }).error(function(e){
                console.log(e);
            });
            
            //window.location="#/app/stelup_shop/itemonboard?id="+i.itemid.toString();
        },
        like:function(i){
            if(bindData.profile){
                var like={itemid:i.itemid,pid:bindData.profile.id,liked:true};
                if(i.liked==0){
                    like.liked=true;
                }else{
                    like.liked=false;
                }
                menuhandler.services.Like(like).then(function(a){
                    if(a.success){
                        if(like.liked){
                            i.liked=1;
                        }else{
                            i.liked=0;
                        }
                    }else{
                        console.log(a);
                    }
                }).error(function(e){
                    console.log(e);
                })
                
            }else{
                goLogin();
            }
        },follow:function(i){
            
            if(bindData.profile){
                var like={id:bindData.user.id,pid:bindData.profile.id,liked:true};
                if(i.followed==0){
                    like.liked=true;
                }else{
                    like.liked=false;
                }
                profilehandler.services.Follow(like).then(function(a){
                    if(a.success){
                        if(like.liked){
                            i.followed=1;
                        }else{
                            i.followed=0;
                        }
                        
                    }else{
                        console.log(a);
                    }
                }).error(function(e){
                    console.log(e);
                })
                
            }else{
                goLogin();
            }
        },favorite:function(i){
            
            if(bindData.profile){
                var like={itemid:i.itemid,pid:bindData.profile.id,liked:true};
                if(i.favorite==0){
                    like.liked=true;
                }else{
                    like.liked=false;
                }
                menuhandler.services.Favorite(like).then(function(a){
                    if(a.success){
                        if(like.liked){
                            i.favorite=1;
                        }else{
                            i.favorite=0;
                        }
                        
                    }else{
                        console.log(a);
                    }
                }).error(function(e){
                    console.log(e);
                })
                
            }else{
                goLogin();
            }
        },
        isEditable:function(item){
            if(bindData.profile){
                if(bindData.profile.id==item.storeid){
                    return true;
                }else{
                    return false;
                }
            }else{
                return false;
            }
        },
        selectStore: function(p){
            bindData.product=p;
            bindData.product.qty=1;
            bindData.product.url="http://"+window.location.hostname+"/components/davvag-shop/productsvr/service/Product/?q="+bindData.product.itemid.toString();
            $('#modalImagePopup').modal('show');
        }, 
        navcheckout: function(){
            window.location="#/app/stelup_shop/checkout-cart";
        }   
        ,selectStoreClose: function(){
            //bindData.product=p;
            $('#modalImagePopup').modal('toggle');
        },handleScroll (event) {
            // Any code to be executed when the window is scrolled
            console.log(event);
          },
        onSearch () {
            if(bindData.q!=""){
                page=0;
                bindData.allloaded=false;
                bindData.products=[];
                loadproducts();
            }
        },
        OnkeyEnter: function(e){
            bindData.noproducts=false;
            if (e.keyCode === 13) {
                if(bindData.q!=""){
                    page=0;
                    bindData.products=[];
                    loadproducts();
                }
            }
        },
        additem:additem
        },
        data :bindData
        ,
        onReady: function(s){
            exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_uploader){
                apploader=_uploader;
                apploader.initialize();
            });
            menuhandler  = exports.getComponent("app-handler");
            profilehandler  = exports.getComponent("p_svr");
            loadProfile();
            
            window.document.body.onscroll = function(e) {
    
            //console.log(window.document.body);
            //console.log("test  " + (window.innerHeight + window.scrollY) +" yo " +document.body.offsetHeight);
            if ((window.innerHeight + window.scrollY+30) >= document.body.offsetHeight) {
                // you're at the bottom of the page
                console.log("In the event ...");
                if(!bindData.allloaded && !bindData.loading){
                    //page=page+size;
                    loadproducts();
                    console.log("Bottom of the page products " +bindData.products.length +" pageNumber "+page);
                }
            }
            //loadproducts();
            }    
            
        },
        filters:{
            markeddown: function (value) {
                if (!value) return ''
                value = value.toString()
                return marked(unescape(value));
              },
              dateformate:function(v){
                  if(!v){
                      return ""
                  }else{
                    return moment(v, "MM-DD-YYYY hh:mm:ss").format('MMMM Do YYYY');
                  }
              }
        },
        computed:{
            reviewsore:function(){
                if(this.user.reviews==0){
                    return 0;
                }else{
                    this.user.reviewscore/this.user.reviews;
                }
            }
        }
    } 

    exports.vue = vueData;
    
    exports.onReady = function(){
        
        

    }


});
