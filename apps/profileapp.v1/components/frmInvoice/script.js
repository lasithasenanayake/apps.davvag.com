WEBDOCK.component().register(function(exports){
    function emptyInvoiceItem(){
        return {
            itemid:0,
            name:"",
            uom:"",
            qty:0,
            price:parseFloat("0").toFixed(2),
            subtotal:0,
            discount_percentage:0,
            discount:0,
            total:parseFloat("0").toFixed(2),
            selected:null,
            invtype:"",
            catogory:"",
            notes:"",
            productSearch:"",
            productMatches:[],
            productSearching:false,
            productNotice:""
        };
    }

    var bindData = {
        i_profile:{},
        InvItems:[emptyInvoiceItem()],
        products:[],
        taxes:[{id:0, code:"NO_TAX", name:"No Tax", rate:0, isDefault:"Y"}],
        selectedTax:null,
        currencyConfig:{code:""},
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
        bindData.total= parseFloat(parseFloat(bindData.subtotal)+parseFloat(bindData.taxamount)-parseFloat(bindData.discount)).toFixed(2);
       
    }

    function removerow(){
        var arr = [];

        bindData.InvItems.forEach(element => {
            ensureInvoiceItemState(element);
            if(element.itemid!=0 || (element.productSearch && element.productSearch.trim() !== "")){
                arr.push(element);
            }
        });
        bindData.InvItems=arr;
        bindData.InvItems.push(emptyInvoiceItem());
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
            productInput:productInput,
            productFocus:productFocus,
            productBlur:productBlur,
            productSelect:productSelect,
            productSelectFirst:productSelectFirst,
            itemsDiscount:function(item){
                var subtotal=parseFloat(item.price)*parseFloat(item.qty);
               item.subtotal=subtotal;
               item.discount_percentage=(item.discount/item.subtotal)*100;
               item.total=subtotal-item.discount;
               calcTotals();
            },
            itemselect:function(item){
                applyProductSelection(item,item.selected);
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
            taxSelect:function(){
                applySelectedTax();
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
        }
    }
    
    exports.vue = vueData;
    exports.onReady = function(element){
    }
    var productHandler;
    var profileHandler;
    var uploaderInstance;
    var pInstance;
    var sossdata;
    var productSearchTimer=null;
    var productsDownloadedFromDatabase=false;
    var productsDownloading=false;

   
    function numberValue(value){
        var amount=parseFloat(value || 0);
        return isNaN(amount) ? 0 : amount;
    }

    function productLabel(product){
        if(!product){
            return "";
        }
        return product.name || product.caption || ("Product #" + product.itemid);
    }

    function productSearchText(product){
        return [
            productLabel(product),
            product.caption || "",
            product.keywords || "",
            product.catogory || "",
            product.itemid || ""
        ].join(" ").toLowerCase();
    }

    function ensureInvoiceItemState(item){
        if(!item){
            return emptyInvoiceItem();
        }
        if(item.productSearch === undefined || item.productSearch === null){
            item.productSearch = item.name || "";
        }
        if(!item.productMatches){
            item.productMatches = [];
        }
        if(item.productSearching === undefined){
            item.productSearching = false;
        }
        if(item.productNotice === undefined){
            item.productNotice = "";
        }
        return item;
    }

    function mergeProducts(products){
        products = products || [];
        var existing = {};
        bindData.products.forEach(function(product){
            if(product && product.itemid !== undefined){
                existing[product.itemid] = true;
            }
        });
        products.forEach(function(product){
            if(product && product.itemid !== undefined && !existing[product.itemid]){
                bindData.products.push(product);
                existing[product.itemid] = true;
            }
        });
    }

    function updateProductMatches(item){
        ensureInvoiceItemState(item);
        var term = (item.productSearch || "").toString().trim().toLowerCase();
        if(term === ""){
            item.productMatches = [];
            item.productNotice = "";
            return;
        }

        item.productMatches = bindData.products.filter(function(product){
            return productSearchText(product).indexOf(term) >= 0;
        }).slice(0,12);

        if(item.productMatches.length > 0){
            item.productNotice = "";
        }
    }

    function clearProductSelection(item){
        item.selected=null;
        item.itemid=0;
        item.name="";
        item.qty=0;
        item.price=parseFloat("0").toFixed(2);
        item.uom="";
        item.total=parseFloat("0").toFixed(2);
        item.invtype="";
        item.catogory="";
        item.subtotal=0;
        item.discount_percentage=0;
        item.discount=0;
    }

    function applyProductSelection(item,product){
        ensureInvoiceItemState(item);
        if(product && product !== ""){
            item.selected=product;
            item.itemid=product.itemid;
            item.name=productLabel(product);
            item.qty=0;
            item.price=numberValue(product.price).toFixed(2);
            item.subtotal=0;
            item.discount_percentage=0;
            item.discount=0;
            item.total=parseFloat("0").toFixed(2);
            item.uom=product.uom || "";
            item.invtype=product.invType || "";
            item.catogory=product.catogory || "";
            item.productSearch=productLabel(product);
            item.productMatches=[];
            item.productNotice="";
        }else{
            clearProductSelection(item);
        }

        calcTotals();
    }

    function productInput(item,event){
        ensureInvoiceItemState(item);
        item.productSearch = event && event.target ? event.target.value : (item.productSearch || "");
        if(item.selected && item.productSearch !== productLabel(item.selected)){
            clearProductSelection(item);
        }
        updateProductMatches(item);
        if((item.productSearch || "").trim().length >= 2 && item.productMatches.length === 0){
            scheduleProductDownload(item);
        }
    }

    function productFocus(item){
        ensureInvoiceItemState(item);
        updateProductMatches(item);
    }

    function productBlur(item){
        setTimeout(function(){
            ensureInvoiceItemState(item);
            item.productMatches=[];
            calcTotals();
        },200);
    }

    function productSelect(item,product){
        applyProductSelection(item,product);
    }

    function productSelectFirst(item){
        ensureInvoiceItemState(item);
        if(item.productMatches && item.productMatches.length > 0){
            productSelect(item,item.productMatches[0]);
        }else if((item.productSearch || "").trim().length >= 2){
            scheduleProductDownload(item);
        }
    }

    function scheduleProductDownload(item){
        ensureInvoiceItemState(item);
        if(productsDownloadedFromDatabase){
            item.productNotice="Product not found in database.";
            return;
        }
        item.productNotice="Searching products...";
        if(productSearchTimer){
            clearTimeout(productSearchTimer);
        }
        productSearchTimer=setTimeout(function(){
            downloadProductsFromDatabase(item);
        },350);
    }

    function productQueryService(preferNoCache){
        if(preferNoCache && profileHandler && profileHandler.services && typeof profileHandler.services.q === "function"){
            return profileHandler.services;
        }
        if(sossdata && sossdata.services && typeof sossdata.services.q === "function"){
            return sossdata.services;
        }
        return profileHandler.services;
    }

    function loadStoreProducts(){
        productQueryService(false).q([{storename:"products",search:"showonstore:Y"}])
        .then(function(r){
            console.log(JSON.stringify(r));
            if(r.success && r.result.products){
                mergeProducts(r.result.products);
            }
        })
        .error(function(error){
            console.log(error && error.responseJSON ? error.responseJSON : error);
        });
    }

    function downloadProductsFromDatabase(item){
        ensureInvoiceItemState(item);
        if(productsDownloading || productsDownloadedFromDatabase){
            updateProductMatches(item);
            if(item.productMatches.length === 0){
                item.productNotice="Product not found in database.";
            }
            return;
        }
        productsDownloading=true;
        item.productSearching=true;
        item.productNotice="Searching products...";
        productQueryService(true).q([{storename:"products",search:"",nocache:true}])
        .then(function(r){
            productsDownloading=false;
            productsDownloadedFromDatabase=true;
            item.productSearching=false;
            if(r.success && r.result.products){
                mergeProducts(r.result.products);
                updateProductMatches(item);
                if(item.productMatches.length === 0){
                    item.productNotice="Product not found in database.";
                }
            }else{
                item.productNotice="Unable to load products.";
            }
        })
        .error(function(error){
            productsDownloading=false;
            item.productSearching=false;
            item.productNotice="Unable to load products.";
            console.log(error && error.responseJSON ? error.responseJSON : error);
        });
    }


    function initializeComponent(){
        pInstance = exports.getShellComponent("soss-routes");
        var routeData = pInstance.getInputData();
        profileHandler = exports.getComponent("profile");
        sossdata = exports.getShellComponent("soss-data");
        loadInvoiceTaxes();
        loadCurrencyConfig();
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
        loadStoreProducts();
        
        
        
        if(routeData.id!=null){
            getProfilebyID(routeData.id)
        }
    }

    function loadInvoiceTaxes(){
        profileHandler.services.InvoiceTaxes()
        .then(function(response){
            if(response.success && response.result && response.result.length){
                bindData.taxes = response.result;
                bindData.selectedTax = response.result[0];
                response.result.forEach(function(item){
                    if(item.isDefault === "Y"){
                        bindData.selectedTax = item;
                    }
                });
                applySelectedTax();
            }
        })
        .error(function(){
            $.notify("Tax mappings could not be loaded. Using no tax.", "warn");
        });
    }

    function loadCurrencyConfig(){
        profileHandler.services.CurrencyConfig()
        .then(function(response){
            if(response.success && response.result){
                bindData.currencyConfig = response.result;
            }
        });
    }

    function applySelectedTax(){
        if(bindData.selectedTax){
            bindData.tax = parseFloat(bindData.selectedTax.rate || 0);
        }
        calcTotals();
    }

    

    

    

    function fDate(d){
        var normalizedDate = normalizeDateValue(d);
        return padNumber(normalizedDate.getMonth()+1) + "-" +
            padNumber(normalizedDate.getDate()) + "-" +
            normalizedDate.getFullYear() + " " +
            padNumber(normalizedDate.getHours()) + ":" +
            padNumber(normalizedDate.getMinutes()) + ":" +
            padNumber(normalizedDate.getSeconds());
    }

    function normalizeDateValue(value){
        if (value instanceof Date && !isNaN(value.getTime())) {
            return value;
        }
        if (typeof value === "string" && value.trim() !== "") {
            var parsed = new Date(value);
            if (!isNaN(parsed.getTime())) {
                return parsed;
            }
        }
        return new Date();
    }

    function padNumber(value){
        return value < 10 ? "0" + value : "" + value;
    }

    function validate(){
        var valItem=[];
        var val=true;
        
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
                taxid:bindData.selectedTax ? bindData.selectedTax.id : 0,
                taxcode:bindData.selectedTax ? bindData.selectedTax.code : "",
                taxname:bindData.selectedTax ? bindData.selectedTax.name : "",
                taxamount:bindData.taxamount,
                currencycode:bindData.currencyConfig ? bindData.currencyConfig.code : "",
                discount:bindData.discount,
                paidamount:0,
                status:"Approved",
                detailsString:null,
                InvoiceItems:[]
            }
            bindData.InvItems.forEach(element => {
                if(element.itemid!=0){
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

            bindData.InvoiceToSave.detailsString=JSON.stringify(bindData.InvoiceToSave.InvoiceItems);
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
