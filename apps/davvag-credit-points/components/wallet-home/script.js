WEBDOCK.component().register(function (exports) {
    var api, router;
    var data = {
        balance: {
            availableBalance: 0,
            postedBalance: 0,
            reservedBalance: 0,
            purchasedBalance: 0,
            promotionalBalance: 0,
            expiringSoon: 0,
            symbol: 'C'
        },
        transactions: [],
        errors: [],
        loading: false
    };

    exports.vue = {data: data, methods: {go: go}, onReady: init};
    exports.onReady = function () {};

    function init() {
        api = exports.getComponent('credit-api');
        router = exports.getShellComponent('soss-routes');
        data.loading = true;

        api.services.Bootstrap({}).then(function (response) {
            if (!response.success) {
                data.loading = false;
                return fail(message(response));
            }

            data.balance = response.result.balance;

            // WebdockPromise only stores one callback; it does not chain like a native Promise.
            api.services.Transactions({limit: 6}).then(function (transactionsResponse) {
                data.loading = false;
                if (!transactionsResponse.success) {
                    return fail(message(transactionsResponse));
                }
                data.transactions = transactionsResponse.result || [];
            }).error(function () {
                data.loading = false;
                fail('Recent credit activity could not be loaded.');
            });
        }).error(function () {
            data.loading = false;
            fail('Credit wallet could not be loaded.');
        });
    }

    function go(path) {
        if (router && router.appNavigate) {
            router.appNavigate('/' + path);
        } else {
            location.hash = '#/app/davvag-credit-points/' + path;
        }
    }

    function message(response) {
        return response.result && response.result.message
            ? response.result.message
            : 'Credit wallet could not be loaded.';
    }

    function fail(errorMessage) {
        data.errors = [errorMessage];
    }
});
