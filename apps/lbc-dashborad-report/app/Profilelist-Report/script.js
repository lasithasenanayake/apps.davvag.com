WEBDOCK.component().register(function(exports){
    var scope,Rini;
    var page=0;
    var pagesize=40;
    var loading =false;
    
    function allloadkeyword(){
        if(loading)
            return;

        var handler = exports.getComponent("rpt-handler");
        loading=true;
        handler.services.allProfiles({page:page.toString(),size:pagesize.toString()}).then(function(result){
            var i;
            for (i = 0; i < result.result.length; i++) {
                //text += cars[i] + "<br>";
                scope.items.push(result.result[i]);
            }
            //scope.items = result.result;
            loading=false;
            if(result.result.length==0){
                loading=true;
            }else{
                page+=result.result.length;
            }
        }).error(function(){
            loading=false;
        });
    }

    var vueData = {
        methods:{navigate: function(e){
            //handler = exports.getShellComponent("soss-routes");
            //Rini.appNavigate(id ? "../broadcast?id=" + id : "../broadcast");
            window.open(location.protocol+'//'+location.host+
            location.pathname+"#/app/profileapp/view?id="+e.id.toString(), '_blank');
        },status:function(status){
            switch((status?status:'active').toString().toLowerCase()){
                case "tobeactive":
                    return "primary";
                break;
                case "tobeactivated":
                    return "pramary";
                    break;
                case "inactive":
                    return "warning";
                break;
                case "void":
                    return "danger";
                break;
                case "active":
                    return "success";
                break;
                default:
                    return "warning";
                break;
            }
        }
    },
        data :{
            items : []
        },
        onReady: function(s){
            scope = s;
            allloadkeyword();
            Rini = exports.getShellComponent("soss-routes");
            routeData = Rini.getInputData();
            $(".contentpanel").scroll(function(e){
                if ((e.currentTarget.clientHeight + e.currentTarget.scrollTop+30) >= e.currentTarget.scrollHeight) {
                    // you're at the bottom of the page
                    //console.log($("#mainBody").outerHeight());
                    console.log("In the event ...");
                    if(!loading){
                        //page=page+size;
                        allloadkeyword();
                        //console.log("Bottom of the page products " +bindData.products.length +" pageNumber "+page);
                    }
                }
              });
            

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
