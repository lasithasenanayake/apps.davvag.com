WEBDOCK.component().register(function(exports){
    var page=0;
    var size=6;
    var handler,apploader,complete_call,ticker;
    //var q
    //document.body.addEventListener('scroll', loadproducts);
   

    var bindData={
        products:[],
        q:"",
        loading:false,
        noproducts:false,
        allloaded:false,
        a5:0,
        profile:localStorage.profile!=null?JSON.parse(localStorage.profile):null,
        showstore:false,
        showshare:false,
        appTitle:"",
        appIcon:"",
        product:{name:"loading",images:[]},
        imgurl:"",
        timeLeft:"",
        expDate:new Date(),
        share:false
    };
    
    
    function additem(item,isOrder){
        complete_call({method:"add_item",item:item,isOrder:isOrder});
        
    }
    var vueData =  {
        methods:{
        showPic:function(img){
            bindData.imgurl='components/davvag-cms/soss-uploader/service/get/products/'+bindData.product.itemid+'-'+img;
        },
        downloadapp:function(appname,form,data,apptitle,appicon){
            //$('#decker1100').addClass("profile-content-show");
            complete_call({method:"app_open",appname:appname,form:form,data:data,apptitle:apptitle,appicon:appicon});
        },close: function(){
            //bindData.product=p;
            $('#modalappwindow').modal('toggle');
        },
        additem:additem,addchecout:function(item,isorder){
            additem(item,isorder);
        },
        showShare:function(){
            if(bindData.share){
                bindData.share=false;
                $("#sharebare").hide(1000);
            }else{
                bindData.share=true;
                $("#sharebare").show(1000);
            }
            
        },
        hideShare:function(){
           
        }
        },
        data :bindData
        ,
        onReady: function(s,c){
            handler  = exports.getComponent("productsvr");
            exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_uploader){
                apploader=_uploader;
                apploader.initialize();
            });
            scope=s;
            call_handler=c;
            complete_call=call_handler.completedEvent?call_handler.completedEvent:null;
            id=0;
            if(c.data){
                id=c.data.itemid;
            }else{
                alert("Error Not working...")
            }
            loadProduct(id);
        },
        filters:{
            markeddown: function (value) {
                if (!value) return ''
                value = value.toString()
                return marked(unescape(value));
              },
              currency_formate:function(val){
                if (!val) return '0.00'
                return parseFloat(val).toFixed(2);
              },
              dateformate:function(v){
                  if(!v){
                      return ""
                  }else{
                    return moment(v, "MM-DD-YYYY hh:mm:ss").format('MMMM Do YYYY');
                  }
              }
        }
    } 

    function genDate(){
        s=bindData.product.sellstype_attributes.bid_enddate.split("-");
        year =s[2].split(" ");
        bindData.expDate=new Date(year[0],parseInt(s[0])-1,s[1]);
        countDownDate=bindData.expDate.getTime();
        now = new Date().getTime();
        var distance = countDownDate - now;
        var days = Math.floor(distance / (1000 * 60 * 60 * 24));
        var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((distance % (1000 * 60)) / 1000);
        bindData.timeLeft=days + "d " + hours + "h "+ minutes + "m " + seconds + "s ";
        if (distance < 0) {
            clearInterval(ticker);
            bindData.timeLeft = "EXPIRED";
        }
    }
    function loadProduct(id){
        try{
            bindData.loading=true;
            handler.services.ProductDetails({id:id.toString()})
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            bindData.product=r.result;
                            if(bindData.product.sellstype_attributes){
                                //bindData.product.sellstype_attributes={};
                                if(bindData.product.sellstype_attributes.bid_enddate)
                                   ticker=setInterval(genDate,1000);
                            }else{
                                bindData.product.sellstype_attributes={};
                            }
                            
                            bindData.imgurl='components/davvag-cms/soss-uploader/service/get/products/'+bindData.product.itemid+'-'+bindData.product.imgurl;
                        }
                        bindData.allloaded=false;
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

    exports.vue = vueData;
    
    exports.onReady = function(){
        
        

    }


});
