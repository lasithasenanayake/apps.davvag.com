# Study and Extend the Course Manager Application

Study the existing application located at:

`C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\course-manager`

follow developmet instruction 
`C:\xampp\htdocs\davvag-core\DAVVAG-Framework-App-Development-AI-Context.md`

The goal is to extend the existing **Course Manager** into a complete teaching and learning platform where I can publish courses, lessons, books, written teaching material, videos, quizzes, and assignments.

Before making changes, review the existing application structure, features, database models, services, components, user roles, marking system, course assignment system, and established DAVVAG Framework conventions.

Reuse and extend existing Course Manager functionality wherever possible. Do not recreate features that already exist, especially user assignment, course management, marks, results, permissions, or progress tracking.

## Main Objective

Create a structured learning experience where:

1. A user can access subjects or courses assigned to them.
2. Each course contains subjects, and each subject contains lessons arranged in a specific order.
3. Every lesson must belong to a subject; a lesson must never be created directly under a course.
4. Lessons may contain written content, books, documents, videos, quizzes, and assignments.
5. Users must complete the current lesson requirements before accessing the next lesson in that subject.
6. Teachers can review student progress, assignments, quiz results, and marks.

## 1. Assigned Subjects and Courses

Users must be able to view and select the subjects or courses assigned to them through the existing Course Manager application.

The student dashboard should show:

* Assigned subjects or courses
* Course title and description
* Teacher or course owner
* Completion percentage
* Current lesson
* Completed lessons
* Pending quizzes
* Pending assignments
* Total marks earned
* Course status

Users must not access courses that have not been assigned or made available to them.

## 2. Course, Subject, and Lesson Structure

A course must support multiple subjects. Each subject must support multiple lessons arranged in a defined order.

The subject is the authoritative parent of a lesson. The lesson creation workflow must require the teacher to:

1. Select a course.
2. Select a subject belonging to that course.
3. Create and order lessons under the selected subject.

The application must reject lesson creation when no subject is selected, when the subject does not exist, or when the subject does not belong to a course. The lesson's course reference must be derived from the selected subject rather than accepted independently from the client.

Lesson ordering and progression locks apply within a subject. An incomplete lesson in one subject must not block lessons in another subject under the same course.

Each lesson should support:

* Lesson title
* Lesson description
* Written lesson content
* Chapters or sections
* Book content
* Images
* Downloadable resources
* Supporting links
* Video content
* Quiz requirement
* Assignment requirement
* Passing mark
* Lesson order
* Publishing status
* Availability date
* Completion requirements

The lesson manager must allow lessons to be reordered.

Students should clearly see:

* Previous lesson
* Current lesson
* Next lesson
* Lesson completion status
* Quiz status
* Assignment status
* Marks received

## 3. Video and Media Support

A lesson must support video content from multiple sources, including:

* YouTube links
* Facebook video links
* Cloudflare Stream
* Other external video streaming services
* Custom uploaded video services
* Locally uploaded video files
* Direct video URLs
* Embedded video players

Each video should support:

* Video title
* Video provider
* Video URL or media reference
* Thumbnail
* Duration
* Transcript
* Caption or subtitle file
* Manual transcript entry
* Automatically retrieved transcript where available

The application should use the transcript together with the written lesson content when generating quizzes.

When an automatic transcript cannot be retrieved, the teacher must be able to enter or upload the transcript manually.

## 4. AI Quiz Generation
use `C:\xampp\htdocs\davvag-core\davvag-core\localhost\apps\ai-agent-creator` to get this done. 
Create a quiz-generation workflow that allows the teacher to select a lesson and automatically generate quiz questions by analysing:

* The written lesson content
* Book or chapter content
* Video transcripts
* Uploaded documents
* Teacher-provided notes

The teacher must be able to review and edit all generated questions before publishing the quiz.

The quiz creator should support:

* Multiple-choice questions
* True or false questions
* Multiple-answer questions
* Fill-in-the-blank questions
* Short-answer questions
* Question explanations
* Difficulty levels
* Marks per question
* Negative marking where enabled
* Random question order
* Random answer order
* Attempt limits
* Quiz time limit
* Passing percentage
* Automatic marking
* Manual marking where required

The teacher should be able to:

* Select a course
* Select a subject belonging to the selected course
* Select a lesson
* Generate a quiz
* Add questions manually
* Edit generated questions
* Remove unsuitable questions
* Change correct answers
* Set marks
* Set the passing score
* Preview the quiz
* Publish or unpublish the quiz

## 5. Lesson Progression and Access Control

Lessons must follow the order defined by the teacher.

When lesson progression is enabled, a student must not access the next lesson until the requirements of the current lesson are completed.

Possible completion requirements include:

* Reading the lesson
* Watching the required video
* Passing the lesson quiz
* Uploading an assignment
* Receiving a passing assignment mark
* Teacher approval
* Completing all required lesson activities

If a lesson has a quiz:

* The student must complete the quiz.
* The student must achieve the required passing mark.
* The next lesson should unlock automatically after the quiz is passed.
* Failed attempts should follow the configured retry rules.
* The student should see the score, passing mark, attempt number, and result.

If an assignment requires teacher marking, the next lesson may remain locked until the teacher awards a passing mark or approves the submission.

The teacher should be able to override lesson locks when necessary.

## 6. Assignment Management

A lesson may include an assignment.

The teacher should be able to configure:

* Assignment title
* Instructions
* Supporting files
* Submission type
* Due date
* Maximum marks
* Passing marks
* Allowed file formats
* Maximum file size
* Maximum number of submissions
* Resubmission rules
* Late submission rules
* Whether teacher approval is required

Students should be able to submit:

* Documents
* PDFs
* Images
* Videos
* Audio files
* ZIP files
* Text responses
* External links
* Other permitted file types

Each assignment submission should record:

* Student
* Course
* Lesson
* Assignment
* Submission date
* Submitted files
* Written response
* Submission status
* Attempt number
* Teacher feedback
* Marks awarded
* Pass or fail status
* Review date

## 7. Teacher Submission Review

Create a lesson-management area where teachers can review student submissions.

Teachers should be able to filter submissions by:

* Course
* Subject
* Lesson
* Assignment
* Student
* Submission status
* Marking status
* Pass or fail status
* Submission date

The teacher should be able to:

* Open submitted files
* Read text responses
* View submission history
* Add feedback
* Award marks
* Mark the submission as passed or failed
* Request resubmission
* Approve completion
* Unlock the next lesson where applicable

## 8. Marks and Results

Integrate with the marks and results functionality already available in Course Manager.

Marks may come from:

* Lesson quizzes
* Assignments
* Manually marked questions
* Teacher-awarded participation marks
* Final assessments
* Other existing Course Manager assessment types

The system should calculate and display:

* Quiz marks
* Assignment marks
* Lesson total
* Course total
* Percentage
* Pass or fail result
* Completion percentage
* Overall grade where enabled

Avoid creating a separate duplicate marking system if Course Manager already contains suitable functionality.

## 9. Student Learning Interface

The student learning page should provide a clear, focused experience containing:

* Course navigation
* Lesson list
* Locked and unlocked lesson indicators
* Current lesson content
* Video player
* Written teaching content
* Resources and downloads
* Quiz section
* Assignment section
* Progress information
* Marks
* Teacher feedback
* Previous and next lesson controls

The interface should clearly explain why a lesson is locked, for example:

* Complete the previous lesson.
* Pass the quiz with at least 70%.
* Submit the required assignment.
* Wait for teacher approval.
* Receive a passing assignment mark.

## 10. Teacher and Administrator Management

Teachers and administrators should be able to manage:

* Courses
* Subjects
* Course assignments
* Student enrolments
* Lessons
* Lesson ordering
* Written content
* Books and chapters
* Video sources
* Transcripts
* Quizzes
* Quiz questions
* Assignments
* Submissions
* Marks
* Progress
* Completion rules
* Publishing status

Respect the existing Course Manager roles, permissions, services, and access-control system.

## 11. Progress Tracking

Track each student’s progress at lesson and course level.

Progress records should include:

* Lesson started
* Lesson viewed
* Video viewed
* Quiz started
* Quiz completed
* Quiz passed
* Quiz attempts
* Assignment submitted
* Assignment reviewed
* Assignment passed
* Lesson completed
* Next lesson unlocked
* Course completed
* Completion date

Progress should remain consistent even when users log out and return later.

## 12. Notifications

Where the DAVVAG Framework already provides notification functionality, use it for events such as:

* New course assigned
* New lesson published
* Assignment requested
* Assignment submitted
* Submission reviewed
* Marks awarded
* Resubmission requested
* Quiz passed
* Quiz failed
* Next lesson unlocked
* Course completed

## 13. Reporting

Provide reporting views for teachers and administrators showing:

* Course enrolments
* Student progress
* Lesson completion
* Quiz attempts
* Quiz pass rates
* Assignment submissions
* Pending marking
* Marks and grades
* Failed students
* Inactive students
* Completed courses

Reports should support filtering by course, lesson, student, teacher, date, and completion status.

## 14. Important Implementation Constraints

* Study the existing Course Manager application before making changes.
* Follow the existing DAVVAG Framework application structure and conventions.
* Extend existing models and services where appropriate.
* Require `subject_id` for every lesson and derive the lesson's `course_id` from the Course Manager subject record.
* Filter the subject selector by the selected course and list lessons only for the selected subject.
* Keep lesson ordering and prerequisite progression scoped to the subject.
* Do not duplicate existing Course Manager features.
* Preserve existing course, user, subject, marks, and assignment data.
* Maintain compatibility with the existing application.
* Use existing authentication, roles, permissions, UI components, services, and storage systems.
* Keep new functionality modular so it can later support additional content providers and assessment types.
* Do not redesign unrelated parts of the Course Manager application.

## Expected Final Result

The completed application should allow a teacher to:

1. Create or select a course.
2. Select a subject under the course.
3. Add ordered lessons under the selected subject.
4. Add written content, books, resources, and videos.
5. Add or retrieve video transcripts.
6. Generate quizzes from lesson text and transcripts.
7. Review and edit generated quiz questions.
8. Set quiz passing requirements.
9. Add assignments to lessons.
10. Assign courses to users.
11. Review student submissions.
12. Provide feedback and award marks.
13. Track student progress.
14. Control access to the next lesson within each subject.
15. View course and student reports.

A student should be able to:

1. View assigned subjects and courses.
2. Open available lessons in order.
3. Read lesson material and watch videos.
4. Complete quizzes.
5. Upload assignments.
6. Receive marks and feedback.
7. Unlock the next lesson after meeting the required conditions.
8. Track overall course progress and results.
