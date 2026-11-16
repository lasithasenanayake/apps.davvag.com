const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../components/learn/script.js'), 'utf8');
const clone = x => JSON.parse(JSON.stringify(x));
const settle = async () => { for (let n = 0; n < 20; n++) await new Promise(setImmediate); };

function store() {
    return {progress: {}, attempts: {}, cache: new Map(), calls: [], failSave: false, clock: 0};
}
function harness(db, width = 1280, student = 7, hash = '#/app/lesson-manager/learn?course_id=4&subject_id=5') {
    const exported = {}, focus = [], timers = new Map();
    let timerId = 0;
    const key = id => student + ':' + id;
    const lessons = () => [56, 57].map((id, i) => ({
        id, course_id: 4, subject_id: 5, lesson_order: i + 1, title: 'Lesson ' + id,
        subject: {id: 5, title: 'Subject'}, unlocked: id === 56 || !!db.progress[key(56)]?.lesson_completed,
        require_reading: 'true', require_video: 'true', require_quiz: 'true', require_assignment: 'true', require_teacher_approval: 'true',
        progress: clone(db.progress[key(id)] || {}),
        content: [{id: 1, title: 'Read first', body: '<p>First material</p>'}, {id: 2, title: 'Read second', body: '<p>Second material</p>'}],
        videos: [{id: 3, title: 'Watch', provider: 'direct'}],
        quizzes: [4, 5].map(id => ({id, title: 'Quiz ' + id, latest_attempt: clone(db.attempts[student + ':' + id] || null)})),
        assignmentRules: [{id: 6, assignment_id: 60, assignment: {title: 'Assignment one'}}, {id: 7, assignment_id: 70, assignment: {title: 'Assignment two'}}]
    }));
    function response(fn) {
        let pending = Promise.resolve().then(fn).then(result => ({success: true, result}));
        return {then(cb) {pending = pending.then(cb); return {error(cb) {pending.catch(cb);}};}};
    }
    const services = {
        StudentCourses: () => response(() => [{id: 4}]),
        LearningCourse: () => response(() => ({course: {id: 4, title: 'Course'}, student: {id: student}, lessons: lessons()})),
        StartLesson: b => response(() => {db.calls.push(['start', b.lesson_id]); return clone(db.progress[key(b.lesson_id)] || {});}),
        SaveLearningPosition: b => {
            if (db.failSave) return {then(cb) {cb({success: false}); return {error() {}};}};
            const commit = () => {
                db.calls.push(['position', clone(b)]);
                const p = db.progress[key(b.lesson_id)] ||= {};
                Object.assign(p, {current_section: b.section, completed_sections: b.completed_sections, learning_drafts: b.drafts, section_updated_at: String(++db.clock).padStart(12, '0')});
                return clone(p);
            };
            return response(() => db.holdSave ? new Promise(resolve => {db.releaseSave = () => {db.holdSave = false; resolve(commit());};}) : commit());
        },
        CompleteActivity: b => response(() => {
            db.calls.push(['complete', b.activity]);
            const p = db.progress[key(b.lesson_id)] ||= {};
            p[b.activity + '_completed'] = true;
            return clone(p);
        }),
        StartQuiz: b => response(() => {
            const attempt = db.attempts[student + ':' + b.quiz_id] ||= {id: b.quiz_id * 10, status: 'in_progress', answers: {}};
            return {attempt: clone(attempt), questions: [{id: 10, question_type: 'short_answer'}, {id: 11, question_type: 'multiple_answer'}]};
        })
    };
    const heading = {focus() {focus.push('section');}, scrollIntoView() {}};
    const host = {querySelector: () => heading};
    const location = {hash};
    vm.runInNewContext(source, {
        WEBDOCK: {component: () => ({register: cb => cb(exported)})},
        URLSearchParams, Promise, location,
        window: {matchMedia: () => ({matches: width <= 1000}), history: {replaceState(a,b,hash) {location.hash = hash;}}, localStorage: {
            getItem: key => db.cache.get(key) || null, setItem: (key,value) => db.cache.set(key,value), removeItem: key => db.cache.delete(key)
        }},
        document: {querySelector: () => host}, setInterval: () => 1,
        setTimeout(cb) {timers.set(++timerId, cb); return timerId;}, clearTimeout(id) {timers.delete(id);}
    });
    exported.getComponent = () => ({services});
    exported.getShellComponent = () => ({});
    exported.vue.onReady(null, {renderDiv: [host]});
    return {d: exported.vue.data, m: exported.vue.methods, location, focus, flushTimers() {const all = [...timers.values()]; timers.clear(); all.forEach(cb => cb());}};
}
async function open(app) {await settle(); if (!app.d.current) {app.m.openLesson(app.d.lessons[0]); await settle();}}

for (const width of [320, 390, 768, 1280]) test(`forward/back and refresh resume at ${width}px`, async () => {
    const db = store(), app = harness(db, width); await open(app);
    assert.equal(app.m.sections().length, 9);
    assert.equal(app.m.activeSection().key, 'reading:1');
    app.m.sectionBack(); await settle(); assert.equal(app.d.sectionIndex, 0);
    app.m.sectionNext(); await settle();
    assert.equal(app.m.activeSection().key, 'reading:2');
    assert.equal(db.progress['7:56'].reading_completed, undefined, 'first material does not finish all reading');
    app.m.sectionNext(); await settle();
    assert.equal(app.m.activeSection().key, 'video:3');
    assert.equal(db.progress['7:56'].reading_completed, true);
    app.m.sectionBack(); await settle();
    assert.equal(app.m.activeSection().key, 'reading:2');
    assert.equal(app.m.sectionDone(), true);
    const reopened = harness(db, width, 7, app.location.hash); await open(reopened);
    assert.equal(reopened.d.current.id, 56);
    assert.equal(reopened.m.activeSection().key, 'reading:2');
    assert.equal(reopened.d.current.progress.reading_completed, true);
    app.m.backToSubjects(); await settle(); app.m.selectSubject(5); await settle();
    assert.equal(app.m.activeSection().key, 'reading:2');
});

test('quiz answers and attempt survive back, other quizzes and refresh', async () => {
    const db = store(), app = harness(db); await open(app);
    app.m.reviewSection(3); await settle(); app.m.startQuiz(app.d.current.quizzes[0]); await settle();
    const attempt = app.d.quizAttempt.id;
    app.d.answers[10] = 'Keep this answer'; app.m.toggleAnswer({id: 11}, 'A');
    app.m.sectionNext(); await settle();
    app.m.startQuiz(app.d.current.quizzes[1]); await settle(); app.d.answers[10] = 'Other quiz';
    app.m.saveDraft(); app.m.sectionBack(); await settle();
    assert.equal(app.d.answers[10], 'Keep this answer');
    assert.equal(app.d.answers[11][0], 'A');
    assert.equal(app.d.quizAttempt.id, attempt);
    const refreshed = harness(db, 390, 7, app.location.hash); await open(refreshed);
    assert.equal(refreshed.m.activeSection().key, 'quiz:4');
    assert.equal(refreshed.d.answers[10], 'Keep this answer');
    assert.equal(refreshed.d.quizAttempt.id, attempt);
});

test('assignment drafts and selected files survive section navigation independently', async () => {
    const db = store(), app = harness(db); await open(app); app.m.reviewSection(5); await settle();
    app.d.assignmentForm.content = 'Draft one'; app.d.assignmentForm.file_url = 'https://example.com/work';
    app.m.chooseAssignmentFiles({target: {files: [{name: 'work.pdf', size: 20}]}}, {allowed_formats: 'pdf'});
    app.m.saveDraft(); app.m.sectionNext(); await settle();
    app.d.assignmentForm.content = 'Draft two'; app.m.saveDraft(); app.m.sectionBack(); await settle();
    assert.equal(app.d.assignmentForm.content, 'Draft one');
    assert.equal(app.d.assignmentFiles[0].name, 'work.pdf');
    const refreshed = harness(db, 390, 7, app.location.hash); await open(refreshed);
    assert.equal(refreshed.d.assignmentForm.content, 'Draft one');
    assert.equal(refreshed.d.assignmentForm.file_url, 'https://example.com/work');
    assert.equal(db.progress['7:56'].assignment_passed, undefined);
    refreshed.d.current.assignmentRules[0].latest_submission = {status: 'graded', passed: 'true'};
    assert.equal(refreshed.m.sectionDone(), true, 'uses the existing graded submission pass flag');
});

test('failed save stays on section, retries, and local drafts survive immediate refresh', async () => {
    const db = store(), app = harness(db); await open(app); app.m.reviewSection(5); await settle();
    db.failSave = true; app.d.assignmentForm.content = 'Unsynced text'; app.m.saveDraft();
    app.m.sectionNext(); await settle();
    assert.equal(app.m.activeSection().key, 'assignment:6'); assert.ok(app.d.errors.length);
    const refreshed = harness(db, 390, 7, app.location.hash); await open(refreshed);
    assert.equal(refreshed.d.assignmentForm.content, 'Unsynced text');
    db.failSave = false; app.m.sectionNext(); await settle(); assert.equal(app.m.activeSection().key, 'assignment:7');
    assert.equal(app.d.errors.length, 0, 'successful retry clears the save error');
});

test('completed summary opens the unlocked next lesson and saves its cursor', async () => {
    const db = store();
    db.progress['7:56'] = {current_section: 'summary', section_updated_at: '001', lesson_completed: true, reading_completed: true, video_completed: true, quiz_passed: true, assignment_passed: true, teacher_approved: true};
    const app = harness(db); await open(app);
    assert.equal(app.m.sectionDone(), true);
    assert.equal(app.m.sectionNextLabel(), 'Next lesson');
    app.m.sectionNext(); await settle();
    assert.equal(app.d.current.id, 57);
    assert.equal(app.m.activeSection().key, 'reading:1');
    const reopened = harness(db, 390, 7, app.location.hash); await settle();
    assert.equal(reopened.d.current.id, 57);
});

test('activity writes wait for pending draft saves', async () => {
    const db = store(), app = harness(db); await open(app);
    app.m.sectionNext(); await settle();
    db.holdSave = true; app.m.saveDraft(); app.flushTimers(); await settle();
    assert.equal(typeof db.releaseSave, 'function');
    app.m.sectionNext(); await settle();
    assert.equal(db.calls.filter(c => c[0] === 'complete').length, 0, 'completion waits for the older save');
    db.releaseSave(); await settle();
    assert.equal(db.progress['7:56'].reading_completed, true);
    assert.equal(app.m.activeSection().key, 'video:3');
});

test('resumes latest lesson in subject and isolates users', async () => {
    const db = store(); db.progress['7:56'] = {lesson_completed: true, current_section: 'summary', section_updated_at: '001'};
    db.progress['7:57'] = {current_section: 'quiz:5', section_updated_at: '002'};
    const app = harness(db); await settle(); assert.equal(app.d.current.id, 57); assert.equal(app.m.activeSection().key, 'quiz:5');
    const other = harness(db, 390, 8); await settle(); assert.equal(other.d.current, null);
});

test('summary preserves assessment rules and handles empty lessons and invalid saved sections', async () => {
    const db = store(), app = harness(db); await open(app); app.m.reviewSection(8); await settle();
    assert.equal(app.m.sectionIs('summary'), true); assert.equal(app.m.sectionDone(), false); assert.equal(app.m.canNext(), false);
    app.m.sectionNext(); assert.equal(app.d.mobileStage, 'lessons');
    db.progress['7:56'].current_section = 'reading:deleted';
    const refreshed = harness(db); await open(refreshed); assert.equal(refreshed.d.sectionIndex, 0);
    Object.assign(refreshed.d.current, {content: [], videos: [], quizzes: [], assignmentRules: [], require_reading: false, require_video: false, require_teacher_approval: false});
    assert.equal(refreshed.m.sections().length, 1); assert.equal(refreshed.m.sectionIs('summary'), true);
});
