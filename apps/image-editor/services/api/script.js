WEBDOCK.component().register(function (exports) {
    // DAVVAG WebdockPromise exposes then/error but is not a native Promise.
    // Normalize this app's generated service methods so component code can use
    // standard chaining, Promise.all, catch, and finally safely.
    Object.keys(exports.services || {}).forEach(function (name) {
        var backendCall = exports.services[name];
        exports.services[name] = function () {
            var args = arguments;
            return new Promise(function (resolve, reject) {
                try {
                    backendCall.apply(null, args).then(resolve).error(reject);
                } catch (error) {
                    reject(error);
                }
            });
        };
    });
});
