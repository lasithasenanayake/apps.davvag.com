(function (window) {
    var runtime = window.TravelDestinationGoogleMaps || {};
    var bootstrapPromise = null, loadedKey = "", rejectBootstrap = null;

    runtime.load = function (config, libraries) {
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

    runtime.createMap = function (container, config, options) {
        options = options || {};
        if (!container) { return Promise.reject(new Error("The map container is unavailable.")); }
        var points = Array.isArray(options.points) ? options.points.filter(validPoint) : [];
        var libraries = points.length || options.draggable ? ["maps", "marker"] : ["maps"];
        return runtime.load(config, libraries).then(function (modules) {
            return waitForContainer(container).then(function (readyContainer) {
            container = readyContainer;
            var center = validPoint(options.center) ? position(options.center) :
                (points.length ? position(points[0]) : position(config.defaultCenter));
            var mapOptions = {
                center: center,
                zoom: Number(options.zoom || config.defaultZoom || 8),
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true,
                mapId: config.mapId || "DEMO_MAP_ID"
            };
            var MapClass = modules[0] && modules[0].Map ? modules[0].Map : window.google.maps.Map;
            var AdvancedMarkerClass = modules[1] && modules[1].AdvancedMarkerElement ? modules[1].AdvancedMarkerElement :
                (window.google.maps.marker && window.google.maps.marker.AdvancedMarkerElement);
            var PinClass = modules[1] && modules[1].PinElement ? modules[1].PinElement :
                (window.google.maps.marker && window.google.maps.marker.PinElement);
            var map = new MapClass(container, mapOptions);
            var markers = points.map(function (point, index) {
                return addMarker(map, point, index, options, AdvancedMarkerClass, PinClass);
            });
            if (uniquePositionCount(points) > 1) {
                var bounds = new window.google.maps.LatLngBounds();
                points.forEach(function (point) { bounds.extend(position(point)); });
                map.fitBounds(bounds, 52);
            }
            if (typeof options.onMapClick === "function") {
                map.addListener("click", function (event) {
                    options.onMapClick({lat:event.latLng.lat(), lng:event.latLng.lng()});
                });
            }
            requestAnimationFrame(function () {
                window.google.maps.event.trigger(map, "resize");
                map.setCenter(center);
            });
            if (typeof ResizeObserver !== "undefined") {
                var observer = new ResizeObserver(function () {
                    window.google.maps.event.trigger(map, "resize");
                });
                observer.observe(container);
                map.__tdResizeObserver = observer;
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
        });
    };

    runtime.geocode = function (config, query) {
        if (!config || !config.geocodingEnabled) {
            return Promise.reject(new Error("Address search is disabled in map settings."));
        }
        return runtime.load(config, ["maps"]).then(function () {
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
            var completed = false;
            var timeout = setTimeout(function () {
                fail("Google Maps timed out while loading. Check the API key, billing, API restrictions and allowed website referrers.");
            },20000);
            var parameters = [
                "key=" + encodeURIComponent(config.apiKey),
                "v=weekly",
                "loading=async",
                "callback=" + callback
            ];
            if (config.language) { parameters.push("language=" + encodeURIComponent(config.language)); }
            if (config.region) { parameters.push("region=" + encodeURIComponent(config.region)); }
            function fail(message) {
                if (completed) { return; }
                completed = true;
                clearTimeout(timeout);
                bootstrapPromise = null;
                rejectBootstrap = null;
                delete window[callback];
                reject(new Error(message));
            }
            rejectBootstrap = fail;
            window[callback] = function () {
                if (completed) { return; }
                completed = true;
                clearTimeout(timeout);
                rejectBootstrap = null;
                delete window[callback];
                resolve();
            };
            script.src = "https://maps.googleapis.com/maps/api/js?" + parameters.join("&");
            script.async = true;
            script.onerror = function () {
                fail("Google Maps could not be loaded. Check the API key, billing, API restrictions and allowed website referrers.");
            };
            document.head.appendChild(script);
        });
        return bootstrapPromise;
    }

    var previousAuthFailure = window.gm_authFailure;
    window.gm_authFailure = function () {
        if (typeof previousAuthFailure === "function") {
            try { previousAuthFailure(); } catch (ignore) {}
        }
        if (rejectBootstrap) {
            rejectBootstrap("Google Maps rejected the API key. Allow https://www.ephraimgen.com/* in HTTP referrers and enable Maps JavaScript API billing.");
        }
    };

    function addMarker(map, point, index, options, AdvancedMarkerClass, PinClass) {
        var markerOptions = {map:map, position:position(point), title:String(point.name || point.title || "")};
        if (!AdvancedMarkerClass) {
            throw new Error("Google Advanced Markers could not be loaded.");
        }
        markerOptions.gmpDraggable = !!options.draggable;
        if (PinClass) {
            var pin = new PinClass({
                glyph:String(point.markerLabel || index + 1),
                background:"#c76443",
                borderColor:"#8f3d28",
                glyphColor:"#ffffff",
                scale:1.08
            });
            markerOptions.content = pin.element;
        }
        var marker = new AdvancedMarkerClass(markerOptions);
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

    function uniquePositionCount(points) {
        var positions = {};
        points.forEach(function (point) {
            var current = position(point);
            positions[current.lat.toFixed(7) + "," + current.lng.toFixed(7)] = true;
        });
        return Object.keys(positions).length;
    }

    function waitForContainer(container) {
        return new Promise(function (resolve, reject) {
            var attempts = 0;
            function ready() {
                attempts++;
                var candidate = visibleMapContainer(container);
                if (candidate) {
                    resolve(candidate);
                    return;
                }
                if (attempts >= 60) {
                    reject(new Error("The map container is not visible yet."));
                    return;
                }
                requestAnimationFrame(ready);
            }
            ready();
        });
    }

    function visibleMapContainer(original) {
        var candidates = [original], selector = "";
        if (original && original.attributes) {
            for (var index = 0; index < original.attributes.length; index++) {
                var attributeName = original.attributes[index].name;
                if (attributeName.indexOf("data-google-") === 0) {
                    selector = "[" + attributeName + "]";
                    break;
                }
            }
        }
        if (selector) {
            candidates = candidates.concat(Array.prototype.slice.call(document.querySelectorAll(selector)));
        }
        for (var candidateIndex = candidates.length - 1; candidateIndex >= 0; candidateIndex--) {
            var candidate = candidates[candidateIndex];
            if (!candidate || !candidate.getBoundingClientRect) { continue; }
            var connected = candidate.isConnected !== undefined ? candidate.isConnected : document.documentElement.contains(candidate);
            var rectangle = candidate.getBoundingClientRect();
            if (connected && rectangle.width > 1 && rectangle.height > 1) {
                return candidate;
            }
        }
        return null;
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

    window.TravelDestinationGoogleMaps = runtime;
    WEBDOCK.component().register(function (exports) {
        exports.runtime = runtime;
        exports.load = runtime.load;
        exports.createMap = runtime.createMap;
        exports.geocode = runtime.geocode;
        exports.onReady = function () {};
    });
})(window);
