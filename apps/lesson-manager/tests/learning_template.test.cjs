const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {JSDOM, VirtualConsole} = require('jsdom');
const partial = fs.readFileSync(path.join(__dirname, '../components/learn/partial.html'), 'utf8');
const script = fs.readFileSync(path.join(__dirname, '../components/learn/script.js'), 'utf8');
const vue = fs.readFileSync(path.join(__dirname, '../../dock/shell/soss-routes-vue/vue.min.js'), 'utf8');
const copy = value => JSON.parse(JSON.stringify(value));
const settle = async () => {for (let i = 0; i < 20; i++) await new Promise(setImmediate);};

function mount(t, progress = {}, hash = '') {
    const errors = [], dom = new JSDOM('<!doctype html><html><body><div id="idAppRenderer">' + partial + '</div></body></html>', {
        url: 'http://localhost/davvag-core/admin#/app/lesson-manager/learn' + hash,
        runScripts: 'outside-only', virtualConsole: new VirtualConsole().on('jsdomError', e => errors.push(e))
    });
    t.after(() => dom.window.close());
    const w = dom.window;
    w.setInterval = () => 1;
    w.matchMedia = () => ({matches: false});
    w.HTMLElement.prototype.scrollIntoView = function () {};
    w.eval(vue);
    // Production Vue 2.0.3 silently replaces a failed compilation with a no-op.
    const OriginalFunction = w.Function;
    w.Function = function (...args) {try {return OriginalFunction(...args);}catch(error){errors.push(error);throw error;}};
    const exported = {};
    const lesson = {
        id:56, course_id:4, subject_id:5, title:'Test lesson', unlocked:true,
        require_reading:true, require_teacher_approval:true,
        content:[{id:1,title:'First material',body:'<p>First section content</p>'},{id:2,title:'Second material',body:'<p>Second section content</p>'}],
        videos:[], quizzes:[], assignmentRules:[], progress
    };
    const response = fn => ({then(done) {Promise.resolve().then(fn).then(value => done({success:true,result:value})).catch(error => errors.push(error));return {error() {}};}});
    const services = {
        StudentCourses: () => response(() => [{id:4,title:'Test course'}]),
        LearningCourse: () => response(() => ({course:{id:4,title:'Test course'},student:{id:7},lessons:[copy(lesson)]})),
        StartLesson: () => response(() => copy(progress)),
        SaveLearningPosition: body => response(() => {Object.assign(progress, {current_section:body.section,completed_sections:body.completed_sections,learning_drafts:body.drafts});return copy(progress);}),
        CompleteActivity: body => response(() => {progress[body.activity+'_completed']=true;return copy(progress);})
    };
    exported.getComponent = () => ({services});
    exported.getShellComponent = () => ({});
    w.WEBDOCK = {component: () => ({register: fn => fn(exported)})};
    w.eval(script);
    const host = [w.document.getElementById('idAppRenderer')];
    w.Vue.config.errorHandler = error => errors.push(error);
    const app = new w.Vue(Object.assign({}, exported.vue, {el:'#idAppRenderer'}));
    exported.vue.onReady(exported.vue.data, host);
    return {w, app, errors, d:exported.vue.data, m:exported.vue.methods};
}

test('DOM-mounted learning page renders loading and course options', async t => {
    const page = mount(t);
    assert.ok(page.w.document.querySelector('.lm-learning'), 'Vue must retain the learning view during its initial mount');
    assert.match(page.w.document.body.textContent, /Loading your assigned courses/);
    await settle();
    assert.match(page.w.document.querySelector('.lm-learning-picker select').textContent, /Test course/);
    assert.equal(page.errors.length, 0, page.errors.map(e => e.message).join('\n'));
});

test('DOM mount resumes the saved section and preserves material on Back/Next', async t => {
    const page = mount(t, {current_section:'reading:2',section_updated_at:'2026-11-16 10:00:00',completed_sections:['reading:1']}, '?course_id=4&subject_id=5');
    await settle();
    assert.equal(page.w.document.querySelector('[data-lm-section-heading]').textContent, 'Second material');
    assert.match(page.w.document.querySelector('.lm-section-progress').textContent, /Section 2 of 4/);
    page.m.sectionBack(); await settle();
    assert.equal(page.w.document.querySelector('[data-lm-section-heading]').textContent, 'First material');
    page.m.sectionNext(); await settle();
    assert.equal(page.w.document.querySelector('[data-lm-section-heading]').textContent, 'Second material');
    assert.equal(page.errors.length, 0, page.errors.map(e => e.message).join('\n'));
});

test('approval and completion messages render through the DOM parser', async t => {
    const page = mount(t, {current_section:'approval',section_updated_at:'2026-11-16 10:00:00'}, '?course_id=4&subject_id=5');
    await settle();
    assert.match(page.w.document.querySelector('section.lm-note').textContent, /must approve/);
    page.d.current.progress.teacher_approved = true;
    await settle();
    assert.match(page.w.document.querySelector('section.lm-note').textContent, /has approved/);
    page.m.sectionNext(); await settle();
    const summary = page.w.document.querySelector('[role="status"].lm-note');
    assert.match(summary.textContent, /Lesson not complete yet/);
    page.d.current.progress.lesson_completed = true;
    await settle();
    assert.match(summary.textContent, /Lesson completed/);
    assert.equal(page.errors.length, 0, page.errors.map(e => e.message).join('\n'));
});
