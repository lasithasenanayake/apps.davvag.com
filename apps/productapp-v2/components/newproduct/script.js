WEBDOCK.component().register(function(exports){
    var pInstance;
    var routeData;
    var validatorInstance;
    var validator;
    var handler,attribute,cropper1,producthandler,uploaderInstance,uploader,editor;
    var newfiles;

    function defaultProduct(){
        return {
            uom:"",
            invType:"",
            currencycode:"",
            catogory:"",
            attributes:{"temp":"aaaa"},
            showonstore:"Y",
            sellstype:"se",
            cost:0,
            price:0,
            discountper:0,
            qty:0,
            reorder_qty:0
        };
    }

    var bindData = {
        product:defaultProduct(),
        image:'',
        files:null,
        p_image:[],
        categories:[],
        uoms: [],
        currencies: [],
        submitErrors: undefined,
        p_removed:[],
        imageSize:{width:450,hieght:500}
    };

    var vueData = {
        onReady: function(s){
            initializeComponent();
        },
        data:bindData,
        methods: {
            submit:submit,
            clear:clearProfile,
            searchItems:searchItems,
            createImage:createImage ,
            removeImage: removeImage,
            changeType:changType,
            crop:function(){
                if(!cropper1 || typeof cropper1.crope !== "function"){
                    $.notify("Image cropper is still loading.", "warn");
                    return;
                }
                cropper1.crope(bindData.imageSize.width,bindData.imageSize.hieght,function(e){
                    newfiles=newfiles?newfiles:[];
                    bindData.p_image.push({id:0,name:e.fileData.name,scr:e.data,file:e.fileData});
                    newfiles.push(e.fileData);
                });
            },
            onFileChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                this.createImage(files[0]);
            },
            onFileMultiChange: function(e) {
                var files = e.target.files || e.dataTransfer.files;
                if (!files.length)
                    return;
                createImageMulti(files);
            },
            navigateBack: function(){
                var routeHandler = exports.getShellComponent("soss-routes");
                routeHandler.appNavigate("..");
            }
        }
    }

    exports.deferredVue = function(resolver, renderDiv){
        var attributes = exports.getShellComponent("dynamic-attributes");
        attributes.renderForm("productattribute","product.attributes",renderDiv,"AttributeText",function(){
            resolver(vueData);
        });
    };


    function changType(sellstype){
        console.log(sellstype);
        var sellstypeElement = document.getElementById("settings-sellstype");
        if(!sellstypeElement){
            return;
        }
        sellstypeElement.innerHTML = "";
        if(!sellstype){
            return;
        }
        attribute.renderForm("attr_"+sellstype,"settings-sellstype",{itemid:bindData.product.itemid},function(){
            //initiate();
            switch(sellstype){
                case "":
                    createTab();
                    break;
            }
        });
        
    }

    function createTab(){

    }

    exports.onReady = function(element){
    }
    
    function loadCurrencies(){
        exports.getAppComponent("currency-configuration", "currency-configuration-handler", function(handler){
            handler.loadActive(function(items){
                bindData.currencies = items;
                if(!bindData.product.currencycode){
                    handler.loadDefault(function(currency){
                        if(currency){ bindData.product.currencycode = currency.code; }
                    });
                }
            });
        });
    }

    function initializeComponent(){
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        validatorInstance = exports.getShellComponent("soss-validator");
        producthandler = exports.getComponent("product");
        loadCurrencies();
        uploaderInstance = exports.getShellComponent("soss-uploader");
        attribute=exports.getShellComponent("attribute_shell");
        editor=$("#txtcaption").Editor();
        loadValidator();
        
        uploaderInstance = exports.getShellComponent("soss-uploader");
        exports.getAppComponent("davvag-tools","davvag-img-cropper", function(cropper){
            cropper.initialize(300,300);
            cropper1=cropper;
            $('#carousel-uploader').modal('show');
        });
        

        loadInitialData();
        console.log(routeData);
        
    }

    
    
    var imagecount=0;
    var completed=0;    
    function uploadFile(productId, cb){
        if(!newfiles || newfiles.length===0){
            cb();
            return;
        }
        if(newfiles.length>0){
            exports.getAppComponent("davvag-tools","davvag-file-uploader", function(_uploader){
                uploader=_uploader;
                uploader.initialize();
                uploader.upload(newfiles, "products", productId,function(r){
                    $.notify("product Image Has been uploaded", "info");
                    cb();
                    newfiles=[];
                });
                //bindData.product=r.result;
            });
        }else{
            cb();
        }
            
        
    }

    function removeImage(e) {
        //const index = array.indexOf(e);
        if (e > -1) {
            if(bindData.p_image[e].id!=0){
                bindData.p_removed.push({id:bindData.p_image[e].id,name:bindData.p_image[e].name,
                    caption:bindData.p_image[e].caption,default_img:bindData.p_image[e].default_img});
            }
            bindData.p_image.splice(e, 1);
            if(newfiles && newfiles.length > e){
                newfiles.splice(e,1);
            }
        }

    }

    function createImage(file) {
        var image = new Image();
        var reader = new FileReader();

        reader.onload = function (e) {
            bindData.image = e.target.result;
        };

        reader.readAsDataURL(file);
    }

    function createImageMulti(files) {
        //console.log(JSON.stringify(files));
        //if(!newfiles){
        newfiles=newfiles?newfiles:[];
        //}
        for (var i = 0; i < files.length; i++) {
            var index = newfiles.length;
            newfiles.push(files[i]);
            getImage(index,files[i]);
            //console.log();
        }
        

        console.log(JSON.stringify(bindData.p_image));
    }

    function getImage(index,file){
        var reader = new FileReader();
            reader.onload = function (e) {
                //console.log(e);
                //console.log(newfiles);
                newfiles[index].scr=e.target.result;
                
                bindData.p_image.push({id:0,name:newfiles[index].name,scr:e.target.result,file:file});
                console.log(newfiles);
            };
        reader.readAsDataURL(file);
    }

    function clearProfile(){
        bindData.product=defaultProduct();
        bindData.image="";
        bindData.p_image=[];
        bindData.p_removed=[];
        newfiles=[];
    }

    function loadInitialData(){
        
        var menuhandler  = exports.getShellComponent("soss-data");
            var query=[{storename:"productcat",search:""},
            {storename:"uom",search:""}];
            //var tmpmenu=[];
            if(routeData.productid!=null){
                //loadProduct(bindData);
                query.push({storename:"products",search:"itemid:"+routeData.productid});
                query.push({storename:"products_attributes",search:"itemid:"+routeData.productid});
                query.push({storename:"products_image",search:"articalid:"+routeData.productid});
            }
            menuhandler.services.q(query)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                bindData.categories=[];
                                bindData.uoms=[];
                                for (var i=0;i<(r.result.productcat || []).length;i++)
                                    bindData.categories.push(r.result.productcat[i].name);
                                
                                for (var i=0;i<(r.result.uom || []).length;i++)
                                    bindData.uoms.push(r.result.uom[i]["symbol"]);
                                
                               
                               if(r.result.products && r.result.products.length!=0){
                                bindData.product = r.result.products[0];
                                $("#txtcaption").data("editor").html(bindData.product.caption);
                                if(r.result.products_attributes && r.result.products_attributes.length!=0)
                                    bindData.product.attributes=r.result.products_attributes[0];
                                else
                                    bindData.product.attributes={};
                                
                                if(bindData.product.sellstype){
                                    changType(bindData.product.sellstype);
                                }
                                bindData.image = bindData.product.imgurl ? 'components/dock/soss-uploader/service/get/products/' + bindData.product.itemid+'-'+bindData.product.imgurl : "";
                                if(r.result.products_image!=null){
                                    bindData.p_image =[];
                                    
                                    bindData.p_image =  r.result.products_image;
                                    for (var i = 0; i < bindData.p_image.length; i++) {
                                        bindData.p_image[i].scr='components/dock/soss-uploader/service/get/products/'+bindData.product.itemid+'-'+bindData.p_image[i].name;
                                    }
                                }
                                
                               }

                            }
                        })
                        .error(function(error){
                            console.log(error && error.responseJSON ? error.responseJSON : error);
            });
        

        
    }

    function loadValidator(){
        bindData.product.caption=$("#txtcaption").data("editor").html(); 
        validator = validatorInstance.newValidator (bindData);
        validator.map ("product.name",true, "You should enter a name.");
        //validator.map ("product.caption",true, "You should enter a psroduct Caption.");
        validator.map ("product.price",true, "You should endter a price.");
        validator.map ("product.price","number", "Price should be a number.");
        validator.map ("product.catogory",true, "You should select a product category.");
    }
    

    function submit(){
        $('#send').prop('disabled', true);
        bindData.submitErrors = validator.validate(); 
        if (!bindData.submitErrors){
            bindData.product.caption=$("#txtcaption").data("editor").html(); 
            bindData.product.Images=[];
            bindData.product.sellsInfo_data=attribute.get_data();
            for (var i = 0; i < bindData.p_image.length; i++) {
                bindData.product.Images.push({id:bindData.p_image[i].id,name:bindData.p_image[i].name,
                    caption:bindData.p_image[i].caption,default_img:bindData.p_image[i].default_img});
            }
            bindData.product.RemoveImages=bindData.p_removed;
            var promiseObj = producthandler.services.Save(bindData.product);
           
            

            promiseObj
            .then(function(result){
                //uploadFile(promiseObj.)
                
                uploadFile(result.result.itemid, function(){
                    gotoProducts();
                });
                
            })
            .error(function(){
                $('#send').prop('disabled', false);
            });
        }else{
            $('#send').prop('disabled', false);
        }
    }

    

    function gotoProducts(){
        //location.href = "#/admin-allproducts";
        var routeHandler = exports.getShellComponent("soss-routes");
        routeHandler.appNavigate("..");
    }

    function searchItems(columncode,columnvalue){
        return false;
    }



    //createForm(formData);

    
});
