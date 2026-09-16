WEBDOCK.component().register(function(exports) {
    'use strict';
    var api, host, d= {
        busy: false, error: '', info: '', id: 0, revision: 0, status: 'draft', preview: false, savedDescription: '', products: [], lessons: [], pages: [], pageSlug: '', pageTitle: '', pageLink: '', lessonOffset: 0, productOffset: 0, form: {
            slug: '', name: '', summary: '', description: '', cover_image: '', outcomes: '', audience: '', prerequisites: '', product_id: 0, lesson_ids: [], pricing_mode: 'free', credit_price: 0, approval_required: false
        }
    };
    exports.vue= {
        data: d, methods: {
            save: save, publish: publish, setStatus: setStatus, load: load, toggle: toggle, move: move, createProduct: createProduct, upload: upload, rich: rich, sync: sync, showPreview: showPreview, loadPages: loadPages, place: place, moreLessons: moreLessons, moreProducts: moreProducts
        }
        , onReady: function(s, context) {
            api=exports.getComponent('marketplace-api');
            host=context && context.renderDiv || context || exports.renderDiv;
            d.id=Number(new URLSearchParams(location.hash.split('?')[1]||'').get('id')||0);
            load();
        }
    };
    function call(name, payload, done) {
        if(d.busy)return;
        d.busy=true;
        d.error='';
        api.services[name](payload).then(function(r) {
            d.busy=false;
            if(!r.success) {
                d.error=typeof r.result==='string'?r.result: (r.message||r.result&&r.result.message||'Request failed.');
                return;
            }
            done(r.result);
        }
        ).error(function() {
            d.busy=false;
            d.error='Connection interrupted. Reload before retrying a publication or decision.';
        }
        );
    }
    function editor() {
        var root=host && host[0] || host;
        return root && root.querySelector?root.querySelector('[data-lmp-editor]'): document.querySelector('[data-lmp-editor]');
    }
    function load() {
        if(d.id)call('AdminPackage', {
            id: d.id
        }
        , function(x) {
            d.form=x.draft;
            d.savedDescription=x.draft.description;
            d.form.slug=x.slug;
            d.revision=Number(x.draft_revision);
            d.status=x.status;
            setTimeout(function() {
                if(editor())editor().innerHTML=d.form.description||'';
            }
            , 0);
            lookups();
        }
        );
        else lookups();
    }
    function lookups() {
        call('Lookups', {
            kind: 'products', limit: 100, offset: d.productOffset
        }
        , function(r) {
            d.products=r.items;
            call('Lookups', {
                kind: 'lessons', limit: 100, offset: d.lessonOffset
            }
            , function(r) {
                d.lessons=r.items;
            }
            );
        }
        );
    }
    function moreLessons() {
        d.lessonOffset+=100;
        call('Lookups', {
            kind: 'lessons', limit: 100, offset: d.lessonOffset
        }
        , function(r) {
            d.lessons=d.lessons.concat(r.items);
        }
        );
    }
    function moreProducts() {
        d.productOffset+=100;
        call('Lookups', {
            kind: 'products', limit: 100, offset: d.productOffset
        }
        , function(r) {
            d.products=d.products.concat(r.items);
        }
        );
    }
    function sync() {
        if(editor())d.form.description=editor().innerHTML;
    }
    function rich(command) {
        if(editor()) {
            editor().focus();
            document.execCommand(command, false, null);
            sync();
        }
    }
    function save() {
        sync();
        var payload=JSON.parse(JSON.stringify(d.form));
        delete payload.lessons;
        delete payload.program_code;
        payload.id=d.id;
        payload.revision=d.revision;
        payload.product_id=Number(payload.product_id);
        payload.credit_price=d.form.pricing_mode==='free'?0: Number(payload.credit_price);
        call('SavePackage', payload, function(r) {
            d.id=Number(r.id);
            d.revision=Number(r.revision);
            d.info='Draft saved. Publish when ready.';
            load();
        }
        );
    }
    function publish() {
        if(!d.id)return;
        call('PublishPackage', {
            id: d.id, revision: d.revision
        }
        , function() {
            d.info='Package published. Existing requests retain their accepted terms.';
            load();
        }
        );
    }
    function setStatus(value) {
        call('SetPackageStatus', {
            id: d.id, status: value
        }
        , function() {
            d.info='Package '+value+'.';
            load();
        }
        );
    }
    function toggle(id) {
        var i=d.form.lesson_ids.indexOf(id);
        if(i<0)d.form.lesson_ids.push(id);
        else d.form.lesson_ids.splice(i, 1);
    }
    function move(index, direction) {
        var next=index+direction;
        if(next<0||next>=d.form.lesson_ids.length)return;
        var ids=d.form.lesson_ids.slice(), temp=ids[index];
        ids[index]=ids[next];
        ids[next]=temp;
        d.form.lesson_ids=ids;
    }
    function createProduct() {
        var name=window.prompt('New product name', d.form.name);
        if(!name)return;
        call('CreateProduct', {
            name: name
        }
        , function(p) {
            d.products.push(p);
            d.form.product_id=p.id;
        }
        );
    }
    function upload(event) {
        var file=event.target.files && event.target.files[0];
        if(!file||d.busy)return;
        if(!/^image\/(png|jpeg|webp|gif)$/.test(file.type)||file.size>5242880) {
            d.error='Choose a PNG, JPG, WebP or GIF up to 5 MB.';
            return;
        }
        d.busy=true;
        file.uploadName='cover-'+Date.now()+'-'+file.name.replace(/[^A-Za-z0-9._-]/g, '_');
        exports.getAppComponent('davvag-tools', 'davvag-file-uploader', function(uploader) {
            uploader.initialize();
            uploader.upload_uncompressed([file], 'lmp_cover', null, function() {
                d.busy=false;
                if(file.status!==true) {
                    d.error='Cover upload failed.';
                    return;
                }
                d.form.cover_image='components/dock/soss-uploader/service/get/lmp_cover/'+file.uploadName;
            }
            );
        }
        );
    }
    function showPreview() {
        sync();
        d.preview=!d.preview;
    }
    function loadPages() {
        call('CmsPages', {
        }
        , function(pages) {
            d.pages=pages;
        }
        );
    }
    function place() {
        call('PlaceOnPage', {
            id: d.id, page_slug: d.pageSlug, page_title: d.pageTitle
        }
        , function(result) {
            d.pageLink=location.href.split('#')[0]+'#'+result.path;
            d.info='Package component added through CMS page settings.';
        }
        );
    }
}
);
