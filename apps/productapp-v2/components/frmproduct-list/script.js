WEBDOCK.component().register(function(exports){
    var bindData = {
        submitErrors: undefined,
        SearchItem:"",
        SearchColumn:"all",
        TypeFilter:"all",
        allItems:[],
        items:[],
        loading:false,
        Message:"Loading products..."
    };

    var vueData = {
        onReady: function(){
            initializeComponent();
        },
        data:bindData,
        methods: {
            ChangePermision:changePermision,
            searchItems:applyProductFilter,
            clearSearch:clearSearch,
            refreshProducts:refreshProducts,
            navigate: function(id){
                appNavigate(id ? "product?productid=" + id : "product");
            },
            navigatePublish: function(id){
                appNavigate(id ? "publish?productid=" + id : "publish");
            },
            deleteProduct:deleteProduct,
            productImage:productImage,
            productStatus:productStatus,
            formatMoney:formatMoney
        }
    };

    exports.vue = vueData;
    exports.onReady = function(){};

    var handler;

    function initializeComponent(){
        handler = exports.getComponent("product");
        refreshProducts();
    }

    function appNavigate(path){
        var routeHandler = exports.getShellComponent("soss-routes");
        if(!routeHandler || !routeHandler.appNavigate){
            return;
        }
        routeHandler.appNavigate(normalizeRoute(path));
    }

    function normalizeRoute(path){
        path = (path || "").toString();
        while(path.indexOf("/") === 0){
            path = path.substring(1);
        }
        return path || "..";
    }

    function refreshProducts(){
        bindData.loading=true;
        bindData.Message="Loading products...";
        handler.transformers.allProducts()
        .then(function(response){
            bindData.loading=false;
            if(response.success){
                bindData.allItems=[];
                (response.result || []).forEach(function(element){
                    bindData.allItems.push(mapProduct(element));
                });
                applyProductFilter();
            }else{
                bindData.items=[];
                bindData.Message=response.error || "Unable to load products.";
                alert(bindData.Message);
            }
        })
        .error(function(error){
            bindData.loading=false;
            bindData.items=[];
            bindData.Message=error && error.responseJSON ? error.responseJSON.result : "Unable to load products.";
            alert(bindData.Message);
            console.log(error && error.responseJSON ? error.responseJSON : error);
        });
    }

    function mapProduct(element){
        element = element || {};
        return {
            name:element.name || "",
            itemid:element.itemid || "",
            image:productImage(element),
            description:element.description || "",
            caption:stripHtml(element.caption || ""),
            keywords:element.keywords || "",
            barcode:element.barcode || "",
            price:element.price,
            cost:element.cost,
            discountper:element.discountper,
            imgurl:element.imgurl || "",
            uom:element.uom || "",
            catogory:element.catogory || "",
            catogoryid:element.catogoryid || "",
            invType:element.invType || "",
            recordtype:element.recordtype,
            showonstore:element.showonstore || "",
            sellstype:element.sellstype || "",
            qty:element.qty,
            reorder_qty:element.reorder_qty,
            sysviewobject:element.sysviewobject
        };
    }

    function productImage(product){
        if(!product || !product.imgurl || !product.itemid){
            return "assets/productapp-v2/appicon.png";
        }
        return "components/dock/soss-uploader/service/get/products/" + product.itemid + "-" + product.imgurl;
    }

    function stripHtml(value){
        return value.toString().replace(/<[^>]*>/g," ").replace(/\s+/g," ").trim();
    }

    function recordValue(record,field){
        if(!record || !field){
            return "";
        }
        return record[field] === undefined || record[field] === null ? "" : record[field].toString();
    }

    function searchableText(record){
        return [
            recordValue(record,"itemid"),
            recordValue(record,"name"),
            recordValue(record,"catogory"),
            recordValue(record,"barcode"),
            recordValue(record,"uom"),
            recordValue(record,"invType"),
            recordValue(record,"showonstore"),
            recordValue(record,"sellstype"),
            recordValue(record,"keywords"),
            recordValue(record,"caption")
        ].join(" ").toLowerCase();
    }

    function applyProductFilter(){
        var term=(bindData.SearchItem || "").toString().trim().toLowerCase();
        var column=(bindData.SearchColumn || "all").toString();
        var typeFilter=(bindData.TypeFilter || "all").toString().toLowerCase();

        bindData.items=bindData.allItems.filter(function(product){
            if(typeFilter !== "all" && normalizeType(product.invType) !== typeFilter){
                return false;
            }

            if(term !== ""){
                if(column === "all"){
                    return searchableText(product).indexOf(term) >= 0;
                }
                return recordValue(product,column).toLowerCase().indexOf(term) >= 0;
            }

            return true;
        });

        bindData.Message=bindData.items.length === 0
            ? "No products found."
            : "Showing " + bindData.items.length + " of " + bindData.allItems.length + " products.";
    }

    function clearSearch(){
        bindData.SearchItem="";
        bindData.SearchColumn="all";
        bindData.TypeFilter="all";
        applyProductFilter();
    }

    function normalizeType(value){
        value=(value || "").toString().trim().toLowerCase();
        if(value === "inventry"){
            return "inventory";
        }
        return value;
    }

    function productStatus(product){
        if(!product){
            return "Not set";
        }
        return product.showonstore === "Y" ? "Store" : "Hidden";
    }

    function formatMoney(value){
        var amount=parseFloat(value || 0);
        if(isNaN(amount)){
            amount=0;
        }
        return amount.toFixed(2);
    }

    function changePermision(item){
        if(typeof openViewObject !== "function"){
            alert("Permission editor is not available.");
            return;
        }

        openViewObject(item.sysviewobject,function(data,shellpopup){
            item.sysviewobject=data;
            handler.services.Save(item)
            .then(function(result){
                if(result.success){
                    alert("Product has been updated");
                }else{
                    alert("Error changing record permission");
                }
            })
            .error(function(){
                alert("Error changing record permission");
            });
            shellpopup.close();
        });
    }

    function deleteProduct(product){
        if(!product || !product.itemid){
            return;
        }
        if(!confirm("Delete product #" + product.itemid + " - " + product.name + "?")){
            return;
        }

        handler.services.Delete(product).then(function(result){
            if(result.success){
                bindData.allItems=bindData.allItems.filter(function(item) {
                    return item.itemid !== result.result.itemid;
                });
                applyProductFilter();
            }else{
                alert("Product could not be deleted.");
            }
        }).error(function(error){
            alert("Product could not be deleted.");
            console.log(error && error.responseJSON ? error.responseJSON : error);
        });
    }
});
