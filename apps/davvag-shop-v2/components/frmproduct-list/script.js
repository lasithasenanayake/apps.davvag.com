WEBDOCK.component().register(function(exports){
    var page=0;
    var size=40;
    var apploader;
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
   
    

    function loadproducts(){
       
        var routId   = exports.getShellComponent("soss-routes");
        routeData = routId.getInputData();
        var menuhandler  = exports.getComponent("productsvr");
            //var query=[{storename:"products",search:""}];
        if(menuhandler){
            
            try{
                bindData.loading=true;
                menuhandler.services.allProducts({page:page.toString(), size:size.toString(),cat:routeData.cat==null?"":routeData.cat ,q:bindData.q})
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
                                //bindData.loading=false;
                                page=page+bindData.products.length;
                            }
                        })
                        .error(function(error){
                            //bindData.products=[];
                            //bindData.loading=false;
                            bindData.allloaded=false;
                            //page=
                            console.log(error.responseJSON);
                    });
                
            }catch(e){
                console.log(e);
            }finally{
                bindData.loading=false;
            }
            
        }
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
        selectStore: function(p){
            bindData.product=p;
            bindData.product.qty=1;
            bindData.product.url="http://"+window.location.hostname+"/components/davvag-shop/productsvr/service/Product/?q="+bindData.product.itemid.toString();
            $('#modalImagePopup').modal('show');
        }, 
        navcheckout: function(){
            window.location="#/app/davvag-shop-v2/checkout";
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
            loadproducts();
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
              },
              getTimeLeft:function(d){
                  return genDate(d);
              }
        }
    } 

    function genDate(date){
        s=date.split("-");
        year =s[2].split(" ");
        expDate=new Date(year[0],parseInt(s[0])-1,s[1]);
        countDownDate=expDate.getTime();
        now = new Date().getTime();
        var distance = countDownDate - now;
        var days = Math.floor(distance / (1000 * 60 * 60 * 24));
        var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((distance % (1000 * 60)) / 1000);
        timeLeft=days + "d " + hours + "h "+ minutes + "m " + seconds + "s ";
        if (distance < 0) {
            //clearInterval(ticker);
            timeLeft = "EXPIRED";
        }
        return timeLeft;
    }

    exports.vue = vueData;
    
    exports.onReady = function(){
        
        

    }


});
