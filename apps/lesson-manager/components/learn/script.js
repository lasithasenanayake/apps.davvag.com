WEBDOCK.component().register(function (exports) {
    var api,
        router,
        timerHandle,
        learningHost,
        draftTimer,
        positionError = false,
        saveQueue = Promise.resolve(),
        quizStates = {},
        assignmentStates = {},
        selectedAssignmentFiles = [];
    var learningRoute = '#/app/lesson-manager/learn';
    // Reactive state used by partial.html.
    var data = {
        courses: [],
        coursesLoaded: false,
        courseId: '',
        course: null,
        lessons: [],
        subjectId: '',
        studentId: '',
        sectionIndex: 0,
        completedSections: [],
        drafts: { quizzes: {}, assignments: {} },
        sectionBusy: false,
        activityBusy: false,
        saveStatus: '',
        current: null,
        mobileStage: 'choose',
        quiz: null,
        quizAttempt: null,
        quizQuestions: [],
        answers: {},
        quizResult: null,
        deadline: null,
        timeRemaining: '',
        assignmentForm: { content: '', file_url: '' },
        assignmentFiles: [],
        uploading: false,
        busyLesson: 0,
        errors: [],
        info: [],
        loading: false
    };

    // Public methods referenced by the Vue template.
    exports.vue = {
        data: data,
        methods: {
            go: go,
            loadCourse: loadCourse,
            selectSubject: selectSubject,
            subjectsForCourse: subjectsForCourse,
            lessonsForSubject: lessonsForSubject,
            openLesson: openLesson,
            backToSubjects: backToSubjects,
            backToLessons: backToLessons,
            previous: previous,
            next: next,
            canPrevious: canPrevious,
            canNext: canNext,
            markReading: markReading,
            markVideo: markVideo,
            startQuiz: startQuiz,
            submitQuiz: submitQuiz,
            chooseAssignmentFiles: chooseAssignmentFiles,
            submitAssignment: submitAssignment,
            options: options,
            toggleAnswer: toggleAnswer,
            isSelected: isSelected,
            videoEmbed: videoEmbed,
            isEmbeddable: isEmbeddable,
            statusClass: statusClass,
            courseProgress: courseProgress,
            sections: sections,
            activeSection: activeSection,
            sectionIs: sectionIs,
            sectionBack: sectionBack,
            sectionNext: sectionNext,
            sectionNextLabel: sectionNextLabel,
            sectionDone: sectionDone,
            saveDraft: saveDraft,
            reviewSection: reviewSection
        },
        onReady: init,
        beforeDestroy: function () {
            if (timerHandle) { clearInterval(timerHandle); timerHandle = null; }
            if (draftTimer) { clearTimeout(draftTimer); draftTimer = null; }
            if (data.current) persistPosition();
        }
    };
    exports.onReady = function () {};

    // Connect framework services and load the initial page data.
    function init(scope, context) {
        learningRoute = /^#\/app\/learners-circle(?:\/|\?|$)/.test(location.hash) ? '#/app/learners-circle/learn' : '#/app/lesson-manager/learn';
        learningHost = (context && context.renderDiv) || context || exports.renderDiv;
        data.errors = [];
        data.info = [];
        try {
            api = exports.getComponent('api');
            router = exports.getShellComponent('soss-routes');
        } catch (e) {
            fail('Lesson Manager could not start. Reload the page and try again.');
            return;
        }
        if (!api || !api.services || typeof api.services.StudentCourses !== 'function') {
            fail('Lesson Manager services are unavailable. Reload the page and try again.');
            return;
        }
        if (!timerHandle) timerHandle = setInterval(updateTimer, 1000);
        api.services
            .StudentCourses({})
            .then(function (r) {
                data.coursesLoaded = true;
                if (!r.success) return fail(msg(r, 'Assigned courses could not be loaded.'));
                data.courses = r.result || [];
                var query = new URLSearchParams(location.hash.split('?')[1] || '');
                var course = query.get('course_id');
                if (course) {
                    data.courseId = course;
                    loadCourse();
                }
            })
            .error(function () {
                data.coursesLoaded = true;
                fail('Assigned courses could not be loaded.');
            });
    }

    // Clear lesson-specific progress, quiz, and assignment state.
    function resetLessonState() {
        if (data.current) persistPosition();
        data.current = null;
        data.quiz = null;
        data.quizAttempt = null;
        data.quizQuestions = [];
        data.quizResult = null;
        data.deadline = null;
        data.timeRemaining = '';
        data.answers = {};
        data.assignmentForm = { content: '', file_url: '' };
        selectedAssignmentFiles = [];
        data.assignmentFiles = [];
    }

    function loadCourse() {
        if (data.activityBusy || data.sectionBusy) { data.courseId = data.current ? data.current.course_id : data.courseId; return; }
        if (draftTimer && data.current) { clearTimeout(draftTimer); draftTimer = null; persistPosition(); }
        data.subjectId = '';
        data.mobileStage = 'choose';
        data.course = null;
        data.lessons = [];
        resetLessonState();
        if (!data.courseId) return;
        data.loading = true;
        api.services
            .LearningCourse({ course_id: data.courseId })
            .then(function (r) {
                data.loading = false;
                if (!r.success) return fail(msg(r, 'Course could not be opened.'));
                applyCourse(r.result || {});
                var wanted = new URLSearchParams(location.hash.split('?')[1] || '').get(
                    'lesson_id'
                );
                if (wanted) {
                    var selected = data.lessons.filter(function (l) {
                        return String(l.id) === wanted;
                    })[0];
                    if (selected) {
                        selectSubject(selected.subject_id, false);
                        openLesson(selected);
                    }
                } else {
                    var subject = new URLSearchParams(location.hash.split('?')[1] || '').get('subject_id');
                    if (subject) selectSubject(subject);
                }
                if (!data.lessons.length) ok('This assigned course has no published lessons yet.');
            })
            .error(function () {
                data.loading = false;
                fail('Course could not be opened.');
            });
    }

    function applyCourse(x) {
        data.course = x.course || null;
        data.studentId = x.student ? x.student.id : '';
        data.lessons = (x.lessons || []).map(function (lesson) { normalizeLesson(lesson); return lesson; });
    }

    // Build the subject picker from the lessons available to the learner.
    function subjectsForCourse() {
        var seen = {},
            subjects = [];
        data.lessons.forEach(function (l) {
            var id = String(l.subject_id || '');
            if (!id || seen[id]) return;
            seen[id] = true;
            subjects.push(l.subject || { id: l.subject_id, code: 'Subject', title: 'Subject' });
        });
        return subjects;
    }

    function lessonsForSubject() {
        return data.lessons.filter(function (l) {
            return String(l.subject_id) === String(data.subjectId);
        });
    }

    function selectSubject(subject, resume) {
        if (data.activityBusy || data.sectionBusy) return;
        data.subjectId = subject && subject.id !== undefined ? subject.id : subject;
        resetLessonState();
        setMobileStage('lessons');
        if (resume !== false) {
            var saved = lessonsForSubject().filter(function (l) {
                return l.unlocked && l.progress && l.progress.current_section;
            }).sort(function (a, b) {
                return String(b.progress.section_updated_at || '').localeCompare(String(a.progress.section_updated_at || '')) || Number(b.id) - Number(a.id);
            })[0];
            if (saved) openLesson(saved);
        }
    }

    function backToSubjects() {
        if (data.activityBusy || data.sectionBusy) return;
        data.subjectId = '';
        resetLessonState();
        setMobileStage('choose');
    }

    function backToLessons() {
        if (data.activityBusy || data.sectionBusy) return;
        if (draftTimer) persistPosition();
        setMobileStage('lessons');
    }

    // Keep the selected step in view when panels change on a phone or tablet.
    function setMobileStage(stage) {
        data.mobileStage = stage;
        if (!window.matchMedia || !window.matchMedia('(max-width: 1000px)').matches) return;
        setTimeout(function () {
            if (data.mobileStage !== stage) return;
            var root = (learningHost && learningHost[0]) || learningHost;
            if (!root || !root.querySelector) root = document.querySelector('.lm-learning');
            var heading = root && root.querySelector('[data-lm-stage-heading="' + stage + '"]');
            if (!heading) return;
            heading.focus({ preventScroll: true });
            heading.scrollIntoView({ block: 'start', behavior: 'auto' });
        }, 0);
    }

    // Open lessons and refresh access after unlocking.
    function openLesson(l) {
        if (!l || (!l.unlocked && !l.credit_locked) || data.busyLesson || data.sectionBusy || data.activityBusy) return;
        if (data.current && String(data.current.id) === String(l.id)) return setMobileStage('content');
        if (draftTimer) { clearTimeout(draftTimer); draftTimer = null; persistPosition(); }
        if (
            l.credit_locked &&
            !window.confirm(
                'Unlock this lesson for ' +
                    l.required_credit_points +
                    ' credits? This purchase is permanent and cannot be repeated.'
            )
        )
            return;
        data.busyLesson = l.id;
        runActivity('StartLesson', { lesson_id: l.id }, function (r) {
                data.busyLesson = 0;
                if (!r.success) return fail(msg(r, 'Lesson could not be opened.'));
                if (l.credit_locked) {
                    ok('Lesson unlocked for ' + l.required_credit_points + ' credits.');
                    return reloadUnlockedLesson(l);
                }
                showLesson(l, r.result);
            }, function () {
                data.busyLesson = 0;
                fail('Lesson could not be opened.');
            });
    }

    function reloadUnlockedLesson(lesson) {
        api.services.LearningCourse({ course_id: data.courseId }).then(function (r) {
            if (!r.success) return fail(msg(r, 'The unlocked lesson could not be refreshed.'));
            applyCourse(r.result || {});
            var refreshed = null;
            data.lessons.forEach(function (x) {
                if (String(x.id) === String(lesson.id)) refreshed = x;
            });
            if (refreshed) showLesson(refreshed, refreshed.progress);
            else fail('The unlocked lesson is not available.');
        });
    }

    function showLesson(lesson, progress) {
        if (progress) {
            normalizeProgress(progress);
            lesson.progress = progress;
        }
        data.current = lesson;
        setMobileStage('content');
        data.quiz = null;
        data.quizAttempt = null;
        data.quizQuestions = [];
        data.quizResult = null;
        data.deadline = null;
        data.answers = {};
        data.assignmentForm = { content: '', file_url: '' };
        selectedAssignmentFiles = [];
        data.assignmentFiles = [];
        quizStates = {};
        assignmentStates = {};
        var p = data.current.progress || {};
        data.completedSections = decode(p.completed_sections, []);
        data.drafts = decode(p.learning_drafts, { quizzes: {}, assignments: {} });
        try {
            var local = JSON.parse(window.localStorage.getItem(draftKey()) || 'null');
            if (local) data.drafts = local;
        } catch (e) {}
        data.drafts.quizzes = data.drafts.quizzes || {};
        data.drafts.assignments = data.drafts.assignments || {};
        data.sectionIndex = Math.max(0, sections().map(function (s) { return s.key; }).indexOf(p.current_section));
        enterSection();
        persistPosition();
        // Refresh retains the selected course and subject without overriding the server cursor.
        if (window.history && window.history.replaceState) window.history.replaceState(null, '', learningRoute + '?course_id=' + encodeURIComponent(data.courseId) + '&subject_id=' + encodeURIComponent(data.subjectId));
    }

    function decode(value, fallback) {
        if (!value) return fallback;
        if (typeof value !== 'string') return value;
        try { return JSON.parse(value); } catch (e) { return fallback; }
    }

    function sections() {
        if (!data.current) return [];
        var l = data.current, list = [];
        function add(kind, rows, title, required) {
            (rows || []).forEach(function (row) { list.push({key: kind + ':' + row.id, kind: kind, id: row.id, title: row.title || (row.assignment && row.assignment.title) || title}); });
            if (!(rows || []).length && required) list.push({key: kind, kind: kind, title: title});
        }
        add('reading', l.content, 'Read the material', l.require_reading);
        add('video', l.videos, 'Watch the video', l.require_video);
        add('quiz', l.quizzes, 'Knowledge check');
        add('assignment', l.assignmentRules, 'Submit the assignment');
        if (l.require_teacher_approval) list.push({key: 'approval', kind: 'approval', title: 'Teacher approval'});
        list.push({key: 'summary', kind: 'summary', title: 'Lesson summary'});
        return list;
    }

    function activeSection() { return sections()[data.sectionIndex] || {}; }
    function sectionIs(kind, id) { var s = activeSection(); return s.kind === kind && (id === undefined || String(s.id) === String(id)); }
    function sectionDone() {
        var s = activeSection(), p = data.current.progress || {};
        if (s.kind === 'reading' || s.kind === 'video') return data.completedSections.indexOf(s.key) >= 0 || truthy(p[s.kind === 'reading' ? 'reading_completed' : 'video_completed']);
        if (s.kind === 'quiz') {
            var q = data.current.quizzes.filter(function (q) { return String(q.id) === String(s.id); })[0];
            return !!(q && q.latest_attempt && truthy(q.latest_attempt.passed));
        }
        if (s.kind === 'assignment') {
            var r = data.current.assignmentRules.filter(function (r) { return String(r.id) === String(s.id); })[0];
            return !!(r && r.latest_submission && truthy(r.latest_submission.passed));
        }
        return truthy(p[s.kind === 'approval' ? 'teacher_approved' : 'lesson_completed']);
    }
    function sectionNextLabel() {
        if (sectionIs('summary')) return canNext() ? 'Next lesson' : 'Back to lessons';
        return (sectionIs('reading') || sectionIs('video')) && !sectionDone() ? 'Complete and continue' : 'Next';
    }

    function draftKey() { return 'lm-drafts:' + data.studentId + ':' + (data.current.course_id || data.courseId) + ':' + data.current.id; }
    function captureDraft() {
        if (!data.current) return;
        if (data.quiz && data.quizAttempt && !data.quizResult) {
            data.drafts.quizzes[data.quiz.id] = {attempt_id: data.quizAttempt.id, answers: JSON.parse(JSON.stringify(data.answers))};
        }
        if (sectionIs('assignment')) data.drafts.assignments[activeSection().id] = JSON.parse(JSON.stringify(data.assignmentForm));
        try { if (data.studentId) window.localStorage.setItem(draftKey(), JSON.stringify(data.drafts)); } catch (e) {}
    }
    function saveDraft() {
        captureDraft();
        data.saveStatus = 'Saving…';
        clearTimeout(draftTimer);
        draftTimer = setTimeout(function () { draftTimer = null; persistPosition(); }, 400);
    }
    function persistPosition(target, done) {
        if (!data.current) return;
        clearTimeout(draftTimer); draftTimer = null;
        captureDraft();
        var lesson = data.current, key = draftKey(), drafts = JSON.stringify(data.drafts);
        var payload = {lesson_id: lesson.id, section: target || activeSection().key, completed_sections: data.completedSections.slice(), drafts: JSON.parse(drafts)};
        data.saveStatus = 'Saving…';
        saveQueue = saveQueue.then(function () {
            return new Promise(function (resolve) {
                api.services.SaveLearningPosition(payload).then(function (r) {
                    if (r.success) {
                        if (positionError) { data.errors = []; positionError = false; }
                        normalizeProgress(r.result);
                        lesson.progress = r.result;
                        normalizeLesson(lesson);
                        try { if (window.localStorage.getItem(key) === drafts) window.localStorage.removeItem(key); } catch (e) {}
                        if (data.current && String(data.current.id) === String(lesson.id)) data.saveStatus = 'Progress saved';
                        if (done) done(true);
                    } else {
                        positionError = true;
                        data.saveStatus = 'Progress could not be saved';
                        fail(msg(r, 'Progress could not be saved. Try again to continue.'));
                        if (done) done(false);
                    }
                    resolve();
                }).error(function () {
                    positionError = true;
                    data.saveStatus = 'Progress could not be saved';
                    fail('Progress could not be saved. Check your connection and try again.');
                    if (done) done(false);
                    resolve();
                });
            });
        });
    }
    // Keep this client's progress writes in order so a draft save cannot overwrite
    // a reading completion, quiz result, or assignment submission in flight.
    function runActivity(name, payload, done, failed) {
        if (draftTimer) persistPosition();
        saveQueue = saveQueue.then(function () {
            return new Promise(function (resolve) {
                api.services[name](payload).then(function (r) {
                    done(r);
                    resolve();
                }).error(function () {
                    if (failed) failed();
                    resolve();
                });
            });
        });
    }
    function leaveSection() {
        captureDraft();
        if (data.quiz) quizStates[data.quiz.id] = {quiz: data.quiz, attempt: data.quizAttempt, questions: data.quizQuestions, answers: data.answers, result: data.quizResult, deadline: data.deadline};
        if (sectionIs('assignment')) assignmentStates[activeSection().id] = {form: data.assignmentForm, files: selectedAssignmentFiles, display: data.assignmentFiles};
    }
    function enterSection() {
        data.quiz = null; data.quizAttempt = null; data.quizQuestions = []; data.answers = {}; data.quizResult = null; data.deadline = null;
        if (sectionIs('quiz')) {
            var state = quizStates[activeSection().id];
            if (state) {
                data.quiz = state.quiz; data.quizAttempt = state.attempt; data.quizQuestions = state.questions; data.answers = state.answers; data.quizResult = state.result; data.deadline = state.deadline;
            } else {
                var q = data.current.quizzes.filter(function (q) { return String(q.id) === String(activeSection().id); })[0];
                if (q && q.latest_attempt && q.latest_attempt.status === 'in_progress') startQuiz(q);
            }
        }
        var assignment = assignmentStates[activeSection().id];
        data.assignmentForm = assignment ? assignment.form : data.drafts.assignments[activeSection().id] || {content: '', file_url: ''};
        selectedAssignmentFiles = assignment ? assignment.files : [];
        data.assignmentFiles = assignment ? assignment.display : [];
        updateTimer();
        setTimeout(function () {
            var root = (learningHost && learningHost[0]) || learningHost;
            if (!root || !root.querySelector) root = document.querySelector('.lm-learning');
            var heading = root && root.querySelector('[data-lm-section-heading]');
            if (heading) { heading.focus({preventScroll: true}); heading.scrollIntoView({block: 'start', behavior: 'auto'}); }
        }, 0);
    }
    function moveSection(index) {
        if (data.sectionBusy || data.activityBusy || data.uploading || data.busyLesson || index < 0 || index >= sections().length) return;
        leaveSection(); data.sectionBusy = true;
        persistPosition(sections()[index].key, function (saved) {
            data.sectionBusy = false;
            if (!saved) return;
            data.sectionIndex = index; enterSection();
        });
    }
    function sectionBack() { moveSection(data.sectionIndex - 1); }
    function reviewSection(index) { moveSection(index); }
    function sectionNext() {
        if (data.sectionBusy || data.activityBusy || data.uploading) return;
        if (sectionIs('summary')) return canNext() ? next() : backToLessons();
        var s = activeSection();
        if (s.kind !== 'reading' && s.kind !== 'video') return moveSection(data.sectionIndex + 1);
        if (data.completedSections.indexOf(s.key) < 0) data.completedSections.push(s.key);
        var remaining = sections().some(function (x) { return x.kind === s.kind && data.completedSections.indexOf(x.key) < 0; });
        if (!remaining && data.current['require_' + s.kind] && !truthy((data.current.progress || {})[s.kind === 'reading' ? 'reading_completed' : 'video_completed'])) {
            return complete(s.kind, 'Section complete.', function () { moveSection(data.sectionIndex + 1); });
        }
        moveSection(data.sectionIndex + 1);
    }

    // Navigate through the current subject sequence.
    function currentIndex() {
        return lessonsForSubject().indexOf(data.current);
    }

    function canPrevious() {
        return currentIndex() > 0;
    }

    function canNext() {
        var list = lessonsForSubject(),
            i = currentIndex();
        return i >= 0 && i < list.length - 1 && list[i + 1].unlocked;
    }

    function previous() {
        var list = lessonsForSubject(),
            i = currentIndex();
        if (i > 0 && list[i - 1].unlocked) openLesson(list[i - 1]);
    }

    function next() {
        var list = lessonsForSubject(),
            i = currentIndex();
        if (i >= 0 && i < list.length - 1 && list[i + 1].unlocked) openLesson(list[i + 1]);
    }

    // Record completion of reading and video requirements.
    function markReading() {
        complete('reading', 'Reading marked complete.');
    }

    function markVideo() {
        complete('video', 'Video marked complete.');
    }

    function complete(activity, text, done) {
        if (data.sectionBusy) return;
        data.sectionBusy = true;
        var lesson = data.current;
        runActivity('CompleteActivity', { lesson_id: data.current.id, activity: activity }, function (r) {
                data.sectionBusy = false;
                if (r.success) {
                    normalizeProgress(r.result);
                    lesson.progress = r.result;
                    normalizeLesson(lesson);
                    ok(text);
                    refreshCurrent();
                    if (done) done();
                } else fail(msg(r, 'Progress could not be saved.'));
            }, function () { data.sectionBusy = false; fail('Progress could not be saved. Try again.'); });
    }

    function refreshCurrent() {
        var id = data.current.id;
        api.services.LearningCourse({ course_id: data.courseId }).then(function (r) {
            if (!r.success) return;
            data.lessons = (r.result.lessons || []).map(function (lesson) { normalizeLesson(lesson); return lesson; });
            data.lessons.forEach(function (x) {
                if (data.current && String(data.current.id) === String(id) && String(x.id) === String(id)) {
                    // Preserve the cursor and drafts while activity status refreshes.
                    if (data.current.progress) ['current_section', 'section_updated_at', 'completed_sections', 'learning_drafts'].forEach(function (key) { x.progress = x.progress || {}; x.progress[key] = data.current.progress[key]; });
                    data.current = x;
                }
            });
        });
    }

    // Quiz attempts, answers, and the countdown timer.
    function startQuiz(q) {
        if (data.quizAttempt && data.quiz && String(data.quiz.id) === String(q.id) && !data.quizResult) return;
        var lessonId = data.current.id;
        data.quiz = q;
        data.quizAttempt = null;
        data.answers = {};
        data.quizResult = null;
        runActivity('StartQuiz', { quiz_id: q.id }, function (r) {
            if (!data.current || data.current.id !== lessonId || !sectionIs('quiz', q.id)) return;
            if (!r.success) return fail(msg(r, 'Quiz could not be started.'));
            var x = r.result || {};
            data.quizAttempt = x.attempt;
            data.deadline = x.deadline;
            data.quizQuestions = x.questions || [];
            var restoredAnswers = {};
            data.quizQuestions.forEach(function (question) {
                var draft = data.drafts.quizzes[q.id];
                var saved = draft && String(draft.attempt_id) === String(x.attempt.id) ? draft.answers : decode(x.attempt.answers, {});
                restoredAnswers[String(question.id)] =
                    saved && saved[String(question.id)] !== undefined ? saved[String(question.id)] : question.question_type === 'multiple_answer' ? [] : '';
            });
            data.answers = restoredAnswers;
            updateTimer();
        }, function () { fail('Quiz could not be started. Try again.'); });
    }

    function submitQuiz() {
        if (data.activityBusy || data.quizResult) return;
        if (!data.quizAttempt) return fail('Start a quiz attempt first.');
        data.activityBusy = true;
        runActivity('SubmitQuiz', {
                quiz_id: data.quiz.id,
                attempt_id: data.quizAttempt.id,
                answers: JSON.parse(JSON.stringify(data.answers))
            }, function (r) {
                data.activityBusy = false;
                if (!r.success) return fail(msg(r, 'Quiz could not be submitted.'));
                data.quizResult = r.result;
                delete data.drafts.quizzes[data.quiz.id];
                saveDraft();
                ok(
                    r.result.manual_marking
                        ? 'Quiz submitted for teacher marking.'
                        : r.result.passed
                          ? 'Quiz passed. The next requirement is available.'
                          : 'Attempt saved. Review the result and retry if allowed.'
                );
                refreshCurrent();
            }, function () { data.activityBusy = false; fail('Quiz could not be submitted. Your answers have been kept.'); });
    }

    function updateTimer() {
        if (!data.deadline) {
            data.timeRemaining = '';
            return;
        }
        var seconds = Math.max(
                0,
                Math.floor((new Date(data.deadline).getTime() - Date.now()) / 1000)
            ),
            remainder = String(seconds % 60);
        data.timeRemaining =
            Math.floor(seconds / 60) + ':' + (remainder.length < 2 ? '0' + remainder : remainder);
    }

    // Assignment uploads and submission.
    function chooseAssignmentFiles(event, rule) {
        var allowed = String(rule.allowed_formats || '')
            .toLowerCase()
            .split(',')
            .map(function (x) {
                return x.trim();
            });
        var max = Number(rule.max_file_size_mb || 10) * 1048576;
        selectedAssignmentFiles = Array.prototype.slice
            .call(event.target.files || [])
            .filter(function (file) {
                var ext = file.name.toLowerCase().split('.').pop();
                return allowed.indexOf(ext) >= 0 && file.size <= max;
            });
        data.assignmentFiles = selectedAssignmentFiles.map(function (file) {
            return { name: file.name, size: file.size };
        });
        if (selectedAssignmentFiles.length !== (event.target.files || []).length)
            fail('Some files were skipped because their type or size is not allowed.');
    }

    function submitAssignment(rule) {
        if (data.uploading || data.activityBusy) return;
        data.activityBusy = true;
        var payload = {
            lesson_id: data.current.id,
            assignment_id: rule.assignment_id,
            content: data.assignmentForm.content,
            file_url: data.assignmentForm.file_url,
            files: []
        };
        if (!selectedAssignmentFiles.length) return saveAssignment(payload);
        data.uploading = true;
        var token = 'lm-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9);
        selectedAssignmentFiles.forEach(function (file, index) {
            file.uploadName =
                token + '-' + index + '-' + file.name.replace(/[^A-Za-z0-9._-]/g, '_');
        });
        exports.getAppComponent('davvag-tools', 'davvag-file-uploader', function (uploader) {
            uploader.initialize();
            uploader.upload_uncompressed(
                selectedAssignmentFiles,
                'lesson_assignment_submission',
                null,
                function () {
                    data.uploading = false;
                    var failed = selectedAssignmentFiles.some(function (file) {
                        return file.status !== true;
                    });
                    if (failed) { data.activityBusy = false; return fail('One or more files failed to upload.'); }
                    payload.files = selectedAssignmentFiles.map(function (file) {
                        return {
                            name: file.name,
                            media_reference:
                                'components/dock/soss-uploader/service/get/lesson_assignment_submission/' +
                                file.uploadName
                        };
                    });
                    saveAssignment(payload);
                }
            );
        });
    }

    function saveAssignment(payload) {
        runActivity('SubmitAssignment', payload, function (r) {
            data.activityBusy = false;
            if (r.success) {
                ok('Assignment submitted for teacher review.');
                data.assignmentForm = { content: '', file_url: '' };
                selectedAssignmentFiles = [];
                data.assignmentFiles = [];
                delete data.drafts.assignments[activeSection().id];
                saveDraft();
                refreshCurrent();
            } else fail(msg(r, 'Assignment could not be submitted.'));
        }, function () { data.activityBusy = false; fail('Assignment could not be submitted. Your draft has been kept.'); });
    }

    // Answer selection, media display, and shared helpers.
    function toggleAnswer(q, o) {
        var key = String(q.id),
            arr = data.answers[key] || [],
            i = arr.indexOf(o);
        if (i >= 0) arr.splice(i, 1);
        else arr.push(o);
        data.answers[key] = arr;
        saveDraft();
    }

    function isSelected(q, o) {
        return (data.answers[String(q.id)] || []).indexOf(o) >= 0;
    }

    function options(q) {
        return Array.isArray(q.options) ? q.options : String(q.options || '').split(',');
    }

    function isEmbeddable(v) {
        return ['youtube', 'cloudflare'].indexOf(String(v.provider).toLowerCase()) >= 0;
    }

    function videoEmbed(v) {
        var u = String(v.video_url || '');
        if (String(v.provider).toLowerCase() === 'youtube') {
            var id = '';
            if (u.indexOf('youtu.be/') >= 0) id = u.split('youtu.be/')[1].split(/[?&]/)[0];
            else if (u.indexOf('v=') >= 0) id = u.split('v=')[1].split('&')[0];
            else if (u.indexOf('/embed/') >= 0) id = u.split('/embed/')[1].split(/[?&]/)[0];
            return 'https://www.youtube.com/embed/' + id;
        }
        return u;
    }

    function normalizeLesson(l) {
        [
            'require_reading',
            'require_video',
            'require_quiz',
            'require_assignment',
            'require_teacher_approval',
            'progression_enabled'
        ].forEach(function (k) {
            l[k] = truthy(l[k]);
        });
        normalizeProgress(l.progress);
    }

    function normalizeProgress(progress) {
        if (progress)
            [
                'lesson_completed',
                'reading_completed',
                'video_completed',
                'quiz_completed',
                'quiz_passed',
                'assignment_submitted',
                'assignment_passed',
                'teacher_approved',
                'override_unlocked'
            ].forEach(function (k) {
                progress[k] = truthy(progress[k]);
            });
    }

    function courseProgress() {
        var value = 0;
        data.courses.forEach(function (c) {
            if (String(c.id) === String(data.courseId)) value = c.completion_percentage || 0;
        });
        return value;
    }

    function statusClass(v) {
        return 'lm-badge ' + String(v || 'locked').toLowerCase();
    }

    function go(p) {
        if (learningRoute.indexOf('learners-circle') !== -1) { location.hash = '#/app/learners-circle/' + p; return; }
        if (router && router.appNavigate) router.appNavigate('/' + p);
        else location.hash = '#/app/lesson-manager/' + p;
    }

    function truthy(v) {
        return v === true || v === 1 || v === '1' || v === 'true';
    }

    function msg(r, d) {
        return r.result && r.result.message ? r.result.message : d;
    }

    function fail(x) {
        data.errors = [x];
        data.info = [];
    }

    function ok(x) {
        data.info = [x];
        data.errors = [];
    }
});
