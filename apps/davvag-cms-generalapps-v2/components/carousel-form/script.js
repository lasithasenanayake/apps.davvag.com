WEBDOCK.component().register(function(exports){
    var scope;
    var handler;
    var pInstance, validatorInstance;
    var routeData;
    var slideIndex = 1;
    var cropper1;
    var bindData = {
        product:{catid:0,carouselitems:[]},
        parentButtons:[],
        content:"",
        newEntery:true,
        carousel:{},
        submitErrors :[],
        carouselitems:[]

    };

    var vueData =   {
        methods: {
            submit: submit,
            gotoUom: gotoUom,
            plusSlides:plusSlides,
            showSlides:showSlides,
            currentSlide:currentSlide,
            remove:function(i){
                filteredItems = bindData.carouselitems.filter(function(item) {
                    if(item.imgurl!==i.imgurl)
                        return item;
                });
                scope.carouselitems=filteredItems==null?[]:filteredItems;
                showSlides(scope.carouselitems.length-1);
            },
            crope:function(){
                if(bindData.product.c_hight>=bindData.product.c_width){
                    hight=bindData.product.c_hight/bindData.product.c_width;
                    width=bindData.product.c_width/bindData.product.c_width;
                }else{
                    hight=bindData.product.c_hight/bindData.product.c_hight;
                    width=bindData.product.c_width/bindData.product.c_hight;
                }
                
                cropper1.crope(width,hight,function(e){
                    //console.log(e);
                    bindData.carousel.imgurl=e.data;
                    bindData.carousel.newFile=e.fileData;
                });
            },
            openForm:function(){
                if(cropper1){
                    $('#carousel-uploader').modal('show');
                }else{
                    exports.getAppComponent("davvag-tools","davvag-img-cropper", function(cropper){
                        cropper.initialize(bindData.product.c_width,bindData.product.c_hight);
                        cropper1=cropper;
                        $('#carousel-uploader').modal('show');
                    });
                }
               
            },
            addItem:function(i){
                if(!bindData.carouselitems){bindData.carouselitems=[]}
                bindData.carouselitems.push({id:bindData.carouselitems.length,catid:bindData.product.catid,title:i.title,
                    caption:i.caption,button_caption:i.button_caption,
                    button_url:i.button_url,imgurl:bindData.carousel.imgurl,newFile:bindData.carousel.newFile});
                
                $('#carousel-uploader').modal('hide');
                showSlides(scope.carouselitems.length-1);
            },
            selectStoreClose:function(){$('#carousel-uploader').modal('hide');},
            navigateBack: function(){
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
            }
        },
        data : bindData,
        onReady : function(s){
            scope = s;
            handler = exports.getComponent("cms-gapp-handler");
            pInstance = exports.getShellComponent("soss-routes");
            validatorInstance = exports.getShellComponent ("soss-validator");
            routeData = pInstance.getInputData();
            loadValidator();
            var menuhandler  = exports.getShellComponent("soss-data");
            var query=[];
            catid=0
            if(routeData.id){
                catid=routeData.id;
                bindData.product={catid:routeData.id,carouselitems:[]};
            }
                
            query=[{storename:"d_cms_carousel_v1",search:"catid:"+catid.toString()},{storename:"d_cms_carousel_dtl_v1",search:"catid:"+catid.toString()}];
            //var tmpmenu=[];
            bindData.TopButtons=[];
            menuhandler.services.q(query)
                        .then(function(r){
                            console.log(JSON.stringify(r));
                            if(r.success){
                                if(r.result.d_cms_carousel_v1.length!=0){
                                    bindData.newEntery=false;
                                    bindData.product=r.result.d_cms_carousel_v1[0];
                                }
                                if(r.result.d_cms_carousel_dtl_v1.length!=0){
                                    bindData.carouselitems=r.result.d_cms_carousel_dtl_v1;
                                    
                                }
                                
                            }
                        })
                        .error(function(error){
                            //bindData.product={title:"Artical Not found or Internal query Erorr",content:"Please refresh or navigate back"};
                            console.log(error.responseJSON);
            });
            
            //$('#editor').wysiwyg();
        }
    }

    
   

showSlides(slideIndex);
// Next/previous controls
function plusSlides(n) {
  showSlides(slideIndex += n);
}

// Thumbnail image controls
function currentSlide(n) {
  showSlides(slideIndex = n);
}

function showSlides(n) {
  var i;
  var slides = document.getElementsByClassName("mySlides");
  var dots = document.getElementsByClassName("dot");
  if(slides.length==0){
      return;
  }
  if (n > slides.length) {slideIndex = 1}
  if (n < 1) {slideIndex = slides.length}
  for (i = 0; i < slides.length; i++) {
      slides[i].style.display = "none";
  }
  for (i = 0; i < dots.length; i++) {
      dots[i].className = dots[i].className.replace(" active", "");
  }
  slides[slideIndex-1].style.display = "block";
  //slides[slideIndex-1].className.replace(" fade", "");
  dots[slideIndex-1].className += " active";
}


    var validator;
    function loadValidator(){
        validator = validatorInstance.newValidator (scope);
        validator.map ("product.catid",true, "You should enter a Catogory Not Found");
        validator.map ("product.c_hight",true, "You should enter a Hight ");
        validator.map ("product.c_width",true, "You should enter a width");
    }

    function submit(){
        $('#send').prop('disabled', true);
        scope.submitErrors = null; 
        var product =bindData.product;
        var files=[];
       product.carouselitems=bindData.carouselitems;
        if (!scope.submitErrors){
            
            var promiseObj;
            promiseObj = handler.services.SaveCarousel(product);
            
            bindData.carouselitems.forEach(element => {
                
                if(element.newFile){
                    element.imgname=product.catid+"-"+element.newFile.name;
                    element.imgurl="components/davvag-cms/soss-uploader/service/get/carousel/"+product.catid.toString()+"-"+element.newFile.name;
                    files.push(element.newFile);
                }
                
            });
            promiseObj
            .then(function(r){
                if(r.success){
                    if(files.length>0){
                        exports.getAppComponent("davvag-tools","davvag-file-uploader", function(_uploader){
                            uploader=_uploader;
                            uploader.initialize();
                            uploader.upload(files, "carousel", product.catid,function(r){
                                gotoUom();
                            });
                            bindData.product=r.result;
                        });
                    }else{
                        gotoUom();
                    }
                    
                }
            })
            .error(function(){
                $('#send').prop('disabled', false);
            });
        }else{
            $('#send').prop('disabled', false);
        }
    }

    function gotoUom(){
        handler1 = exports.getShellComponent("soss-routes");
        handler1.appNavigate("../catall");
    }


    exports.vue = vueData;
    exports.onReady = function(element){
        
    }
});
