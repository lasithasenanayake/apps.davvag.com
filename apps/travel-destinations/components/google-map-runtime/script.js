WEBDOCK.component().register(function (exports) {
    var bootstrapPromise = null, loadedKey = "";

    exports.onReady = function () {};

    exports.load = function (config, libraries) {
        if (!config || !config.enabled || !config.apiKey) {
            return Promise.reject(new Error("Google Maps is not configured."));
        }
        return ensureBootstrap(config).then(function () {
            var requested = Array.isArray(libraries) && libraries.length ? libraries : ["maps"];
            return Promise.all(requested.map(function (library) {
                return window.google.maps.importLibrary(library);
            }));
        });
    };

    exports.createMap = function (container, config, options) {
        options = options || {};
        if (!container) { return Promise.reject(new Error("The map container is unavailable.")); }
        var points = Array.isArray(options.points) ? options.points.filter(validPoint) : [];
        var libraries = points.length || options.draggable ? ["maps", "marker"] : ["maps"];
        return exports.load(config, libraries).then(function () {
            var center = validPoint(options.center) ? position(options.center) :
                (points.length ? position(points[0]) : position(config.defaultCenter));
            var mapOptions = {
                center: center,
                zoom: Number(options.zoom || config.defaultZoom || 8),
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true
            };
            if (config.mapId) { mapOptions.mapId = config.mapId; }
            var map = new window.google.maps.Map(container, mapOptions);
            var markers = points.map(function (point) {
                return addMarker(map, config, point, options);
            });
            if (points.length > 1) {
                var bounds = new window.google.maps.LatLngBounds();
                points.forEach(function (point) { bounds.extend(position(point)); });
                map.fitBounds(bounds, 52);
            }
            if (typeof options.onMapClick === "function") {
                map.addListener("click", function (event) {
                    options.onMapClick({lat:event.latLng.lat(), lng:event.latLng.lng()});
                });
            }
            return {
                map: map,
                markers: markers,
                setPosition: function (index, point) {
                    var marker = markers[index || 0];
                    if (!marker || !validPoint(point)) { return; }
                    marker.position = position(point);
                    if (marker.setPosition) { marker.setPosition(position(point)); }
                }
            };
        });
    };

    exports.geocode = function (config, query) {
        if (!config || !config.geocodingEnabled) {
            return Promise.reject(new Error("Address search is disabled in map settings."));
        }
        return exports.load(config, ["maps"]).then(function () {
            return new Promise(function (resolve, reject) {
                var geocoder = new window.google.maps.Geocoder();
                geocoder.geocode({address:String(query || "").trim()}, function (results, status) {
                    if (status !== "OK" || !results || !results.length) {
                        reject(new Error("Google could not find that location."));
                        return;
                    }
                    var location = results[0].geometry.location;
                    resolve({lat:location.lat(), lng:location.lng(), formattedAddress:results[0].formatted_address || ""});
                });
            });
        });
    };

    function ensureBootstrap(config) {
        if (window.google && window.google.maps && window.google.maps.importLibrary) {
            if (loadedKey && loadedKey !== config.apiKey) {
                return Promise.reject(new Error("The Google Maps key changed. Reload this page to use the new key."));
            }
            loadedKey = config.apiKey;
            return Promise.resolve();
        }
        if (bootstrapPromise) {
            if (loadedKey !== config.apiKey) {
                return Promise.reject(new Error("A different Google Maps key is already loading. Reload this page."));
            }
            return bootstrapPromise;
        }
        loadedKey = config.apiKey;
        bootstrapPromise = new Promise(function (resolve, reject) {
            var callback = "__tdGoogleMapsReady";
            var script = document.createElement("script");
            var parameters = [
                "key=" + encodeURIComponent(config.apiKey),
                "v=weekly",
                "loading=async",
                "callback=" + callback
            ];
            if (config.language) { parameters.push("language=" + encodeURIComponent(config.language)); }
            if (config.region) { parameters.push("region=" + encodeURIComponent(config.region)); }
            window[callback] = function () {
                delete window[callback];
                resolve();
            };
            script.src = "https://maps.googleapis.com/maps/api/js?" + parameters.join("&");
            script.async = true;
            script.onerror = function () {
                bootstrapPromise = null;
                delete window[callback];
                reject(new Error("Google Maps could not be loaded. Check the API key, API restrictions and allowed website referrers."));
            };
            document.head.appendChild(script);
        });
        return bootstrapPromise;
    }

    function addMarker(map, config, point, options) {
        var markerOptions = {map:map, position:position(point), title:String(point.name || point.title || "")};
        var marker;
        if (config.mapId && window.google.maps.marker && window.google.maps.marker.AdvancedMarkerElement) {
            markerOptions.gmpDraggable = !!options.draggable;
            marker = new window.google.maps.marker.AdvancedMarkerElement(markerOptions);
        } else {
            markerOptions.draggable = !!options.draggable;
            marker = new window.google.maps.Marker(markerOptions);
        }
        marker.__tdPoint = point;
        if (typeof options.onMarkerClick === "function") {
            marker.addListener("click", function () { options.onMarkerClick(point, marker); });
        }
        if (options.draggable && typeof options.onPositionChanged === "function") {
            marker.addListener("dragend", function () {
                var current = marker.position;
                var lat = typeof current.lat === "function" ? current.lat() : current.lat;
                var lng = typeof current.lng === "function" ? current.lng() : current.lng;
                options.onPositionChanged({lat:Number(lat), lng:Number(lng)});
            });
        }
        return marker;
    }

    function validPoint(point) {
        if (!point) { return false; }
        var lat = point.lat !== undefined ? point.lat : point.latitude;
        var lng = point.lng !== undefined ? point.lng : point.longitude;
        return isFinite(Number(lat)) && isFinite(Number(lng)) && Number(lat) >= -90 && Number(lat) <= 90 && Number(lng) >= -180 && Number(lng) <= 180;
    }

    function position(point) {
        point = point || {lat:7.8731,lng:80.7718};
        return {
            lat:Number(point.lat !== undefined ? point.lat : point.latitude),
            lng:Number(point.lng !== undefined ? point.lng : point.longitude)
        };
    }
});
