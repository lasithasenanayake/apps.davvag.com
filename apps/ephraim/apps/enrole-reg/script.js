WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,routeData,intervalId;

    var bindData = {
        submitErrors : [],submitInfo : [],data:{},day:0,year:0,month:0,hour:0,mininute:0,seconds:0,project:{}
    };

    var DateDiff = {
 
        inDays: function(d1, d2) {
            var t2 = d2.getTime();
            var t1 = d1.getTime();
     
            return Math.floor((t2-t1)/(24*3600*1000));
        },
        inHours: function(d1, d2) {
            var t2 = d2.getTime();
            var t1 = d1.getTime();
     
            return Math.floor((t2-t1)/(3600*1000))-(Math.floor((t2-t1)/(24*3600*1000))*24);
        },
        inMinutes: function(d1, d2) {
            var t2 = d2.getTime();
            var t1 = d1.getTime();
     
            return Math.floor((t2-t1)/(60*1000))-(Math.floor((t2-t1)/(24*3600*1000))*86400);
        }, inSeconds: function(d1, d2) {
            var t2 = d2.getTime();
            var t1 = d1.getTime();
     
            return Math.floor((t2-t1)/(1*1000))-(Math.floor((t2-t1)/(24*3600*1000))*86400);
        },
     
        inWeeks: function(d1, d2) {
            var t2 = d2.getTime();
            var t1 = d1.getTime();
     
            return parseInt((t2-t1)/(24*3600*1000*7));
        },
     
        inMonths: function(d1, d2) {
            var d1Y = d1.getFullYear();
            var d2Y = d2.getFullYear();
            var d1M = d1.getMonth();
            var d2M = d2.getMonth();
     
            return (d2M+12*d2Y)-(d1M+12*d1Y);
        },
     
        inYears: function(d1, d2) {
            return d2.getFullYear()-d1.getFullYear();
        }
    }
     

    var vueData =  {
        methods:{
            submit:submit
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            initialize();
        }
    }

    function initialize(){
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.");
        }

        if(routeData.pid!=null){
            //intervalId=setInterval(CountDown,1000);
            service_handler.services.Project({pid:routeData.pid}).then(function(result){
                if(result.success){
                    bindData.project=result.result;
                    intervalId=setInterval(CountDown,1000);
                }else{
                    bindData.submitErrors.push("Error: Project Not found");
                    lockForm();
                    $("#form-details-4").toggle();
                    $("#form-details-1").toggle();
                }

            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                lockForm();
                $("#form-details-4").toggle();
                $("#form-details-1").toggle();
            });
        }else{
            $("#form-details-4").toggle();
            $("#form-details-1").toggle();
        }
        //exports.Complete({});
        loadValidator();
    }

    function CountDown(){
        dString=bindData.project.end_date;
        var d1 = new Date();
        var d2 = new Date(dString);
        bindData.day=DateDiff.inDays(d1,d2);
        if(bindData.day<0){
            $("#form-details-3").toggle();
            $("#form-details-1").toggle();
            clearInterval(intervalId);
        }
        bindData.year=DateDiff.inYears(d1,d2);
        bindData.month=DateDiff.inMonths(d1,d2);
        bindData.hour=DateDiff.inHours(d1,d2);
        bindData.mininute=DateDiff.inMinutes(d1,d2);
        bindData.seconds=DateDiff.inSeconds(d1,d2);
    }

    function submit(){
        
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if(routeData!=null){
            if(routeData.ref!=null)
                bindData.data.referelid=routeData.ref;
            if(routeData.pid!=null){
                bindData.data.projectid=routeData.pid;
            }

        }
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            service_handler.services.Save(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    //exports.Complete(result.result);
                    bindData.data=result.result;
                    scope.submitInfo.push("Created Successfully.");
                    $("#form-details-2").toggle();
                    $("#form-details-1").toggle();
                }else{
                    scope.submitErrors.push("Error");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                unlockForm();
            });

        }else{
            unlockForm();
        }
    }

    function navigateBack(){

    }

    

    function lockForm(){
        $("#form-details-1 :input").prop("disabled", true);
        $("#form-details-1 :button").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details-1 :input").prop("disabled", false);
        $("#form-details-1 :button").prop("disabled", false);
    }

    function loadValidator(){
        var validatorInstance = exports.getShellComponent ("soss-validator");

        validator_profile = validatorInstance.newValidator (scope);
        validator_profile.map ("data.name",true, "Please enter your full name");
        validator_profile.map ("data.email",true, "Please enter your email");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        initialize();
    }

});
