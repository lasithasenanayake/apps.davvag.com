WEBDOCK.component().register(function(exports){
    var pInstance;
    var routeData;
    var validatorInstance;
    var validator;
    var newfiles;
    var producthandler;
    var uploaderInstance;

    var bindData = {
        product:{uom:"",invType:"",currencycode:"",catogory:"",tenant:"",tname:"",monday:"N",tuesday:"N",wednesday:"N",thursday:"N",friday:"N",saturday:"N",sunday:"N",timeFrom:"7:00 AM",timeTo:"6:00 PM"},
        image:'',
        files:null,
        p_image:[],
        categories:[],
        uoms: [],
        submitErrors: undefined,
        timeOptions:["7:00 AM","8:00 AM","9:00 AM","10:00 AM","11:00 AM","12:00 PM","1:00 PM","2:00 PM","3:00 PM","4:00 PM","5:00 PM","6:00 PM","7:00 PM","9:00 PM","10:00 PM","11:00 PM","12:00 AM","1:00 AM","2:00 AM","3:00 AM","4:00 AM","5:00 AM","6:00 AM"]
    };

    var vueData = {
        onReady: function(s){
            initializeComponent();
        },
        data:bindData,
        methods: {
            submit:submit,
            clear:clearProfile,
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
                var handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
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
    }

    
    
    var imagecount=0;
    var completed=0;    
    function uploadFile(productId, cb){
            if(!newfiles){
                cb();
                return;
            }
            imagecount=newfiles.length;
            completed=0;
            for (var i = 0; i < newfiles.length; i++) {
                uploaderInstance.services.uploadFile(newfiles[i], "products", productId+"-"+newfiles[i].name )
                .then(function(result2){
                    $.notify("Product image uploaded.", "info");
                    completed++;
                    if(imagecount==completed){
                        cb();
                    }
                })
                .error(function(){
                    completed++;
                    $.notify("One or more images could not be uploaded.", "error");
                    if(imagecount==completed){
                        cb();
                    }
                });
            }
    }

    function removeImage(e) {
        bindData.image = '';
    }

    function createImage(file) {
        var reader = new FileReader();
        reader.onload = function (e) {
            bindData.image = e.target.result;
        };
        reader.readAsDataURL(file);
    }

    function createImageMulti(files) {
        newfiles = newfiles || [];
        for (var i = 0; i < files.length; i++) {
            newfiles.push(files[i]);
            getImage(newfiles.length - 1,files[i]);
        }
    }

    function getImage(index,file){
        var reader = new FileReader();
            reader.onload = function (e) {
                newfiles[index].scr=e.target.result;
                
                bindData.p_image.push({id:0,name:newfiles[index].name,scr:e.target.result,file:file});
                if (!bindData.image) {
                    bindData.image = e.target.result;
                }
            };
        reader.readAsDataURL(file);
    }

    function clearProfile(){
        bindData.product={uom:"",invType:"",currencycode:"",catogory:"",tenant:"",tname:"",monday:"N",tuesday:"N",wednesday:"N",thursday:"N",friday:"N",saturday:"N",sunday:"N",timeFrom:"7:00 AM",timeTo:"6:00 PM"};
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
                            bindData.categories=[];
                            if(r.success){
                                for (var i=0;i<r.result.productcat.length;i++)
                                    bindData.categories.push(r.result.productcat[i].name);
                            }
                        })
                        .error(function(error){
            });
            menuhandler.services.q(query)
                        .then(function(r){
                            if(r.success){
                                bindData.uoms = [];
                                for (var i=0;i<r.result.uom.length;i++)
                                    bindData.uoms.push(r.result.uom[i]["symbol"]);
                                
                               
                               if(r.result.product_published!=null && r.result.product_published.length!=0){
                                    bindData.product = r.result.product_published[0];
                                    bindData.product.tenant = bindData.product.tenant || bindData.product.tname || bindData.product.domain || "";
                                    bindData.product.tname = bindData.product.tname || bindData.product.tenant;
                                    bindData.product.thursday = bindData.product.thursday || "N";
                                    
                                    bindData.image = 'components/dock/soss-uploader/service/get/products/' + bindData.product.itemid+'-'+bindData.product.imgurl;
                                    if(r.result.products_image!=null){
                                        bindData.p_image =[];
                                        
                                        bindData.p_image =  r.result.products_image;
                                        for (var i = 0; i < bindData.p_image.length; i++) {
                                            bindData.p_image[i].scr='components/dock/soss-uploader/service/get/products/'+bindData.product.itemid+'-'+bindData.p_image[i].name;
                                        }
                                    }
                                    //getLocation();
                               }else{
                                if(r.result.products!=null && r.result.products.length!=0){
                                    bindData.product = r.result.products[0];
                                    bindData.product.tenant = bindData.product.tenant || bindData.product.tname || "";
                                    bindData.product.tname = bindData.product.tname || bindData.product.tenant;
                                    bindData.product.thursday = bindData.product.thursday || "N";
                                
                                    bindData.image = 'components/dock/soss-uploader/service/get/products/' + bindData.product.itemid+'-'+bindData.product.imgurl;
                                    if(r.result.products_image!=null){
                                        bindData.p_image =[];
                                        
                                        bindData.p_image =  r.result.products_image;
                                        for (var i = 0; i < bindData.p_image.length; i++) {
                                            bindData.p_image[i].scr='components/dock/soss-uploader/service/get/products/'+bindData.product.itemid+'-'+bindData.p_image[i].name;
                                        }
                                    }
                                    getLocation();
                                }else{
                                    var handler1 = exports.getShellComponent("soss-routes");
                                    handler1.appNavigate("..");
                                }
                               }

                            }
                        })
                        .error(function(error){
                            $.notify("Unable to load publishing data.", "error");
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
            bindData.product.caption = (bindData.product.caption || "").split("'").join("~^");
            bindData.product.caption=bindData.product.caption.split('"').join("~*");
            bindData.product.Images=[];
            bindData.product.tid=bindData.product.itemid;
            bindData.product.tname = bindData.product.tenant || bindData.product.tname;
            
            for (var i = 0; i < bindData.p_image.length; i++) {
                bindData.product.Images.push({id:bindData.p_image[i].id,name:bindData.p_image[i].name,
                    caption:bindData.p_image[i].caption,default_img:bindData.p_image[i].default_img});
            }
            
            var promiseObj = producthandler.services.ProductToStore(bindData.product);
           
            

            promiseObj
            .then(function(result){
                uploadFile(result.result.itemid, function(){
                    gotoProducts();
                });
                
            })
            .error(function(){
                $('#send').prop('disabled', false);
                $.notify("Unable to publish product.", "error");
            });
        }else{
            $('#send').prop('disabled', false);
        }
    }

    

    function gotoProducts(){
        var handler1 = exports.getShellComponent("soss-routes");
        handler1.appNavigate("..");
    }
});
