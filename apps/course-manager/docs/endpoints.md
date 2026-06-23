# Course Manager Endpoints

`SOSSData` is the framework facade. It chooses a datastore adapter for the active tenant, then the adapter uses the tenant schema files to read or write data.

`Schemas live under the active tenant: {TENANT_RESOURCE_LOCATION}/schemas/{namespace}.json`

Base path:

```text
/components/course-manager/api/service/{HandlerName}
```

Core endpoints:

| Method | Handler | Purpose |
| --- | --- | --- |
| GET | EndpointCatalog | Returns a compact endpoint list. |
| POST | Dashboard | Returns dashboard counts and recent rows. |
| POST | SeedSampleData | Creates CS101 sample course, subjects, cohort, and scales. |
| POST | ListProfiles | Lists tenant profiles for teacher/student selection. |
| POST | ListCourses / SaveCourse / DeleteCourse | Course CRUD. |
| POST | ListSubjects / SaveSubject / DeleteSubject | Subject CRUD. |
| POST | ListProducts / SaveProduct / DeleteProduct | Product mapping CRUD. |
| POST | ListClassGrades / SaveClassGrade / DeleteClassGrade | Cohort CRUD. |
| POST | ListEnrollments / SaveEnrollment / DeleteEnrollment | Enrollment CRUD with capacity checks. |
| POST | BulkImportEnrollments | CSV or row-array enrollment import. |
| POST | ListClassrooms / SaveClassroom / DeleteClassroom | Room CRUD. |
| POST | ListTimetable / CreateTimetable / DeleteTimetable | Timetable CRUD with teacher and room conflict checks. |
| POST | ListAttendance / RecordAttendance / QrCheckIn | Attendance recording and QR check-in. |
| POST | ExportAttendanceCsv | Returns `{ fileName, csv }`. |
| POST | ListAssignments / CreateAssignment / DeleteAssignment | Assignment CRUD. |
| POST | ListSubmissions / SubmitAssignment / DeleteSubmission | Submission CRUD. |
| POST | GradeSubmission | Applies late penalty, computes scale grade, updates mark. |
| POST | ListAssessments / SaveAssessment / DeleteAssessment | Assessment CRUD. |
| POST | ListMarks / SaveMark / DeleteMark | Gradebook mark CRUD. |
| POST | ListGradingScales / CreateGradingScale / DeleteGradingScale | Scale CRUD. |
| GET | ComputeGrade?marks=87 | Matches active grading scale. |
| POST | RecomputeGrades | Recomputes grade letters for existing marks. |
| POST | FinalGrade | Weighted final-grade aggregation. |
| POST | QueueNotification | Queues a notification row. |

CSV import columns:

```text
student_id,student_name,student_email,class_grade_id,status
```

QR check-in code:

```text
cm-slot-{timetable_slot_id}
```

Registration:

- App folder: `{TENANT_RESOURCE_LOCATION}/apps/course-manager`
- Schemas: `{TENANT_RESOURCE_LOCATION}/schemas/course_manager_*.json`
- Registered in: `tenant.json`, `sysadmin.json`, and `web_user.json`
