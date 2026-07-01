WEBDOCK.component().register(function(exports){
    var bindData = {
        submitErrors: undefined,
        SearchItem:"",
        SearchColumn:"name",
        items:[],
        image:'',
        currentPage: 1,
        pageSize: 24,
        totalPages: 1,
        loadingPage: false,
        isSearching: false,
        totalItems: 0
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
                bindData.currentPage = 1;
                handler.services.ProductSearch({"column":bindData.SearchColumn,"value":bindData.SearchItem}).then(function(results){
                    bindData.items = (results.result || []).map(mapProduct);
                    bindData.totalItems = bindData.items.length;
                    bindData.totalPages = 1;
                }).error(function(){
                    $.notify("Unable to search products right now.", "error");
                });
            },
            resetList: function(){
                bindData.SearchItem = "";
                bindData.currentPage = 1;
                bindData.isSearching = false;
                bindData.totalItems = 0;
                bindData.totalPages = 1;
                searchItems();
            },
            goToPage: function(page){
                if (bindData.loadingPage || bindData.isSearching) {
                    return;
                }
                if (page < 1 || page > bindData.totalPages || page === bindData.currentPage) {
                    return;
                }
                bindData.currentPage = page;
                searchItems();
            },
            nextPage: function(){
                if (bindData.currentPage < bindData.totalPages) {
                    vueData.methods.goToPage(bindData.currentPage + 1);
                }
            },
            prevPage: function(){
                if (bindData.currentPage > 1) {
                    vueData.methods.goToPage(bindData.currentPage - 1);
                }
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
        searchItems();
    }

    function searchItems(){
        bindData.loadingPage = true;
        handler.services.allProducts({
            page: ((bindData.currentPage - 1) * bindData.pageSize).toString(),
            size: bindData.pageSize.toString(),
            q: bindData.SearchItem || ""
        })
        .then(function(response){
            if(response.success){
                var payload = normalizePayload(response.result);
                var results = payload.items;
                bindData.items = results.map(mapProduct);
                bindData.totalItems = payload.total;
                bindData.totalPages = Math.max(1, Math.ceil(bindData.totalItems / bindData.pageSize));
            }else{
                $.notify(response.error || "Unable to load products.", "error");
            }
            bindData.loadingPage = false;
        })
        .error(function(error){
            bindData.loadingPage = false;
            $.notify(error && error.responseJSON ? error.responseJSON.result : "Unable to load products.", "error");
        });
    }

    function normalizePayload(result){
        if (Array.isArray(result)) {
            return {
                items: result,
                total: result.length
            };
        }
        if (result && Array.isArray(result.items)) {
            return {
                items: result.items,
                total: typeof result.total === "number" ? result.total : result.items.length
            };
        }
        return {
            items: [],
            total: 0
        };
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
