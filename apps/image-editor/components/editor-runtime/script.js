(function () {
function initializeImageEditorRuntime(exports) {
    var VERSION = '1';

    exports.create = function (canvasId, options) {
        if (!window.fabric || !window.fabric.Canvas) {
            throw new Error('Fabric.js 7.4.0 is unavailable.');
        }
        return new EditorAdapter(canvasId, options || {});
    };

    exports.loadFont = function (font) {
        if (!font || !font.id || !font.internal_family) return Promise.reject(new Error('Invalid font.'));
        if (font.source === 'builtin' && !font.media_reference) return Promise.resolve(font.internal_family);
        var source = 'url("' + String(font.media_reference || '').replace(/["\\]/g, '') + '")';
        var face = new FontFace(font.internal_family, source, {
            weight: String(font.font_weight || 400),
            style: font.font_style || 'normal',
            stretch: font.font_stretch || 'normal'
        });
        return face.load().then(function (loaded) {
            document.fonts.add(loaded);
            return font.internal_family;
        });
    };

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 3 | 8)).toString(16);
        });
    }

    function finite(value, fallback) {
        value = Number(value);
        return Number.isFinite(value) ? value : fallback;
    }

    function colorByte(value) {
        return Math.max(0, Math.min(255, Math.round(value))).toString(16).padStart(2, '0');
    }

    function normalizeColor(value, fallback, emptyAllowed) {
        if (value === '' && emptyAllowed) return '';
        if (typeof value !== 'string') return fallback;
        var color = value.trim().toLowerCase();
        if (color === '' && emptyAllowed) return '';
        if (color === 'transparent') return '#00000000';
        var hex = color.match(/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/);
        if (hex) {
            if (hex[1].length === 3 || hex[1].length === 4) {
                return '#' + hex[1].split('').map(function (part) { return part + part; }).join('');
            }
            return '#' + hex[1];
        }
        var rgb = color.match(/^rgba?\(\s*([^,]+)\s*,\s*([^,]+)\s*,\s*([^,\)]+)\s*(?:,\s*([^\)]+)\s*)?\)$/);
        if (rgb) {
            var components = rgb.slice(1, 4).map(function (part) {
                part = part.trim();
                return part.endsWith('%') ? Number(part.slice(0, -1)) * 2.55 : Number(part);
            });
            if (components.every(Number.isFinite)) {
                var result = '#' + components.map(colorByte).join('');
                if (rgb[4] !== undefined) {
                    var alphaText = rgb[4].trim();
                    var alpha = alphaText.endsWith('%') ? Number(alphaText.slice(0, -1)) / 100 : Number(alphaText);
                    if (Number.isFinite(alpha)) return result + colorByte(alpha * 255);
                } else {
                    return result;
                }
            }
        }
        return fallback;
    }

    function EditorAdapter(canvasId, options) {
        this.options = options;
        this.canvas = new fabric.Canvas(canvasId, {
            preserveObjectStacking: true,
            selection: true,
            stopContextMenu: true,
            fireRightClick: false
        });
        this.width = finite(options.width, 1280);
        this.height = finite(options.height, 720);
        this.zoom = 1;
        this.background = '#ffffff';
        this.pages = [];
        this.currentPageIndex = 0;
        this.pageUuid = null;
        this.history = [];
        this.historyIndex = -1;
        this.suspendHistory = false;
        this.disposed = false;
        this.onChange = options.onChange || function () {};
        this.onSelection = options.onSelection || function () {};
        this.onPage = options.onPage || function () {};
        this.canvas.setDimensions({ width: this.width, height: this.height });
        this.applyDisplaySize();
        this.canvas.backgroundColor = this.background;
        this.pages = [this.blankPage()];
        this.pageUuid = this.pages[0].pageUuid;
        this.bind();
        this.captureHistory();
        this.notifyPage();
    }

    EditorAdapter.prototype.bind = function () {
        var self = this;
        ['object:modified', 'object:added', 'object:removed', 'text:changed'].forEach(function (eventName) {
            self.canvas.on(eventName, function () {
                if (!self.suspendHistory) {
                    self.captureHistory();
                    self.onChange();
                }
            });
        });
        ['selection:created', 'selection:updated'].forEach(function (eventName) {
            self.canvas.on(eventName, function () { self.onSelection(self.describe(self.canvas.getActiveObject())); });
        });
        this.canvas.on('selection:cleared', function () { self.onSelection(null); });
    };

    EditorAdapter.prototype.applyDisplaySize = function () {
        var displayWidth = Math.max(1, Math.round(this.width * this.zoom));
        var displayHeight = Math.max(1, Math.round(this.height * this.zoom));
        var width = displayWidth + 'px';
        var height = displayHeight + 'px';
        var lower = this.canvas.lowerCanvasEl;
        var upper = this.canvas.upperCanvasEl;
        var wrapper = this.canvas.wrapperEl;
        [lower, upper].forEach(function (element) {
            if (!element) return;
            element.style.width = width;
            element.style.height = height;
            element.style.maxWidth = 'none';
            element.style.maxHeight = 'none';
        });
        if (wrapper) {
            wrapper.style.width = width;
            wrapper.style.height = height;
            wrapper.style.maxWidth = 'none';
            wrapper.style.maxHeight = 'none';
        }
        var frame = wrapper && wrapper.parentElement;
        if (frame && frame.classList.contains('ie-canvas-frame')) {
            frame.style.width = width;
            frame.style.height = height;
        }
        this.canvas.calcOffset();
        this.canvas.requestRenderAll();
    };

    EditorAdapter.prototype.refreshLayout = function () {
        this.applyDisplaySize();
    };

    EditorAdapter.prototype.blankPage = function () {
        return {
            pageUuid: uuid(),
            width: this.width,
            height: this.height,
            background: { type: 'solid', color: '#ffffff' },
            elements: []
        };
    };

    EditorAdapter.prototype.cloneData = function (value) {
        return JSON.parse(JSON.stringify(value));
    };

    EditorAdapter.prototype.currentPageData = function () {
        var self = this;
        return {
            pageUuid: this.pageUuid || uuid(),
            width: this.width,
            height: this.height,
            background: { type: 'solid', color: normalizeColor(this.background, '#ffffff', false) },
            elements: this.canvas.getObjects().map(function (object, index) {
                return self.serializeElement(object, index);
            })
        };
    };

    EditorAdapter.prototype.storeCurrentPage = function () {
        if (!this.pages.length) this.pages = [this.blankPage()];
        this.pages[this.currentPageIndex] = this.currentPageData();
    };

    EditorAdapter.prototype.notifyPage = function () {
        this.onPage({
            index: this.currentPageIndex,
            count: this.pages.length,
            background: this.background,
            pages: this.pages.map(function (page, index) {
                return { index: index, pageUuid: page.pageUuid, number: index + 1 };
            })
        });
    };

    EditorAdapter.prototype.decorate = function (object, type, values) {
        values = values || {};
        object.elementUuid = values.elementUuid || uuid();
        object.elementType = type;
        object.elementName = values.name || type.charAt(0).toUpperCase() + type.slice(1);
        object.assetId = values.assetId || null;
        object.mediaReference = values.mediaReference || '';
        object.fontId = values.fontId || null;
        object.set({
            left: finite(values.x, 80),
            top: finite(values.y, 80),
            angle: finite(values.rotation, 0),
            scaleX: finite(values.scaleX, 1),
            scaleY: finite(values.scaleY, 1),
            opacity: Math.max(0, Math.min(1, finite(values.opacity, 1))),
            flipX: !!values.flipX,
            flipY: !!values.flipY,
            visible: values.hidden !== true,
            lockMovementX: !!values.locked,
            lockMovementY: !!values.locked,
            lockScalingX: !!values.locked,
            lockScalingY: !!values.locked,
            lockRotation: !!values.locked,
            hasControls: !values.locked
        });
        return object;
    };

    EditorAdapter.prototype.addText = function (values) {
        values = values || {};
        var text = new fabric.Textbox(values.text || 'Add a heading', {
            width: finite(values.width, Math.min(520, this.width - 160)),
            fill: values.fill || '#111827',
            fontFamily: values.fontFamily || 'Arial',
            fontSize: finite(values.fontSize, 64),
            fontWeight: values.fontWeight || 700,
            fontStyle: values.fontStyle || 'normal',
            textAlign: values.textAlign || 'left',
            lineHeight: finite(values.lineHeight, 1.16),
            charSpacing: finite(values.charSpacing, 0)
        });
        this.canvas.add(this.decorate(text, 'text', values));
        this.canvas.setActiveObject(text);
        this.canvas.requestRenderAll();
        return text;
    };

    EditorAdapter.prototype.addShape = function (type, values) {
        values = values || {};
        var common = { width: finite(values.width, 240), height: finite(values.height, 160), fill: values.fill || '#7c3aed', stroke: values.stroke || '', strokeWidth: finite(values.strokeWidth, 0), rx: finite(values.cornerRadius, 0), ry: finite(values.cornerRadius, 0) };
        var object;
        if (type === 'ellipse') object = new fabric.Ellipse(Object.assign({}, common, { rx: common.width / 2, ry: common.height / 2 }));
        else if (type === 'triangle') object = new fabric.Triangle(common);
        else if (type === 'line') object = new fabric.Line([0, 0, finite(values.width, 260), 0], { stroke: values.stroke || values.fill || '#111827', strokeWidth: finite(values.strokeWidth, 6) });
        else object = new fabric.Rect(common);
        this.canvas.add(this.decorate(object, type, values));
        this.canvas.setActiveObject(object);
        this.canvas.requestRenderAll();
        return object;
    };

    EditorAdapter.prototype.addImage = function (asset, values) {
        var self = this;
        values = values || {};
        return fabric.FabricImage.fromURL(asset.media_reference, { crossOrigin: 'anonymous' }).then(function (image) {
            var maxWidth = Math.min(self.width * 0.65, 720);
            var scale = Math.min(1, maxWidth / Math.max(1, image.width));
            values.assetId = asset.id;
            values.mediaReference = asset.media_reference;
            values.scaleX = values.scaleX || scale;
            values.scaleY = values.scaleY || scale;
            image.set({ width: finite(values.width, image.width), height: finite(values.height, image.height), cropX: finite(values.cropX, 0), cropY: finite(values.cropY, 0) });
            image.imageBrightness = finite(values.brightness, 0); image.imageGrayscale = !!values.grayscale;
            self.applyImageFilters(image);
            self.canvas.add(self.decorate(image, 'image', values));
            self.canvas.setActiveObject(image);
            self.canvas.requestRenderAll();
            return image;
        });
    };

    EditorAdapter.prototype.describe = function (object) {
        if (!object) return null;
        if (fabric.ActiveSelection && object instanceof fabric.ActiveSelection) {
            return { type: 'multi', name: object.size ? object.size() + ' elements' : 'Multiple elements', count: object.size ? object.size() : this.canvas.getActiveObjects().length };
        }
        var description = {
            cornerRadius: finite(object.rx, 0), flipX: !!object.flipX, flipY: !!object.flipY,
            cropX: finite(object.cropX, 0), cropY: finite(object.cropY, 0),
            brightness: finite(object.imageBrightness, 0), grayscale: !!object.imageGrayscale
        };
        return Object.assign(description, {
            uuid: object.elementUuid, type: object.elementType, name: object.elementName,
            fill: normalizeColor(object.fill, '#000000', false), stroke: normalizeColor(object.stroke, '#000000', false),
            strokeWidth: finite(object.strokeWidth, 0), opacity: finite(object.opacity, 1), rotation: finite(object.angle, 0),
            locked: !!object.lockMovementX, hidden: object.visible === false, text: object.text || '', fontFamily: object.fontFamily || 'Arial',
            fontId: object.fontId || null, fontSize: finite(object.fontSize, 48), fontWeight: object.fontWeight || 400, fontStyle: object.fontStyle || 'normal',
            textAlign: object.textAlign || 'left', lineHeight: finite(object.lineHeight, 1.16), charSpacing: finite(object.charSpacing, 0)
        });
    };

    EditorAdapter.prototype.updateSelected = function (changes) {
        var object = this.canvas.getActiveObject();
        if (!object || fabric.ActiveSelection && object instanceof fabric.ActiveSelection) return;
        var map = {
            fill: 'fill', stroke: 'stroke', strokeWidth: 'strokeWidth', opacity: 'opacity', rotation: 'angle', text: 'text',
            fontFamily: 'fontFamily', fontId: 'fontId', fontSize: 'fontSize', fontWeight: 'fontWeight', fontStyle: 'fontStyle',
            textAlign: 'textAlign', lineHeight: 'lineHeight', charSpacing: 'charSpacing', flipX: 'flipX', flipY: 'flipY'
        };
        Object.keys(changes || {}).forEach(function (key) { if (map[key]) object.set(map[key], changes[key]); });
        if (object.elementType === 'image' && (changes.cropX !== undefined || changes.cropY !== undefined)) {
            var source = object.getElement ? object.getElement() : object._element;
            var sourceWidth = source && (source.naturalWidth || source.width) || object.width;
            var sourceHeight = source && (source.naturalHeight || source.height) || object.height;
            if (changes.cropX !== undefined) object.set('cropX', Math.max(0, Math.min(finite(changes.cropX, 0), Math.max(0, sourceWidth - object.width))));
            if (changes.cropY !== undefined) object.set('cropY', Math.max(0, Math.min(finite(changes.cropY, 0), Math.max(0, sourceHeight - object.height))));
        }
        if (changes.cornerRadius !== undefined && object.elementType === 'rect') object.set({ rx: finite(changes.cornerRadius, 0), ry: finite(changes.cornerRadius, 0) });
        if (changes.brightness !== undefined) object.imageBrightness = finite(changes.brightness, 0);
        if (changes.grayscale !== undefined) object.imageGrayscale = !!changes.grayscale;
        if (object.elementType === 'image' && (changes.brightness !== undefined || changes.grayscale !== undefined)) this.applyImageFilters(object);
        if (changes.locked !== undefined) {
            var locked = !!changes.locked;
            object.set({ lockMovementX: locked, lockMovementY: locked, lockScalingX: locked, lockScalingY: locked, lockRotation: locked, hasControls: !locked });
        }
        if (changes.hidden !== undefined) object.set('visible', !changes.hidden);
        object.setCoords();
        this.canvas.requestRenderAll();
        this.captureHistory();
        this.onChange();
        this.onSelection(this.describe(object));
    };

    EditorAdapter.prototype.applyImageFilters = function (image) {
        if (!image || image.elementType && image.elementType !== 'image') return;
        var filters = [];
        if (image.imageGrayscale && fabric.filters && fabric.filters.Grayscale) filters.push(new fabric.filters.Grayscale());
        if (Math.abs(finite(image.imageBrightness, 0)) > 0.001 && fabric.filters && fabric.filters.Brightness) filters.push(new fabric.filters.Brightness({ brightness: finite(image.imageBrightness, 0) }));
        image.filters = filters; if (image.applyFilters) image.applyFilters();
    };

    EditorAdapter.prototype.fitSelectedImage = function (mode) {
        var image = this.canvas.getActiveObject(); if (!image || image.elementType !== 'image') return;
        var scale = mode === 'fill' ? Math.max(this.width / image.width, this.height / image.height) : Math.min(this.width / image.width, this.height / image.height);
        image.set({ scaleX: scale, scaleY: scale }); this.canvas.centerObject(image); image.setCoords(); this.canvas.requestRenderAll(); this.captureHistory(); this.onChange(); this.onSelection(this.describe(image));
    };

    EditorAdapter.prototype.replaceSelectedImage = function (asset) {
        var self = this, current = this.canvas.getActiveObject();
        if (!current || current.elementType !== 'image') return this.addImage(asset, {});
        return fabric.FabricImage.fromURL(asset.media_reference, { crossOrigin: 'anonymous' }).then(function (replacement) {
            var index = self.canvas.getObjects().indexOf(current);
            var values = self.serializeElement(current, index).properties;
            values.elementUuid = current.elementUuid; values.name = current.elementName; values.assetId = asset.id; values.mediaReference = asset.media_reference;
            replacement.set({ width: replacement.width, height: replacement.height, cropX: 0, cropY: 0 });
            replacement.imageBrightness = finite(values.brightness, 0); replacement.imageGrayscale = !!values.grayscale; self.applyImageFilters(replacement);
            self.suspendHistory = true;
            self.canvas.remove(current); self.canvas.insertAt(index, self.decorate(replacement, 'image', values)); self.canvas.setActiveObject(replacement); self.canvas.requestRenderAll();
            self.suspendHistory = false; self.captureHistory(); self.onChange(); self.onSelection(self.describe(replacement));
            return replacement;
        });
    };

    EditorAdapter.prototype.deleteSelected = function () {
        var active = this.canvas.getActiveObjects();
        var canvas = this.canvas;
        active.forEach(function (object) { if (!object.lockMovementX) canvas.remove(object); });
        canvas.discardActiveObject(); canvas.requestRenderAll();
    };

    EditorAdapter.prototype.duplicateSelected = function () {
        var self = this, object = this.canvas.getActiveObject();
        if (!object || fabric.ActiveSelection && object instanceof fabric.ActiveSelection) return Promise.resolve(null);
        return Promise.resolve(object.clone()).then(function (clone) {
            var values = self.serializeElement(object, 0).properties;
            values.x = finite(object.left, 0) + 24; values.y = finite(object.top, 0) + 24; values.name = object.elementName + ' copy';
            self.decorate(clone, object.elementType, values);
            if (object.elementType === 'image') { clone.imageBrightness = finite(values.brightness, 0); clone.imageGrayscale = !!values.grayscale; self.applyImageFilters(clone); }
            self.canvas.add(clone); self.canvas.setActiveObject(clone); self.canvas.requestRenderAll();
            return clone;
        });
    };

    EditorAdapter.prototype.layer = function (direction) {
        var object = this.canvas.getActiveObject(); if (!object) return;
        if (direction === 'front') this.canvas.bringObjectForward(object);
        else this.canvas.sendObjectBackwards(object);
        this.canvas.requestRenderAll(); this.captureHistory(); this.onChange();
    };

    EditorAdapter.prototype.align = function (axis) {
        var object = this.canvas.getActiveObject(); if (!object) return;
        if (axis === 'horizontal') this.canvas.centerObjectH(object); else this.canvas.centerObjectV(object);
        object.setCoords(); this.canvas.requestRenderAll(); this.captureHistory(); this.onChange();
    };

    EditorAdapter.prototype.setBackground = function (color) {
        this.background = normalizeColor(color, '#ffffff', false); this.canvas.backgroundColor = this.background;
        this.canvas.requestRenderAll(); this.captureHistory(); this.onChange();
    };

    EditorAdapter.prototype.serializeElement = function (object, index) {
        var values = {
            x: finite(object.left, 0), y: finite(object.top, 0), width: finite(object.width, 1), height: finite(object.height, 1),
            scaleX: finite(object.scaleX, 1), scaleY: finite(object.scaleY, 1), rotation: finite(object.angle, 0), opacity: finite(object.opacity, 1),
            flipX: !!object.flipX, flipY: !!object.flipY, fill: normalizeColor(object.fill, '#000000', false),
            stroke: normalizeColor(object.stroke, '', true), strokeWidth: finite(object.strokeWidth, 0), cornerRadius: finite(object.rx, 0)
        };
        if (object.elementType === 'text') Object.assign(values, { text: String(object.text || ''), fontId: object.fontId || null, fontFamily: object.fontFamily || 'Arial', fontSize: finite(object.fontSize, 48), fontWeight: String(object.fontWeight || 400), fontStyle: object.fontStyle || 'normal', textAlign: object.textAlign || 'left', lineHeight: finite(object.lineHeight, 1.16), charSpacing: finite(object.charSpacing, 0) });
        if (object.elementType === 'image') Object.assign(values, { assetId: Number(object.assetId), mediaReference: object.mediaReference || '', cropX: finite(object.cropX, 0), cropY: finite(object.cropY, 0), brightness: finite(object.imageBrightness, 0), grayscale: !!object.imageGrayscale });
        return { elementUuid: object.elementUuid || uuid(), type: object.elementType || 'rect', name: object.elementName || 'Element', zIndex: index, locked: !!object.lockMovementX, hidden: object.visible === false, properties: values };
    };

    EditorAdapter.prototype.serialize = function () {
        this.storeCurrentPage();
        return { documentVersion: Number(VERSION), pages: this.cloneData(this.pages) };
    };

    EditorAdapter.prototype.createFromElement = function (element) {
        var p = element.properties || {};
        var values = Object.assign({}, p, { elementUuid: element.elementUuid, name: element.name, locked: element.locked, hidden: element.hidden });
        if (element.type === 'text') return Promise.resolve(this.addText(values));
        if (element.type === 'image') return this.addImage({ id: p.assetId, media_reference: p.mediaReference }, values);
        return Promise.resolve(this.addShape(element.type, values));
    };

    EditorAdapter.prototype.renderPage = function (page) {
        var self = this;
        if (!page) return Promise.resolve();
        this.suspendHistory = true;
        this.canvas.clear();
        this.width = page.width; this.height = page.height; this.pageUuid = page.pageUuid;
        this.canvas.setDimensions({ width: this.width, height: this.height });
        this.applyDisplaySize();
        this.background = page.background && page.background.color || '#ffffff'; this.canvas.backgroundColor = this.background;
        var chain = Promise.resolve();
        (page.elements || []).sort(function (a, b) { return a.zIndex - b.zIndex; }).forEach(function (element) { chain = chain.then(function () { return self.createFromElement(element); }); });
        return chain.then(function () {
            self.canvas.discardActiveObject(); self.canvas.requestRenderAll(); self.suspendHistory = false;
            self.onSelection(null); self.notifyPage();
        }).catch(function (error) { self.suspendHistory = false; throw error; });
    };

    EditorAdapter.prototype.load = function (documentData, preserveHistory, pageIndex) {
        var sourcePages = documentData && documentData.pages;
        if (!sourcePages || !sourcePages.length) return Promise.resolve();
        this.pages = this.cloneData(sourcePages);
        this.currentPageIndex = Math.max(0, Math.min(this.pages.length - 1, Number(pageIndex) || 0));
        var self = this;
        return this.renderPage(this.pages[this.currentPageIndex]).then(function () {
            if (!preserveHistory) { self.history = []; self.historyIndex = -1; self.captureHistory(); }
        });
    };

    EditorAdapter.prototype.switchPage = function (index) {
        index = Number(index);
        if (index < 0 || index >= this.pages.length || index === this.currentPageIndex) return Promise.resolve(false);
        this.storeCurrentPage();
        this.currentPageIndex = index;
        return this.renderPage(this.pages[index]).then(function () { return true; });
    };

    EditorAdapter.prototype.addPage = function (duplicate) {
        this.storeCurrentPage();
        var page = duplicate ? this.cloneData(this.pages[this.currentPageIndex]) : this.blankPage();
        page.pageUuid = uuid();
        (page.elements || []).forEach(function (element) { element.elementUuid = uuid(); });
        this.pages.splice(this.currentPageIndex + 1, 0, page);
        this.currentPageIndex += 1;
        var self = this;
        return this.renderPage(page).then(function () {
            self.captureHistory(); self.onChange(); self.notifyPage(); return self.currentPageIndex;
        });
    };

    EditorAdapter.prototype.deletePage = function () {
        if (this.pages.length <= 1) return Promise.resolve(false);
        this.storeCurrentPage();
        this.pages.splice(this.currentPageIndex, 1);
        this.currentPageIndex = Math.min(this.currentPageIndex, this.pages.length - 1);
        var self = this;
        return this.renderPage(this.pages[this.currentPageIndex]).then(function () {
            self.captureHistory(); self.onChange(); self.notifyPage(); return true;
        });
    };

    EditorAdapter.prototype.movePage = function (direction) {
        this.storeCurrentPage();
        var target = this.currentPageIndex + Number(direction);
        if (target < 0 || target >= this.pages.length) return false;
        var page = this.pages.splice(this.currentPageIndex, 1)[0];
        this.pages.splice(target, 0, page); this.currentPageIndex = target;
        this.captureHistory(); this.onChange(); this.notifyPage();
        return true;
    };

    EditorAdapter.prototype.captureHistory = function () {
        if (this.suspendHistory) return;
        var snapshot = JSON.stringify({ document: this.serialize(), pageIndex: this.currentPageIndex });
        if (this.history[this.historyIndex] === snapshot) return;
        this.history = this.history.slice(0, this.historyIndex + 1);
        this.history.push(snapshot);
        if (this.history.length > 50) this.history.shift();
        this.historyIndex = this.history.length - 1;
    };

    EditorAdapter.prototype.restoreHistory = function (index) {
        if (index < 0 || index >= this.history.length) return Promise.resolve(false);
        var self = this, snapshot = JSON.parse(this.history[index]);
        var documentData = snapshot.document || snapshot;
        var pageIndex = snapshot.document ? snapshot.pageIndex : 0;
        return this.load(documentData, true, pageIndex).then(function () { self.historyIndex = index; return true; });
    };
    EditorAdapter.prototype.undo = function () { return this.restoreHistory(this.historyIndex - 1); };
    EditorAdapter.prototype.redo = function () { return this.restoreHistory(this.historyIndex + 1); };

    EditorAdapter.prototype.exportDataUrl = function (format, multiplier, quality) {
        var self = this;
        return document.fonts.ready.then(function () {
            var viewport = self.canvas.viewportTransform.slice();
            self.canvas.setViewportTransform([1, 0, 0, 1, 0, 0]);
            self.canvas.discardActiveObject(); self.canvas.requestRenderAll();
            var dataUrl = self.canvas.toDataURL({ format: format === 'jpg' ? 'jpeg' : format, multiplier: multiplier || 1, quality: quality || 0.92, enableRetinaScaling: false });
            self.canvas.setViewportTransform(viewport); self.canvas.requestRenderAll();
            return dataUrl;
        });
    };

    EditorAdapter.prototype.setZoom = function (value) {
        this.zoom = Math.max(0.1, Math.min(3, Number(value) || 1));
        this.applyDisplaySize();
    };
    EditorAdapter.prototype.dispose = function () { if (!this.disposed) { this.disposed = true; this.canvas.dispose(); } };
}

// Initialize a global fallback as soon as the script executes. Some DAVVAG
// shells expose a component instance before invoking its register callback.
// The editor can therefore use the runtime without depending on that timing.
window.DAVVAGImageEditorRuntime = window.DAVVAGImageEditorRuntime || {};
initializeImageEditorRuntime(window.DAVVAGImageEditorRuntime);

var component = WEBDOCK.component();
if (component && typeof component.register === 'function') {
    component.register(initializeImageEditorRuntime);
}
})();
