WEBDOCK.component().register(function(exports){
    var page=0,size=5;
    var routeData={},menuhandler={};
    
    var bindData={
        Articals:[],
        Products:[],
        sidebar:[],
        titlepage:{name:"",title:"",caption:""},
        id:0,allloaded:false,loading:false,cards:[],
        carousel:[]
    };
    var vueData =  {
        methods:{
            
            getfiletype:function(filename){
                if(filename==null){
                    return "";
                }
                var a = filename.split(".");
                if( a.length === 1 || ( a[0] === "" && a.length === 2 ) ) {
                    return "";
                }
                filetype=a.pop().toLowerCase();
                switch(filetype){
                    case "jpeg":
                            return "img";
                    break;
                    case "png":
                        return "img";
                    break;
                    case "jpg":
                        return "img";
                    break;
                    case "mp3":
                        return "audio";
                    break;
                    default:
                        return "link";
                    break;
                }
                return a.pop().toLowerCase();
            }
        },
        data :bindData
        ,
        onReady: function(s){
            //createlayout();
            //createlayout();
            pInstance = exports.getShellComponent("soss-routes");
            routeData = pInstance.getInputData();
            menuhandler  = exports.getComponent("soss-data");
            


            loadData();
            MainSroll();
            

            if(sessionStorage.blogheader){
                bindData.titlepage=JSON.parse(sessionStorage.blogheader);
            }else{
                var data={name:"cms-global"}
                menuhandler.services.Settings(data)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                bindData.titlepage= r.result;
                                sessionStorage.blogheader=JSON.stringify(r.result)
                            }
                        })
                        .error(function(error){
                            //bindData.Articals=[];
                            console.log(error.responseJSON);
            });
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
        }
    } 

    function MainSroll(){
        $('.carousel').carousel({
            interval: 4000
          });
        if(window.innerWidth>600){
            $("#mainBody").scroll(function(e){
                if ((e.currentTarget.clientHeight + e.currentTarget.scrollTop+30) >= e.currentTarget.scrollHeight) {
                    // you're at the bottom of the page
                    console.log($("#mainBody").outerHeight());
                    console.log("In the event ...");
                    if(!bindData.allloaded && !bindData.loading){
                        //page=page+size;
                        loadData();
                        //console.log("Bottom of the page products " +bindData.products.length +" pageNumber "+page);
                    }
                }
              });
        }else{
            window.document.body.onscroll = function(e) {
                if ((window.innerHeight + window.scrollY+30) >= document.body.offsetHeight) {
    
                    console.log("In the event ...");
                    if(!bindData.allloaded && !bindData.loading){
                        //page=page+size;
                        loadData();
                        console.log("Bottom of the page products " +bindData.products.length +" pageNumber "+page);
                    }
                }
                } 
        }
        


        
    }

    

    function loadData(){
        bindData.loading=true;
        var query=[];
        
            if(routeData.pid==null){
                //
                //query.push({storename:"d_cms_cat_v1",search:"parentButtonid:0"});
                if(bindData.Articals.length==0){
                    query.push({storename:"d_cms_cat_v1",search:"parentButtonid:0"},{storename:"d_cms_cards_v1",search:"parentButtonid:0"});
                }
                query.push({storename:"d_cms_artical_v1_pod_paging",parameters:{page:page.toString(),size:size.toString(),boost:"Y"}},{storename:"d_cms_carousel_dtl_v1",search:"catid:0"});

            }else{
                //query.push({storename:"d_cms_artical_v1",search:"catid:"+routeData.pid});
                //query.push({storename:"d_cms_cat_v1",search:"parentButtonid:"+routeData.pid});
                if(bindData.Articals.length==0){
                    query.push({storename:"d_cms_cat_v1",search:"parentButtonid:"+routeData.pid},{storename:"d_cms_cards_v1",search:"parentButtonid:"+routeData.pid},{storename:"d_cms_carousel_dtl_v1",search:"catid:"+routeData.pid});
                }
                query.push({storename:"d_cms_artical_v1_pod_bycat_paging",parameters:{page:page.toString(),size:size.toString(),catid:routeData.pid.toString()}});

                bindData.id=routeData.pid;
            }
            //var tmpmenu=[];
            //bindData.TopButtons=[];
            var articals=[];
            menuhandler.services.q(query)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                //if(r.result.d_cms_artical_v1!=null){
                                    if(r.result.d_cms_artical_v1_pod_paging!=null){
                                        
                                        articals=r.result.d_cms_artical_v1_pod_paging;
                                    }

                                    if(r.result.d_cms_artical_v1_pod_bycat_paging!=null){
                                        articals=r.result.d_cms_artical_v1_pod_bycat_paging;
                                    }
                                //}
                                if(r.result.d_cms_cards_v1!=null){
                                    bindData.cards=r.result.d_cms_cards_v1;
                                }
                                
                                if(r.result.d_cms_carousel_dtl_v1!=null){
                                    bindData.carousel=r.result.d_cms_carousel_dtl_v1;
                                }
                                if(r.result.d_cms_cat_v1!=null){
                                    bindData.sidebar=r.result.d_cms_cat_v1;
                                }
                                if(articals.length==0){
                                    bindData.allloaded=true;
                                }
                                articals.forEach(element => {
                                    bindData.Articals.push(element);
                                    page++;
                                });
                                bindData.loading=false;

                            }
                        })
                        .error(function(error){
                            //bindData.Articals=[];
                            bindData.loading=false;
                            console.log(error.responseJSON);
            });
        }

    exports.vue = vueData;
    exports.onReady = function(){
        
        

    }

    
});
