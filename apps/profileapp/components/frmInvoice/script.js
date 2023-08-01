WEBDOCK.component().register(function(exports){
    var bindData = {
        i_profile:{},
        InvItems:[{itemid:0,name:"",uom:"",qty:0,price:parseFloat("0").toFixed(2),total:parseFloat("0").toFixed(2),selected:null,invtype:"",catogory:""}],
        products:[],
        subtotal:0,
        discount:0,
        tax:0,
        taxamount:0,
        total:0,
        date:new Date(),
        duedate:new Date(),
        invoiceSave:false,
        InvoiceToSave:{},
        supplierData:{}
    };

    function calcTotals(){
        removerow();
        bindData.subtotal=parseFloat(0.00);
        bindData.discount=parseFloat(0.00);
        bindData.InvItems.forEach(element => {
            bindData.subtotal+=parseFloat(element.subtotal?element.subtotal:0);
            bindData.discount+=parseFloat(element.discount?element.discount:0);
        });
        bindData.subtotal=parseFloat(bindData.subtotal).toFixed(2);
        bindData.taxamount=parseFloat(parseFloat(bindData.subtotal)*(parseFloat(bindData.tax)/100)).toFixed(2);
        bindData.total= parseFloat(parseFloat(bindData.subtotal)+parseFloat(bindData.taxamount)).toFixed(2)-parseFloat(bindData.discount).toFixed(2);
       
    }

    function removerow(){
        var additem=true;
        var arr = [];

        bindData.InvItems.forEach(element => {
            if(element.itemid==0){
             additem=false;
            }else{
                arr.push(element);
            }
        });
        bindData.InvItems=arr;
        bindData.InvItems.push({itemid:0,name:"",uom:"",qty:0,price:parseFloat("0").toFixed(2),total:parseFloat("0").toFixed(2),selected:null,invtype:"",catogory:""});
        //console.log(arr);
    }
    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data:bindData,
        methods: {
            save:saveInvoice,
            savePreview:savePreview,
            savePreviewCancel:function(){bindData.invoiceSave=false;},
            onFileChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                this.createImage(files[0]);
            },
            navigateBack: function(){
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
            },
            itemLeave:calcTotals,
            itemsDiscount:function(item){
                var subtotal=parseFloat(item.price)*parseFloat(item.qty);
               item.subtotal=subtotal;
               item.discount_percentage=(item.discount/item.subtotal)*100;
               item.total=subtotal-item.discount;
               calcTotals();
            },
            itemselect:function(item){
                //console.log(JSON.stringify(item));
                if(item.selected!==""){ 
                    var subtotal=parseFloat(item.price)*parseFloat(item.qty);
                    item.itemid=item.selected.itemid;
                    item.name=item.selected.name;
                    item.qty=0;
                    item.price=parseFloat(item.selected.price).toFixed(2);
                    item.subtotal=subtotal;                    
                    item.discount_percentage=0;
                    item.discount= subtotal*item.discount_percentage/100;
                    item.uom=item.selected.uom;
                    
                    item.invtype=item.selected.invType;
                    item.catogory=item.selected.catogory;
                    
                }else{
                    item.itemid=0;
                    item.name="";
                    item.qty=0;
                    item.price=0;
                    item.uom="";
                    item.total=0;
                    item.invtype="";
                    item.catogory="";
                    item.subtotal=0;
                    item.discount_percentage=0;
                    item.discount= 0;
                }

               calcTotals();
                
            },
            itemsQtyChange:function(item){
                var subtotal=parseFloat(item.price)*parseFloat(item.qty);
               item.subtotal=subtotal;
               item.discount= subtotal*item.discount_percentage/100;
               item.total=subtotal-item.discount;
               calcTotals();
                
            }
            ,
            taxChange:calcTotals,
            print:function(){
                var prtContent=document.getElementById("printcontent");
                var WinPrint = window.open('', '', 'left=0,top=0,width=800,height=900,toolbar=0,scrollbars=0,status=0');
                WinPrint.document.open('text/html');
                WinPrint.document.write('<link href="//netdna.bootstrapcdn.com/bootstrap/3.1.0/css/bootstrap.min.css" rel="stylesheet" id="bootstrap-css"><div style="margin: 30px;"> '+prtContent.innerHTML+'</div>');
                WinPrint.document.close();
                WinPrint.focus();
                setTimeout(function(){ WinPrint.print();WinPrint.close(); }, 3000);
            }
        }
    }
    
    exports.vue = vueData;
    exports.onReady = function(element){
    }
    var productHandler;
    var profileHandler;
    var uploaderInstance;
    var pInstance;

   

    function initializeComponent(){
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        profileHandler = exports.getComponent("profile");
        sossdata = exports.getShellComponent("soss-data");
        profileHandler.services.SupplierData().then(
            function(r){
                if(r.success){
                    bindData.supplierData=r.result;
                }else{
                    bindData.supplierData={name:"error Loading...",id:-1};
                }
            }
        ).error(function(er){
            bindData.supplierData={name:"error Loading...",id:-1};
        });
        if(routeData.tid!=null){
            var query=[{storename:"orderheader",search:"invoiceNo:"+routeData.tid},{storename:"orderdetails",search:"invoiceNo:"+routeData.tid}];
                    profileHandler.services.q(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            if(r.result.orderheader.length!=0){
                                bindData.InvoiceToSave=r.result.orderheader[0];
                                bindData.InvoiceToSave.InvoiceItems=r.result.orderdetails;
                                bindData.invoiceSave=true;
                            }
                            return;
                            //calcTotals();
                            
                        }
                    })
                    .error(function(error){
                        console.log(error.responseJSON);
            });
            //getProfilebyID(routeData.id)
        }
        var query=[{storename:"products",search:"showonstore:Y"}];
        //productHandler = exports.getComponent("product");
        sossdata.services.q(query)
                    .then(function(r){
                        console.log(JSON.stringify(r));
                        if(r.success){
                            if(r.result.products.length!=0){
                                bindData.products=r.result.products;
                               
                            }
                            return;
                            //calcTotals();
                            
                        }
                    })
                    .error(function(error){
                        console.log(error.responseJSON);
            });
        
        
        
        if(routeData.id!=null){
            getProfilebyID(routeData.id)
        }
        console.log(routeData);
    }

    

    

    

    function fDate(d){
        var datestring = (d.getMonth()+1)  + "-" + d.getDate() + "-" + d.getFullYear() + " " + d.getHours() + ":" + d.getMinutes() + ":00";
        return datestring;
    }

    function validate(){
        valItem=[];
        val=true;
        
        bindData.InvItems.forEach(element => {
            if(element.itemid!=0){
                $("#item_"+element.itemid).attr("class","");
                if(element.qty<=0){
                    $.notify("Error! '"+element.name+"' Quantity is not Valied", "error");
                    element.validate=false;
                    $("#item_"+element.itemid).attr("class","has-error");
                    val=false;
                }
                if(element.discount_percentage>100){
                    $.notify("Error! '"+element.name+"' Discount is not valied", "error");
                    element.validate=false;
                    $("#item_"+element.itemid).attr("class","has-error");
                    val=false;
                }
                if(valItem.indexOf(element.itemid) >= 0){
                    //alert("Duplicate Items Found");
                    $.notify("Error! '"+element.name+"' Duplicate Item Found", "error");
                    element.validate=false;
                    $("#item_"+element.itemid).attr("class","has-error");
                    val=false;
                }

                valItem.push(element.itemid);
            }



        });
        return val;
    }

    function savePreview(){
        //var d = ;
        
        if(validate()){
            bindData.InvoiceToSave={
                invoiceNo:0,
                invoiceDate:fDate(bindData.date),
                invoiceDueDate:fDate(bindData.duedate),
                profileId:bindData.i_profile.id,
                email:bindData.i_profile.email,
                contactno:bindData.i_profile.contactno,
                name:bindData.i_profile.name,
                address:bindData.i_profile.address,
                city:bindData.i_profile.city,
                country:bindData.i_profile.country,
                subtotal:bindData.subtotal,
                total:bindData.total,
                tax:bindData.tax,
                taxamount:bindData.taxamount,
                discount:bindData.discount,
                paidamount:0,
                status:"Approved",
                detailsString:null,
                InvoiceItems:[]
            }
            bindData.InvItems.forEach(element => {
                if(element.itemid!=0){
                    console.log(JSON.stringify(element));
                    bindData.InvoiceToSave.InvoiceItems.push(
                        {
                            invoiceNo:0,
                            itemid:element.itemid,
                            name:element.name,
                            uom:element.uom,qty:element.qty,
                            price:element.price,
                            subtotal:element.subtotal,
                            discount_percentage:element.discount_percentage,
                            discount:element.discount,
                            total:element.total,
                            invType:element.invtype,
                            catogory:element.catogory,
                            notes:element.notes
                        }
                    )
                }
            });

            bindData.InvItems.detailsString=JSON.stringify(bindData.InvoiceToSave.InvoiceItem);
            console.log(JSON.stringify(bindData.InvoiceToSave));
            bindData.invoiceSave=true;
        }
    }
    function saveInvoice(){
        $('#send').prop('disabled', true);
        //console.log(JSON.stringify(bindData.InvoiceToSave));
        //return;
        profileHandler.services.InvoiceSave(bindData.InvoiceToSave)
        .then(function(response){
            //console.log(JSON.stringify(response));
            
            if(response.success){
                //console
                $.notify("invoice Has been generated", "success");
                bindData.InvoiceToSave=response.result;
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("../invoice?tid="+bindData.InvoiceToSave.invoiceNo);
                
            }else{
                $.notify("Error! Savining Error", "error");
                console.log(JSON.stringify(response.result));
                $('#send').prop('disabled', false);
                //alert (response.result.error);
            }
        })
        .error(function(error){
            $.notify("Error! Savining Error please check your intenet connection", "error");
            console.log(JSON.stringify(error));
            $('#send').prop('disabled', false);
        });
    }

    function getProfilebyID(id){
        profileHandler.services.Search({q:"id:"+id})
        .then(function(response){
            console.log(JSON.stringify(response));
            if(response.success){
                //console
                //bindData.item.id=response.result.result.generatedId;
                bindData.showSearch=false;
                console.log(response);
                if(response.result.length!=0){
                    console.log("items chnaged");
                    bindData.i_profile=response.result[0];
                    bindData.p_image = 'components/dock/soss-uploader/service/get/profile/'+bindData.i_profile.id;
                    console.log( bindData.p_image);
                    //image
                }else{
                    clearProfile();
                }
            }else{
                alert (response.error);
            }
        })
        .error(function(error){
            alert (error.responseJSON.result);
            console.log(error.responseJSON);
        });
    }

    
});
