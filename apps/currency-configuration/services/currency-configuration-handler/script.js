WEBDOCK.component().register(function(exports){
    function unwrap(response){
        return response && response.success ? response.result : null;
    }

    exports.loadActive = function(callback, onError){
        return exports.services.Active().then(function(response){
            var items = unwrap(response) || [];
            if(callback){ callback(items); }
            return items;
        }).error(function(error){
            if(onError){ onError(error); }
        });
    };

    exports.loadDefault = function(callback, onError){
        return exports.services.Default().then(function(response){
            var currency = unwrap(response);
            if(callback){ callback(currency); }
            return currency;
        }).error(function(error){
            if(onError){ onError(error); }
        });
    };

    exports.format = function(amount, currency){
        currency = currency || {};
        var places = parseInt(currency.decimalPlaces, 10);
        if(isNaN(places)){ places = 2; }
        var value = parseFloat(amount || 0);
        if(isNaN(value)){ value = 0; }
        return (currency.symbol || currency.code || "") + " " + value.toFixed(places);
    };
});
