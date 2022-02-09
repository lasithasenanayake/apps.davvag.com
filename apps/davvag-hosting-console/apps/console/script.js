WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},files:[]
    };

    var vueData =  {
        methods:{
            render:function(){
                exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_uploader){
                    apploader=_uploader;
                    apploader.initialize();
                    apploader.RenderHTML($("#dock-console"),function(e){
                        
                    });
                });
            }
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    function initialize(){
        service_handler = exports.getComponent("hosting-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        exports.getAppComponent("davvag-tools","davvag-app-downloader", function(_uploader){
            apploader=_uploader;
            apploader.initialize();
            apploader.RenderHTML($("#dock-console"),function(e){

            });
        });
    }

    
    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
