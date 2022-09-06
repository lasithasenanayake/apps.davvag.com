WEBDOCK.component().register(function(exports){
    var scope,validator_profile,service_handler,sossrout_handler,cropper1,routeData;
    var newFile_1,newFile_2,newFile_3,newFile_4;
    var bindData = {
        submitErrors : [],submitInfo : [],data:{},profile:{},attributes:{},
        p_image_1:"components/dock/soss-uploader/service/get/products/i_1",
        p_image_2:"components/dock/soss-uploader/service/get/products/i_2",
        p_image_3:"components/dock/soss-uploader/service/get/products/i_3",
        p_image_4:"components/dock/soss-uploader/service/get/products/i_4",
        p_image:[],
        item:{},
        items:[],
        removedItems:[]
    };

    
    
    var vueData =  {
        methods:{
            sellersiginup:function(){
                scope.submitErrors = [];
                scope.submitErrors = validator_profile.validate();
                if(!scope.submitErrors){ 
                    $("#form-details-1-1").toggle();
                    $("#form-details-1").toggle();
                }
            },
            groupListing:function(){
                bindData.data.catogory="group listing";
                $("#form-details-1-1").toggle();
                $("#form-details-1-2").toggle();
            },
            SingleListing:function(){
                bindData.data.catogory="single listing";
                $("#form-details-1-1").toggle();
                $("#form-details-2").toggle();
            }
            ,
            additem:function(i){
                var newItem = {};
                Object.assign(newItem,i);
                bindData.item={};
                bindData.items.push(newItem);
            },
            item_group:function(){
                //scope.submitErrors = [];
                //scope.submitErrors = validator_profile.validate();
                //if(!scope.submitErrors){ 
                    $("#form-details-1-2").toggle();
                    $("#form-details-2").toggle();
                //}
            },
            biosiginup:function(){
                $("#form-details-2").toggle();
                $("#form-details-3").toggle();;
            },
            stripesiginup:function(){
                $("#form-details-3").toggle();
                $("#form-details-4").toggle();;
            },
            sellingoplicy:function(){
                $("#form-details-4").toggle();
                $("#form-details-5").toggle();;
            },
            tradingoplicy:function(){
                $("#form-details-5").toggle();
                $("#form-details-6").toggle();;
            },
            removeItem:function(item){
                filteredItems = bindData.items.filter(function(i) {
                    if(item.itemid==null){
                    if(item.name!==i.name || i.price!==item.price)
                        return item;
                    }else{
                        if(item.itemid!==i.itemid)
                        return item;
                    }
                });
                if(item.itemid){
                    var newItem = {};
                    Object.assign(newItem,item);
                    bindData.removedItems.push(newItem);
                }
                bindData.items=filteredItems==null?[]:filteredItems;
            },
            editItem:function(i){
                
            },
            crope:function(id){
                cropper1.crope(1,1,function(e){
                    //console.log(e);
                    switch(id){
                        case 1:
                            bindData.p_image_1=e.data;
                            newFile_1=e.fileData;
                            break;
                        case 2:
                            bindData.p_image_2=e.data;
                            newFile_2=e.fileData;
                            break;
                        case 3:
                            bindData.p_image_3=e.data;
                            newFile_3=e.fileData;
                            break;
                        case 4:
                            bindData.p_image_4=e.data;
                            newFile_4=e.fileData;
                            break;
                    }
                    
                });
            },
            submit:submit
           
        },
        data :bindData,
        onReady: function(s){
            scope=s;
            pInstance = exports.getShellComponent("soss-routes");
            routeData = pInstance.getInputData();
            Login(routeData,function(){
                initialize();
            });
            
        },
        filters:{
            markeddown: function (value) {
                if (!value) return ''
                value = value.toString()
                return marked(unescape(value));
              },
              currency_formate:function(val){
                if (!val) return '0.00'
                return parseFloat(val).toFixed(2);
              },
              dateformate:function(v){
                  if(!v){
                      return ""
                  }else{
                    return moment(v, "MM-DD-YYYY hh:mm:ss").format('MMMM Do YYYY');
                  }
              }
        }
    }

    function Login(routeData,cb){
        if(localStorage.profile==null){
            
            location.href="#/app/userapp?u=#/app/stelup_shop/itemonboard";
            return;
        }else{
            bindData.profile=JSON.parse(localStorage.profile);
            //bindData.p_image="components/dock/soss-uploader/service/get/profile/"+bindData.data.id.toString();
            if(bindData.profile.catogory==null){
                location.href="#/app/stelup_shop/selleronboard?u=#/app/stelup_shop/itemonboard"
                return;
            }
            if(bindData.profile.catogory.toLowerCase()=='vender'){
                bindData.attributes.country=bindData.profile.country;
                
                cb();
                
            }else{
                location.href="#/app/stelup_shop/selleronboard?u=#/app/stelup_shop/itemonboard"
                return;
            }
            
        }
    }

    function initialize(){
        
        $("#form-details-0").toggle();
        $("#form-details-1").toggle();
        service_handler = exports.getComponent("app-handler");
        if(!service_handler){
            console.log("Service has not Loaded please check.")
        }
        exports.getAppComponent("davvag-tools","davvag-img-cropper", function(cropper){
            cropper.initialize(300,300);
            cropper1=cropper;
        });
        pInstance = exports.getShellComponent("soss-routes");
        routeData = pInstance.getInputData();
        loadValidator();
        if(routeData.id){
            loadInitialData(routeData);
        }
    }

    function loadInitialData(routeData){
        if(routeData.id!=null){
            service_handler.services.Product({itemid:routeData.id}).then(function(r){
                if(r.success){
                    //if(r.result.products!=null){
                    bindData.data = r.result;
                    //$("#txtcaption").data("editor").html(bindData.product.caption);
                    if(r.result.attributes.length!=0)
                        bindData.attributes=r.result.attributes[0];
                    else
                        bindData.attributes={};
                    
                        bindData.items=r.result.groupItems?r.result.groupItems:[];
                        bindData.p_image_1="components/dock/soss-uploader/service/get/products/"+bindData.data.itemid.toString()+"_1";
                        bindData.p_image_2="components/dock/soss-uploader/service/get/products/"+bindData.data.itemid.toString()+"_2";
                        bindData.p_image_3="components/dock/soss-uploader/service/get/products/"+bindData.data.itemid.toString()+"_3";
                        bindData.p_image_4="components/dock/soss-uploader/service/get/products/"+bindData.data.itemid.toString()+"_4";
                    //}

                }
            }).error(function(e){
                console.log(e);
            });
        }
    }

    function imageList(){
        bindData.p_image=[];
        if(newFile_1){
            bindData.p_image.push({id:0,name:"1",caption:"",default_img:"Y"});
        }
        if(newFile_2){
            bindData.p_image.push({id:0,name:"2",caption:"",default_img:"N"});
        }

        if(newFile_3){
            bindData.p_image.push({id:0,name:"3",caption:"",default_img:"N"});
        }

        if(newFile_4){
            bindData.p_image.push({id:0,name:"4",caption:"",default_img:"N"});
        }
        return bindData.p_image;
    }
    var uploader;
    function uploadFile(productId, cb){
        var files=[];
        if(newFile_1){
            newFile_1.name="1";
            files.push(newFile_1);
        }
        if(newFile_2){
            newFile_2.name="2";
            files.push(newFile_2);
        }

        if(newFile_3){
            newFile_3.name="3";
            files.push(newFile_3);
        }

        if(newFile_4){
            newFile_4.name="4";
            files.push(newFile_4);
        }
        if (files.length==0)cb();
        else{
            exports.getAppComponent("davvag-tools","davvag-file-uploader", function(_uploader){
                uploader=_uploader;
                uploader.initialize();
                uploader.upload(files, "products", productId,cb);
            });
        }
    }
    function draftDefaults(){
        bindData.data.Images=imageList();
        //bindData.data.catogory="listing";
        bindData.data.currencycode="GBP";
        bindData.data.uom="Units";
        bindData.invType="Inventory";
        bindData.showonstore="N";
        bindData.data.RemoveImages=[];
    }

    function submit(){
        lockForm();
        scope.submitErrors = [];
        scope.submitErrors = validator_profile.validate(); 
        if (!scope.submitErrors){
            lockForm();
            scope.submitErrors = [];
            scope.submitInfo=[];
            bindData.data.Images=imageList();
            bindData.data.RemoveImages=[];
            
            bindData.data.currencycode="GBP";
            bindData.data.uom="Units";
            bindData.data.invType="Inventory";
            bindData.data.showonstore="Y";
            bindData.data.imgurl="1";
            bindData.data.storeid=bindData.profile.id;
            bindData.data.storename=bindData.profile.name;
            bindData.data.attributes=bindData.attributes;
            bindData.data.groupItems=bindData.items;
            bindData.data.removedItems=bindData.removedItems;
            
            service_handler.services.SaveProduct(bindData.data).then(function(result){
                
                console.log(result);
                
                if(result.success){
                    uploadFile(result.result.itemid,function(){
                        scope.submitInfo.push("Saved Successfully.");
                        
                        location.href="#/app/stelup_shop";
                        
                    });
                    //scope.submitInfo.push("result.result.message");
                }else{
                    scope.submitErrors.push("Error");
                }
                unlockForm();
            }).error(function(result){
                scope.submitErrors = [];
                bindData.submitErrors.push("Error");
                unlockForm();
            });

        }
    }

    function navigateBack(){

    }

    

    function lockForm(){
        $("#form-details :input").prop("disabled", true);
        $("#form-details :button").prop("disabled", true);
    }

    function unlockForm(){
        $("#form-details :input").prop("disabled", false);
        $("#form-details :button").prop("disabled", false);
    }

    function loadValidator(){
        var validatorInstance = exports.getShellComponent ("soss-validator");

        validator_profile = validatorInstance.newValidator (scope);
        validator_profile.map ("data.name",true, "Please enter your full name");
        //validator_profile.map ("data.contactno",true, "Please enter your contact number");
        //validator_profile.map ("data.contactno","numeric", "Phone number should only consist of numbers");
        ///validator_profile.map ("data.contactno","minlength:9", "Phone number should consit of 10 numbers");
        //validator_profile.map ("data.country",true, "Please pick your country");
        //validator_profile.map ("data.country",true, "Please pick your country");

        
        
    }

    exports.vue = vueData;
    exports.onReady = function(element){
        
    }

});
