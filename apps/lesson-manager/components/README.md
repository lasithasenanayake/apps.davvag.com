# Lesson Manager components

These files are the editable browser source for Lesson Manager. They are loaded directly by WEBDOCK; there is no frontend build step for these components.

## Where to make changes

| Folder | Purpose |
| --- | --- |
| dashboard | Overview statistics and sample data action |
| studio | Lessons, materials, videos, and assignment links |
| quiz-studio | Quizzes, questions, generation, and previews |
| learn | Learner navigation, lesson access, completion, quizzes, and submissions |
| submissions | Teacher reviews of assignments and quiz attempts |
| reports | Progress reports and manual overrides |
| settings | Video provider settings and account connections |
| lesson-style | Shared appearance and responsive layouts |

Each page has three files:

- **partial.html**: visible text, fields, buttons, and Vue bindings.
- **script.js**: reactive state, event handlers, and service calls.
- **component.json**: component metadata and the resources WEBDOCK loads.

Shared CSS lives in **lesson-style/lesson-manager.css**. Backend endpoints live outside this folder in **../services/api/service.php**. App routing and registration are configured in **../app.json**.

## Reading a script

The registration callback owns the component state. The `data` object supplies values to the template, while `exports.vue.methods` exposes the functions used by `v-on` and template expressions. `exports.vue.onReady` points to `init`, which connects the API and router.

Functions are separated with blank lines, and comments identify the main workflows. Keep method names in sync with the template when renaming them. Keep API payload field names in sync with the backend.

## Example: change the studio subject picker

In **studio/script.js**, `subjectsForCourse()` filters `data.subjects` by `data.courseId`. It converts both IDs to strings because IDs can arrive as numbers or strings. `onCourseChange()` selects the first matching subject, then `loadLessons()` fetches that subject's lessons.

In **studio/partial.html**, search for `subjectsForCourse()` to find the subject dropdown. Change the option text there; change filtering behavior in the JavaScript function. Default values for a new lesson are in `newLesson()`.

## Formatting and checks

The local **.prettierrc.json** defines four-space indentation, a 100-column target, and the existing JavaScript quote style. A Prettier-enabled editor can use it when saving these files. Keep the checked-in source expanded so it remains easy to edit.

After changing JavaScript, run `node --check path/to/script.js`. After changing a template or CSS, open the affected page and check both desktop and mobile layouts. When changing a workflow, check its save action and any related learner view.
