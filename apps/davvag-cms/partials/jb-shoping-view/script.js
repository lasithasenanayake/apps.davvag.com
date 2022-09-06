WEBDOCK.component().register(function(exports){

    var bindData={
        products:[],
        carousel:[]
    };
    var vueData =  {
        methods:{
        },
        data :bindData
        ,
        onReady: function(s){
            var menuhandler  = exports.getComponent("soss-data");
            var query=[{storename:"products",search:""}];
            query.push({storename:"d_cms_carousel_dtl_v1",search:"catid:0"});
            menuhandler.services.q(query)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                bindData.products= r.result.products;
                                bindData.carousel=r.result.d_cms_carousel_dtl_v1;
                            }
                        })
                        .error(function(error){
                            bindData.products=[];
                            console.log(error.responseJSON);
            });
            
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



    exports.vue = vueData;
    exports.onReady = function(){
        
        

    }

    
});
