WEBDOCK.component().register(function(exports){
    var scope,Rini;
    var page=0;
    var pagesize=40;
    var loading =false;
    
    function allloadkeyword(){
        var handler = exports.getComponent("rpt-handler");
        var d = new Date($('#endDatePicker').val());
        var endDate =  (d.getFullYear()+"-"+(d.getMonth()+1).toString()+"-"+d.getDate()); 
        var d = new Date($('#startDatePicker').val());
        var startDate =  (d.getFullYear()+"-"+(d.getMonth()+1).toString()+"-"+d.getDate()); 
        handler.services.CustomerEnrolment({startdate:startDate,enddate:endDate}).then(function(result){
            scope.items = result.result;
            
        }).error(function(){
            loading=false;
        });
    }

    var vueData = {
        methods:{
            showCustomers:function(item){
                //alert(item.cusids);
                let shellpopup =exports.getShellComponent("app_popup");
                shellpopup.open("lbc-dashborad-report","customers",item,function(data){
                    console.log(JSON.stringify(data));
                    shellpopup.close();
                },"Customer List",true,false);
            },
            refreshData:function(){
                allloadkeyword();
            },
            navigate: function(e){
            //handler = exports.getShellComponent("soss-routes");
            //Rini.appNavigate(id ? "../broadcast?id=" + id : "../broadcast");
            window.open(location.protocol+'//'+location.host+
            location.pathname+"#/app/profileapp/view?id="+e.id.toString(), '_blank');
        },sum:function(year,month,v){
            return scope.items[year].reduce((acc, item) => acc + parseInt(item[month][v]), 0);
        },sumFloat:function(year,month,v){
            return scope.items[year].reduce((acc, item) => acc + parseFloat(item[month][v]), 0);
        }},
        data :{
            items : [],
            startDate:'2023-1-1',
            endDate:'2023-12-31'
        },
        onReady: function(s){
            scope = s;
            $('#startDatePicker').datepicker().on('changeDate', function(ev){
                var d = new Date($('#startDatePicker').val());
                scope.startDate =  (d.getMonth()+1).toString()+"-"+d.getDate()+"-"+d.getFullYear(); 
            });

            $('#endDatePicker').datepicker().on('changeDate', function(ev){
                var d = new Date($('#endDatePicker').val());
                scope.endDate =  (d.getMonth()+1).toString()+"-"+d.getDate()+"-"+d.getFullYear(); 
            });

            allloadkeyword();
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
