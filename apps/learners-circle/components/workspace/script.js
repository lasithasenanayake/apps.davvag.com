WEBDOCK.component().register(function (exports) {
    'use strict';
    var api, revision = 0;
    var data = { active: 'dashboard', loading: false, error: '', overview: null };
    var titles = { dashboard: 'Your learning, at a glance', learn: 'My learning', 'my-enrolments': 'My enrolments', catalog: 'Find your next chapter', package: 'Package details' };

    exports.vue = {
        data: data,
        computed: { pageTitle: function () { return titles[this.active] || titles.dashboard; } },
        methods: { load: load, courseLink: courseLink, number: number, bar: bar, signIn: signIn },
        directives: {
            circleContent: {
                inserted: mountContent,
                componentUpdated: function (element, binding) { if (binding.value !== binding.oldValue) mountContent(element, binding); },
                unbind: disposeContent
            }
        },
        onReady: function () {
            var path = location.hash.split('?')[0].replace(/^#\/app\/learners-circle\/?/, '').replace(/\/$/, '');
            data.active = Object.prototype.hasOwnProperty.call(titles, path) ? path : 'dashboard';
            data.error = '';
            data.overview = null;
            revision++;
            if (data.active === 'dashboard') load();
        },
        beforeDestroy: function () { revision++; }
    };
    exports.onReady = function () {};

    function load() {
        var request = ++revision;
        data.loading = true;
        data.error = '';
        exports.getAppComponent('lesson-manager', 'api', function (component) {
            if (request !== revision) return;
            api = component;
            if (!api || !api.services || !api.services.LearnerOverview) return fail('The learning dashboard is unavailable.');
            api.services.LearnerOverview({}).then(function (response) {
                if (request !== revision) return;
                data.loading = false;
                if (!response.success) return fail(message(response));
                data.overview = response.result;
            }).error(function () {
                if (request === revision) fail('Your learning dashboard could not be loaded. Please try again.');
            });
        });
    }

    function mountContent(element, binding) {
        disposeContent(element);
        var token = {};
        element.circleToken = token;
        var choices = { learn: ['lesson-manager', 'learn'], enrolments: ['lesson-market-place', 'my-enrolments'], catalog: ['lesson-market-place', 'catalog'], package: ['lesson-market-place', 'package-card'] };
        var choice = choices[binding.value];
        if (!choice) return;
        function current() { return element.circleToken === token && document.documentElement.contains(element); }
        exports.getAppComponent('davvag-tools', 'davvag-app-downloader', function (loader) {
            if (!current()) return;
            if (!loader || !loader.RenderHTML) { element.textContent = 'This part of your learning space could not be loaded.'; return; }
            // The source components retain their own services, permissions, and progress logic.
            var host = document.createElement('div');
            host.setAttribute('webdock-app', choice[0]);
            host.setAttribute('webdock-component', choice[1]);
            element.appendChild(host);
            loader.RenderHTML($(element), null, function () {}, null,
                binding.value === 'catalog' ? { exclude_enrolled: true } : {}, { isCurrent: current });
        });
    }

    function disposeContent(element) {
        element.circleToken = null;
        var nodes = [element].concat(Array.prototype.slice.call(element.querySelectorAll('*')));
        var destroyed = [];
        nodes.forEach(function (node) {
            var instance = node.__vue__;
            if (instance && destroyed.indexOf(instance) === -1) { destroyed.push(instance); instance.$destroy(); }
        });
        element.innerHTML = '';
    }

    function courseLink(course) {
        var url = '#/app/learners-circle/learn?course_id=' + encodeURIComponent(course.id);
        if (course.current_lesson) url += '&lesson_id=' + encodeURIComponent(course.current_lesson.id);
        return url;
    }
    function number(value) { return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 1 }); }
    function bar(value) { return Math.max(0, Math.min(100, Number(value) || 0)); }
    function signIn() { sessionStorage.setItem('redirecturl', location.href); location.hash = '#/app/userapp/login'; }
    function message(response) { return typeof response.result === 'string' ? response.result : response.result && response.result.message || response.message || 'Your learning dashboard could not be loaded.'; }
    function fail(value) { data.loading = false; data.error = value; }
});
