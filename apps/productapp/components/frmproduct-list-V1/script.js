WEBDOCK.component().register(function(exports){
    var bindData = {
        submitErrors: undefined,
        SearchItem:"",
        SearchColumn:"name",
        items:[],
        image:'',
        page: 0,
        pageSize: 12,
        hasMore: true,
        loadingMore: false,
        isSearching: false
    };

    var vueData = {
        onReady: function(s){
            initializeComponent();
        },
        data:bindData,
        methods: {
            searchItems:searchItems,
            search:function(){
                bindData.isSearching = bindData.SearchItem !== "";
                handler.services.ProductSearch({"column":bindData.SearchColumn,"value":bindData.SearchItem}).then(function(results){
                    bindData.items = (results.result || []).map(mapProduct);
                    bindData.hasMore = false;
                }).error(function(){
                    $.notify("Unable to search products right now.", "error");
                });
            },
            resetList: function(){
                bindData.SearchItem = "";
                bindData.page = 0;
                bindData.hasMore = true;
                bindData.isSearching = false;
                searchItems(true);
            },
            loadMore: function(){
                if (bindData.loadingMore || !bindData.hasMore || bindData.isSearching) {
                    return;
                }
                bindData.loadingMore = true;
                bindData.page += bindData.pageSize;
                searchItems(false);
            },
            navigate: function(id){
                handler = exports.getShellComponent("soss-routes");
                handler.appNavigate(id ? "/product?productid=" + id : "/product");
            },
            navigatePublish: function(id){
                handler = exports.getShellComponent("soss-routes");
                handler.appNavigate(id ? "/publish?productid=" + id : "/product");
            },
            deleteProduct:deletepr

        }
    }

    function deletepr(e){
        var handler = exports.getComponent("product");

        handler.services.Delete(e).then(function(result){
            if(result.success){
                let filteredItems = bindData.items.filter(function(item) {
                    return item.itemid !== result.result.itemid;
                });
                bindData.items = filteredItems || [];
            }else{
                $.notify("Unable to delete product right now.", "error");
            }
        }).error(function(){
            $.notify("Unable to delete product right now.", "error");
        });
    }
    exports.vue = vueData;
    exports.onReady = function(element){
    }
    //var catogoryid ={"Staff",""};
    //var item ={};
    
    var handler;

    function initializeComponent(){
        handler = exports.getComponent("product");
        searchItems(true);
    }

    function searchItems(reset){
        handler.services.allProducts({
            page: bindData.page.toString(),
            size: bindData.pageSize.toString(),
            q: bindData.SearchItem || ""
        })
        .then(function(response){
            if(response.success){
                var results = response.result || [];
                var mappedProducts = results.map(mapProduct);
                bindData.items = reset ? mappedProducts : bindData.items.concat(mappedProducts);
                bindData.hasMore = results.length === bindData.pageSize;
            }else{
                $.notify(response.error || "Unable to load products.", "error");
            }
            bindData.loadingMore = false;
        })
        .error(function(error){
            bindData.loadingMore = false;
            if (!reset) {
                bindData.page = Math.max(0, bindData.page - bindData.pageSize);
            }
            $.notify(error && error.responseJSON ? error.responseJSON.result : "Unable to load products.", "error");
        });
    }

    function mapProduct(element){
        return {
            name:element.name,
            itemid:element.itemid,
            image: element.imgurl ? "components/dock/soss-uploader/service/get/products/" + element.itemid + "-" + element.imgurl : "assets/productapp/appicon.png",
            description:element.description,
            price:element.price,
            imgurl:element.imgurl,
            uom:element.uom,
            catogory: element.catogory,
            caption: element.caption,
            currencycode: element.currencycode
        };
    }


});
