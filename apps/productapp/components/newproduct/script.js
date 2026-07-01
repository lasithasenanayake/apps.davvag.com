WEBDOCK.component().register(function(exports){
    var pInstance;
    var routeData;
    var validatorInstance;
    var validator;
    var newfiles;
    var producthandler;
    var uploaderInstance;
    var editor;

    var bindData = {
        product:{uom:"",invType:"",currencycode:"",catogory:"",showonstore:"Y",attributes:{}},
        image:'',
        files:null,
        p_image:[],
        categories:[],
        uoms: [],
        submitErrors: undefined,
        p_removed:[]
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
        editor=$("#txtcaption").Editor();
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
        if (e > -1) {
            if(bindData.p_image[e].id!=0){
                bindData.p_removed.push({id:bindData.p_image[e].id,name:bindData.p_image[e].name,
                    caption:bindData.p_image[e].caption,default_img:bindData.p_image[e].default_img});
            }
            bindData.p_image.splice(e, 1);
            if (newfiles && newfiles.length > e) {
                newfiles.splice(e,1);
            }
            if (bindData.product.imgurl && bindData.p_image.every(function(image){ return image.name !== bindData.product.imgurl; })) {
                bindData.product.imgurl = "";
                bindData.image = "";
            }
        }

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
        bindData.product={uom:"",invType:"",currencycode:"",catogory:"",showonstore:"Y",attributes:{}};
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
                            if(r.success){
                                bindData.categories = [];
                                bindData.uoms = [];
                                for (var i=0;i<r.result.productcat.length;i++)
                                    bindData.categories.push(r.result.productcat[i].name);
                                
                                for (var i=0;i<r.result.uom.length;i++)
                                    bindData.uoms.push(r.result.uom[i]["symbol"]);
                                
                               
                               if(r.result.products!=null && r.result.products.length){
                                bindData.product = r.result.products[0];
                                $("#txtcaption").data("editor").html(bindData.product.caption);
                                if(r.result.products_attributes.length!=0)
                                    bindData.product.attributes=r.result.products_attributes[0];
                                else
                                    bindData.product.attributes={};
                                
                                bindData.image = 'components/dock/soss-uploader/service/get/products/' + bindData.product.itemid+'-'+bindData.product.imgurl;
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
                            $.notify("Unable to load product data.", "error");
            });
        

        
    }

    function loadValidator(){
        validator = validatorInstance.newValidator (bindData);
        validator.map ("product.name",true, "You should enter a name.");
        validator.map ("product.caption",true, "You should enter a product caption.");
        validator.map ("product.price",true, "You should endter a price.");
        validator.map ("product.price","number", "Price should be a number.");
        validator.map ("product.catogory",true, "You should select a product category.");
    }
    

    function submit(){
        $('#send').prop('disabled', true);
        bindData.product.caption=$("#txtcaption").data("editor").html();
        bindData.submitErrors = validator.validate(); 
        if (!bindData.submitErrors){
            bindData.product.caption=bindData.product.caption.split("'").join("~^");
            bindData.product.caption=bindData.product.caption.split('"').join("~*");
            bindData.product.Images=[];
            for (var i = 0; i < bindData.p_image.length; i++) {
                bindData.product.Images.push({id:bindData.p_image[i].id,name:bindData.p_image[i].name,
                    caption:bindData.p_image[i].caption,default_img:bindData.p_image[i].default_img});
            }
            bindData.product.RemoveImages=bindData.p_removed;
            var promiseObj = producthandler.services.Save(bindData.product);
           
            

            promiseObj
            .then(function(result){
                uploadFile(result.result.itemid, function(){
                    gotoProducts();
                });
                
            })
            .error(function(){
                $('#send').prop('disabled', false);
                $.notify("Unable to save product.", "error");
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
