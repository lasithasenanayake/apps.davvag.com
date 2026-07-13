WEBDOCK.component().register(function(exports){

    var vueData =  {
        methods:{
        },
        data :{
        },
        onReady: function(){
            var router = exports.getShellComponent("soss-routes");
            if(router && typeof router.appNavigate === "function"){
                router.appNavigate("#/app/profileapp.v1");
            }else{
                window.location.href = "#/app/profileapp.v1";
            }
        }
    } 

    exports.vue = vueData;
    exports.onReady = function(){}
});
