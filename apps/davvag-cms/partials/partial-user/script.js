WEBDOCK.component().register(function(exports){
    
    var pInstance = exports.getShellComponent("soss-routes");
    var routeSettings = pInstance.getSettings();

    var vueData =  {
        methods:{
        },
        data :{
            appName:undefined
        },
        deferRendering : true,
        onReady: function(s, renderDiv, variables){
            var appName = variables.routeParams.username;
            //downloadApp(appName,subRoute);
        }
    } 

    exports.vue = vueData;
    exports.onReady = function(){
        
    }
    var appCallBack=null;
    

    

    
});
