WEBDOCK.component().register(function(exports){
    var scope,Rini;
    var page=0;
    var pagesize=40;
    
    function allloadkeyword(category, skip, take){
        var handler = exports.getComponent("rpt-handler");

        handler.services.allOutstandingProfiles({page:page.toString(),size:pagesize.toString()}).then(function(result){
            scope.items = result.result;
        }).error(function(){
            
        });
    }

    var vueData = {
        methods:{navigate: function(e){
            //handler = exports.getShellComponent("soss-routes");
            //Rini.appNavigate(id ? "../broadcast?id=" + id : "../broadcast");
            window.open(location.protocol+'//'+location.host+
            location.pathname+"#/app/profileapp/view?id="+e.id.toString(), '_blank');
        }},
        data :{
            items : []
        },
        onReady: function(s){
            scope = s;
            allloadkeyword(undefined,0,100);
            Rini = exports.getShellComponent("soss-routes");
            routeData = Rini.getInputData();
            if(routeData){
                
            }else{
                
            }

        },
        filters: {
            currency: function (value) {
              if (!value) return ''
              value = value.toString()
              return parseFloat(value).toFixed(2);
            }
          
        }
    }    

    exports.vue = vueData;
    exports.onReady = function(element){
    }
});
