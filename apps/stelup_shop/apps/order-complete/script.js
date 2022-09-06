WEBDOCK.component().register(function(exports){
    bindData={header:{buttonuri:""},InvoiceToSave:sessionStorage.tmpRept?JSON.parse(sessionStorage.tmpRept):null};
    var vueData =  {
        methods:{
            navigateBack: function(){
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
            },
            print:function(){
                var prtContent=document.getElementById("printcontent");
                var WinPrint = window.open('', '', 'left=0,top=0,width=800,height=900,toolbar=0,scrollbars=0,status=0');
                WinPrint.document.open('text/html');
                WinPrint.document.write('<link href="//netdna.bootstrapcdn.com/bootstrap/3.1.0/css/bootstrap.min.css" rel="stylesheet" id="bootstrap-css"><div style="margin: 30px;"> '+prtContent.innerHTML+'</div>');
                WinPrint.document.close();
                WinPrint.focus();
                setTimeout(function(){ WinPrint.print();WinPrint.close(); }, 3000);
            }
        },
        data :bindData,
        onReady: function(s){
            var menuhandler  = exports.getComponent("soss-data");
            if(sessionStorage.blogheader){
    
                //document.title=JSON.parse(sessionStorage.blogheader).name;
                //bindData.url=r.result.buttonuri;
                bindData.header=JSON.parse(sessionStorage.blogheader);
            }else{
                var data={name:"cms-global"}
                menuhandler.services.Settings(data)
                        .then(function(r){
                            //console.log(JSON.stringify(r));
                            if(r.success){
                                bindData.header= r.result;
                                sessionStorage.blogheader=JSON.stringify(r.result)
                            }
                        })
                        .error(function(error){
                            //bindData.Articals=[];
                            console.log(error.responseJSON);
                });
            }
        }
    } 

    exports.vue = vueData;
    exports.onReady = function(){
       
    }
    
});
