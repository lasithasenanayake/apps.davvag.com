WEBDOCK.component().register(function(exports){
    var apploader;
    var bindData={
        products:[],
        carousel:[]
    };
    function completeResponce(e){
        console.log("ss");
    }
    var vueData =  {
        methods:{
        },
        data :bindData
        ,
        onReady: function(s){
            try {
                var menuhandler  = exports.getComponent("soss-data");
                var query=[{storename:"products",search:""}];
                query.push({storename:"d_cms_carousel_dtl_v1",search:"catid:0"});
                
                menuhandler.services.q(query)
                            .then(function(r){
                                finalLoad();
                                //console.log(JSON.stringify(r));
                                if(r.success){
                                    //setTimeout(finalLoad, 500);
                                    
                                    bindData.products= r.result.products;
                                    bindData.carousel=r.result.d_cms_carousel_dtl_v1;
                                }
                            })
                            .error(function(error){
                                bindData.products=[];
                                console.log(error.responseJSON);
                });
            } catch (error) {
                console.log(error);
            }
            
            
        },
        filters:{
              dateformate:function(v){
                  if(!v){
                      return ""
                  }else{
                    return moment(v, "MM-DD-YYYY hh:mm:ss").format('MMMM Do YYYY');
                  }
              }
        }
    } 

    function finalLoad(){
        exports.getAppComponent("davvag-tools","davvag-app-downloader", function(a){
            if(a.initialize){
                apploader=a;
                apploader.initialize();
                apploader.downloadAPP("davvag-shop-v2","product-catogory-list","appLoad-cat",function(d){
                    apploader.downloadAPP("davvag-shop-v2","frmproduct-list","appLoad",function(d){
                    
                    },function(e){
                        console.log(e);
                        //bindData.loadingAppError=true;
                        $("#appLoad").html('<div class="d-flex align-items-center"><strong> Error Loading...</strong><div class="spinner-border ml-auto" role="status" aria-hidden="true"></div></div>')
                    },completeResponce,{page:0,size:12,searchCondition:""});
                },function(e){
                    console.log(e);
                    //bindData.loadingAppError=true;
                    $("#appLoad").html('<div class="d-flex align-items-center"><strong> Error Loading...</strong><div class="spinner-border ml-auto" role="status" aria-hidden="true"></div></div>')
                },completeResponce,{page:0,size:12,searchCondition:""});

               
                
            }else{
                alert("Error Loading Componet");
            }
        });
    }

    exports.vue = vueData;
    exports.onReady = function(){
        
        

    }

    
});
