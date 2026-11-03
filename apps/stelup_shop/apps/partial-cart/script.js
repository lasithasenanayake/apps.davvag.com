WEBDOCK.component().register(function(exports){
    var scope;

    var bindData =  {
            message : "Works!!!",
            items: [],
            itemsGrouped:[],
            hasOrder: false,
            user:{},
            paymentSettings:{service_fee_percent:0,enabled:false}
    };

    function unitPrice(item) {
        var price = Number(item.price) || 0;
        var discount = Number(item.discountper) || 0;
        return price - price * discount / 100;
    }

    function lineTotal(item) {
        return unitPrice(item) * (Number(item.qty) || 0);
    }

    function processItems() {
        var grouped = groupBy(bindData.items, function (item) { return item.storeid; });
        var stores = [];
        grouped.forEach(function (items, storeid) {
            stores.push({
                storeid: storeid,
                storename: items[0].storename,
                currencycode: items[0].currencycode,
                items: items,
                total: items.reduce(function (sum, item) { return sum + lineTotal(item); }, 0)
            });
        });
        bindData.itemsGrouped = stores;
        bindData.hasOrder = bindData.items.some(function (item) { return item.isOrder; });
    }

    function login(){

        if (!scope.user.email || !scope.user.password){
            toastr.error('Invalid Username or Password', 'Login error');
            return;
        }

        var handler = exports.getComponent("login-handler");
        handler.services.login({email: scope.user.email,password:scope.user.password,domain:window.location.hostname})
        .then(function(result){
            $('#idCartLogin').on('hidden.bs.modal', function () {
                location.href ="#/app/stelup_shop/checkout";
            })
            if (result.result)
                result = result.result;
            if (!result.error){
                localStorage.loginData = JSON.stringify(result);
                scope.profile = {address:{gpspoint:""},address2:{},address3:{}};
                //scope.profile.name = scope.user.name;
                scope.profile.email = scope.user.email;
                $('#idCartLogin').modal('hide');
            }else {
                toastr.error('Invalid Username or Password', 'Login error');
            }

        }).error(function(){
            toastr.error('Error logging into raha.lk.', 'Login error');
        });
    }

    var vueData =  {
       data : bindData,
       onReady: function(app,renderDiv){
           scope = app;
            exports.getComponent("stripe-api").services.PublicSettings().then(function(result){
                if(result.success){bindData.paymentSettings=result.result;}
            }).error(function(){});
            if(sessionStorage.items){
                bindData.items=JSON.parse(sessionStorage.items);
                processItems();
            }
       },
       filters: {
            money: function (value) { return (Number(value) || 0).toFixed(2); }
       },
       methods:{
            unitPrice: unitPrice,
            serviceFee: function(store){
                var code=String(store.currencycode||"gbp").toLowerCase();
                var factor=["bif","clp","djf","gnf","jpy","kmf","krw","mga","pyg","rwf","vnd","vuv","xaf","xof","xpf"].indexOf(code)!==-1?1:100;
                var whole=code==="isk"||code==="ugx"?100:1;
                return Math.round((Math.round(store.total*factor)*Number(bindData.paymentSettings.service_fee_percent||0)/100)/whole)*whole/factor;
            },
            lineTotal: lineTotal,
            updateQuantity: function (item) {
                item.qty = Math.max(1, Math.floor(Number(item.qty) || 1));
                processItems();
                sessionStorage.items = JSON.stringify(bindData.items);
            },
            windowClick: function(event){
                event.stopPropagation();
            },
            getItems:function () {
                items=[];
                if(sessionStorage.items){
                    items=JSON.parse(sessionStorage.items);
                }
                return items;
            },
            navigateBack:function(){
                handler1 = exports.getShellComponent("soss-routes");
                handler1.appNavigate("..");
            },
            removeItem: function(item){
                var removeIndex;

                for (var i=0;i<bindData.items.length;i++)
                if (bindData.items[i].itemid == item.itemid){
                    removeIndex = i;
                    break;
                }

                if (removeIndex !== undefined)
                     bindData.items.splice(removeIndex, 1);

                processItems();

                sessionStorage.items = JSON.stringify(bindData.items);
            },
            checkout: function(store){
                sessionStorage.tmpstorecheckout = JSON.stringify(store);
                location.href ="#/app/userapp?u=#/app/stelup_shop/checkout";
            },
            closeModal: function(){
                $('#idCartLogin').modal('hide');
            },
            login: login,
            registerNow: function(){
                $('#idCartLogin').on('hidden.bs.modal', function () {
                    location.href ="#/user";
                });
                $('#idCartLogin').modal('hide');
            }
        },
        computed: {
            total: function() {
                return this.items.reduce(function (sum, item) { return sum + lineTotal(item); }, 0);
            }
        },
        watch:{
            items: {
                deep: true,
                handler: function (val) {
                    sessionStorage.items=JSON.stringify(val);
                    processItems();
                }
            }
        }
    }
    function groupBy(list, keyGetter) {
        const map = new Map();
        list.forEach((item) => {
             const key = keyGetter(item);
             const collection = map.get(key);
             if (!collection) {
                 map.set(key, [item]);
             } else {
                 collection.push(item);
             }
        });
        return map;
    }
    exports.vue = vueData;
    exports.onReady = function(){
    }
});

