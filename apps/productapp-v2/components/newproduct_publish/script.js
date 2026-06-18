WEBDOCK.component().register(function(exports){
    var pInstance;
    var routeData;
    var validatorInstance;
    var handler, producthandler, uploaderInstance, validator;
    var newfiles;

    function defaultProduct(){
        return {
            uom:"",
            invType:"",
            currencycode:"Rs.",
            catogory:"",
            attributes:{"temp":"aaaa"},
            tname:"green7.raha.lk",
            qty:0,
            price:0,
            discountper:0
        };
    }

    var bindData = {
        product:defaultProduct(),
        image:'',
        files:null,
        p_image:[],
        categories:[],
        uoms: [],
        submitErrors: undefined,
        p_removed:[],
        tname:"green7.raha.lk"
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

    exports.onReady = function(element){
    }
    
    function initializeComponent(){
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        validatorInstance = exports.getShellComponent("soss-validator");
        producthandler = exports.getComponent("product");
        uploaderInstance = exports.getShellComponent("soss-uploader");
        
        loadValidator();
        
        uploaderInstance = exports.getShellComponent("soss-uploader");
        
        

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
            imagecount=newfiles.length;
            completed=0;
            for (var i = 0; i < newfiles.length; i++) {
                console.log(i);

                        uploaderInstance.services.uploadFile(newfiles[i], "products", productId+"-"+newfiles[i].name )
                        .then(function(result2){
                            $.notify("product Image Has been uploaded", "info");
                            completed++;
                            if(imagecount==completed){
                                cb();
                            }
                            //cb();
                        })
                        .error(function(){
                            completed++;
                            $.notify("product Image Has not been uploaded", "error");
                            //cb();
                            if(imagecount==completed){
                                cb();
                            }
                        });
                    
                    
                    
                  
            }
            //cb();
        
    }

    function removeImage(e) {
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
            var query=[{storename:"uom",search:""}];
            //var tmpmenu=[];
            if(routeData.productid!=null){
                //loadProduct(bindData);
                query.push({storename:"product_published",search:"tid:"+routeData.productid});
                query.push({storename:"products",search:"itemid:"+routeData.productid});
                //query.push({storename:"products_attributes",search:"itemid:"+routeData.productid});
                query.push({storename:"products_image",search:"articalid:"+routeData.productid});
            }
            
            var CrossDomainQuery ={query:[{storename:"productcat",search:""}]};
            menuhandler.services.qcrossdomain(CrossDomainQuery)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            bindData.categories=[];
                            if(r.success){
                                for (var i=0;i<(r.result.productcat || []).length;i++)
                                    bindData.categories.push(r.result.productcat[i].name);
                            }
                        })
                        .error(function(error){
                            console.log(error && error.responseJSON ? error.responseJSON : error);
            });
            menuhandler.services.q(query)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                
                                
                                bindData.uoms=[];
                                for (var i=0;i<(r.result.uom || []).length;i++)
                                    bindData.uoms.push(r.result.uom[i]["symbol"]);
                                
                               
                               if(r.result.product_published!=null && r.result.product_published.length!=0){
                                    bindData.product = r.result.product_published[0];
                                    
                                    bindData.image = bindData.product.imgurl ? 'components/dock/soss-uploader/service/get/products/' + (bindData.product.itemid || bindData.product.tid)+'-'+bindData.product.imgurl : "";
                                    if(r.result.products_image!=null){
                                        bindData.p_image =[];
                                        
                                        bindData.p_image =  r.result.products_image;
                                        for (var i = 0; i < bindData.p_image.length; i++) {
                                            bindData.p_image[i].scr='components/dock/soss-uploader/service/get/products/'+(bindData.product.itemid || bindData.product.tid)+'-'+bindData.p_image[i].name;
                                        }
                                    }
                                    //getLocation();
                               }else{
                                if(r.result.products!=null && r.result.products.length!=0){
                                    bindData.product = r.result.products[0];
                                
                                    bindData.image = bindData.product.imgurl ? 'components/dock/soss-uploader/service/get/products/' + bindData.product.itemid+'-'+bindData.product.imgurl : "";
                                    if(r.result.products_image!=null){
                                        bindData.p_image =[];
                                        
                                        bindData.p_image =  r.result.products_image;
                                        for (var i = 0; i < bindData.p_image.length; i++) {
                                            bindData.p_image[i].scr='components/dock/soss-uploader/service/get/products/'+bindData.product.itemid+'-'+bindData.p_image[i].name;
                                        }
                                    }
                                    getLocation();
                                }else{
                                    var routeHandler = exports.getShellComponent("soss-routes");
                                    routeHandler.appNavigate("..");
                                }
                               }

                            }
                        })
                        .error(function(error){
                            console.log(error && error.responseJSON ? error.responseJSON : error);
            });

        
    }

    function loadValidator(){
        validator = validatorInstance.newValidator (bindData);
        validator.map ("product.name",true, "You should enter a name");
        validator.map ("product.caption",true, "You should enter a caption");
        validator.map ("product.price",true, "You should endter a price");
        validator.map ("product.qty",true, "You should endter a Quantity");
        validator.map ("product.price","number", "Price should be a number");
        validator.map ("product.qty","number", "Quantity should be a number");
        validator.map ("product.catogory",true, "You should select a product category");
    }
    function getLocation() {
        if (navigator.geolocation) {
            bindData.product.lat=-1;
            bindData.product.lon=-1;
          navigator.geolocation.getCurrentPosition(showPosition);
        } else { 
          bindData.product.lat=0;
          bindData.product.lon=0;
        }
      }
      
      function showPosition(position) {
        console.log(position);
        bindData.product.lat= position.coords.latitude;// + 
        bindData.product.lon=position.coords.longitude;
      }

    function submit(){
        $('#send').prop('disabled', true);
        bindData.submitErrors = validator.validate(); 
        if (!bindData.submitErrors){
            bindData.product.caption=(bindData.product.caption || "").split("'").join("~^");
            bindData.product.caption=bindData.product.caption.split('"').join("~*");
            bindData.product.Images=[];
            bindData.product.tid=bindData.product.itemid;
            
            for (var i = 0; i < bindData.p_image.length; i++) {
                bindData.product.Images.push({id:bindData.p_image[i].id,name:bindData.p_image[i].name,
                    caption:bindData.p_image[i].caption,default_img:bindData.p_image[i].default_img});
            }
            bindData.product.RemoveImages=bindData.p_removed;
            
            var promiseObj = producthandler.services.ProductToStore(bindData.product);
           
            

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
