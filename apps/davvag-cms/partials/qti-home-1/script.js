WEBDOCK.component().register(function(exports){
    var page=0,size=3;
    var routeData={},menuhandler={};
    
    var bindData={
        Articals:[],
        products:[],
        sidebar:[],
        titlepage:{name:"",title:"",caption:""},
        id:0,allloaded:false,loading:false,cards:[],profile:{}
    };
    var vueData =  {
        methods:{
            
            navProfile: function(id){
                window.location="#/app/userapp";
            },
            navDonate: function(id){
                window.location="#/app/com_qti_students/donate?id="+id.toString();
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
            try {
                bindData.profile=localStorage.profile?JSON.parse(localStorage.profile):{};
            } catch (error) {
                console.log("Error Parasng Profile");
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

    

    exports.vue = vueData;
    exports.onReady = function(){
        
        

    }

    
});
