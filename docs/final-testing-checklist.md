# Peer Review Studio final testing checklist

## 1. Installation and upgrade
- Install the plugin on a clean Moodle site.
- Upgrade the plugin on a site with an existing Peer Review Studio instance.
- Confirm no upgrade errors appear.
- Confirm the activity can be added to a course.
- Confirm the scheduled task "Process pending peer review assignment queue" is registered and runs.
- Confirm the activity icon appears in the activity chooser and course page.
- Open `/mod/peerreviewstudio/index.php?id={courseid}` and confirm the instance list renders.

## 2. Activity configuration
- Create one activity with `submissiontype = onlinetext`.
- Create one activity with `submissiontype = file`.
- Create one activity with `submissiontype = both`.
- Confirm rubric defaults load correctly.
- Confirm custom rubric criteria and levels save correctly.
- Confirm workflow settings save correctly in scheduled and manual mode.
- In scheduled mode, confirm saving without a close date or review due date is rejected with a clear error.
- In manual mode, confirm the date fields are hidden.
- After a review exists, confirm the form blocks removing rubric criteria/levels but allows renaming them, and that existing rubric answers still display afterwards.

## 3. Learner submission flow
### Online text only
- Learner can submit text.
- Learner can edit the submission while submission phase is still open.
- Learner cannot submit after submission closes.

### File only
- Learner can upload one or more files.
- Uploaded files are visible after submit.
- Learner can replace/update files while submission phase is still open.

### Both
- Learner can submit only text.
- Learner can submit only file(s).
- Learner can submit text + file(s).
- Validation blocks an empty submission.

## 4. Matching and assignment
- Auto-assignment creates reviewers when eligible peers exist, up to the reviews-received target.
- Editing an existing submission does not create additional reviewer assignments.
- Submission remains queued cleanly when no eligible reviewer exists, and the cron task assigns it later once peers become available.
- Reciprocal review blocking works when enabled.
- Group-based pool restrictions work for:
  - any
  - same group
  - different group

## 5. Reviewer experience
- Reviewer sees assigned submission text.
- Reviewer can open attached submission files.
- Reviewer sees rubric criteria and levels.
- Reviewer can submit rubric selections and comments.
- Review submission updates assignment status to completed.

## 6. Author feedback experience
- Author does not see reviewer identity in received feedback.
- Author sees rubric answers after feedback release phase.
- Author sees general comments after feedback release phase.
- Feedback is hidden before feedback phase.

## 7. Tutor dashboard
- Tutor sees participant list with status, counts, queue, and grades.
- Status filter works.
- Search by learner name/email works.
- Incident list shows:
  - no reviewers
  - stuck queue
  - unassigned submissions
- Manual assignment works.
- Requeue works.
- Grade override works.

## 8. Grading
- Submission grade equals the average of the grades proposed by received reviews (rubric-driven).
- Review grade is calculated from completed assigned reviews.
- Final grade syncs to gradebook and the gradebook item maximum equals submission max + review max.
- Gradebook recalculation (course grade recalc) pulls grades via the module callbacks without errors.
- Teacher override replaces final grade without breaking stored components.

## 9. Permissions
- Student can submit/review but not access tutor dashboard.
- Teacher can access dashboard and grading actions.
- Non-author/non-assigned learner cannot access another learner's submission files.
- Assigned reviewer can access only the files for assigned submissions.

## 10. Privacy and lifecycle
- Backup and restore preserve activity structure, data, and submission files.
- Backup without "include user information" contains no submissions, reviews, or files.
- Privacy export includes submission text, submission files, authored reviews, and received feedback (without reviewer identities).
- Privacy deletion/anonymisation clears personal data and deletes submission files.
- Activity deletion removes dependent operational data cleanly.

## 11. AI second review (optional feature)
- With no provider configured, enabling AI review in the activity form fails validation with a clear message.
- With a provider configured, enabling AI review per activity reveals the AI grade weight and the two per-activity prompt fields (pre-filled with the default prompts).
- Learners see the AI transparency notice on the activity page when AI review is enabled.
- After a review is submitted, the ad-hoc task grades the submission and the review quality; the submission grade blends peer average and AI grade with the configured weight.
- Clearing a prompt field restores the default prompt behaviour.
- The AI payload includes activity name, description, rubric, and submission/review content, and never includes user names or emails.
- The AI feedback panel appears for the author only when feedback is released, with the AI disclaimer.
- The plugin privacy registry (Admin > Users > Privacy) lists the external AI provider location and the data sent to it.
- Upgrading from a site with customised site-wide prompts migrates them into existing activities.

## 12. Workflow notifications
- After a learner creates a new submission, they receive the "submission received" notification (popup and email, per user preferences) saying the work was submitted and is waiting for a peer review assignment.
- Editing an existing submission does not send a duplicate "submission received" notification.
- When auto-assignment gives a learner a peer submission to review, the reviewer receives the "review assigned" notification (including assignments created later by the cron queue task).
- When a tutor manually assigns a reviewer, the reviewer receives the "review assigned" notification.
- When a peer review is submitted, the submission author receives the "grade received" notification.
- When a teacher sets a grade override, the submission author receives the "grade received" notification; clearing the override sends nothing.
- Notifications link back to the activity view page.
- The three providers appear under Preferences > Notification preferences ("Peer Review Studio") and can be disabled per user.
- Notifications arrive in the recipient's language (test with one user in Spanish and one in English).

## 13. Automatic follow-up of unresolved reviews
- With default thresholds (reminder 2 days, reassignment 4 days), a reviewer who ignores an assignment receives exactly one reminder notification after 2 days of inactivity (cron task run required).
- After 4 days of inactivity the assignment is withdrawn: the reviewer gets the "review withdrawn" notification, the submission is queued again, and a different learner receives the "review assigned" notification.
- In scheduled mode the inactivity clocks start at the review phase opening (timeclose), not at assignment time, and nothing fires during the submission phase.
- Setting either threshold to 0 disables that layer; the form rejects negative values and a reassignment threshold lower than or equal to the reminder threshold.
- A withdrawn reviewer is never reassigned the same submission again.
- After 3 automatic reassignments (or when the queue has been pending longer than the reassignment threshold with no eligible reviewer, or when the scheduled review deadline passes), a submission with zero received reviews is escalated once: tutors with the manage-assignments capability receive the "tutor escalation" notification.
- With AI review + AI fallback enabled and a provider configured, escalation instead runs an ad-hoc AI review: the submission gets the AI grade (standing in fully while there are zero peer reviews) and AI feedback, its status closes (feedback becomes visible in open mode), and the author gets the "grade received" notification.
- If a real peer review arrives after the fallback, the submission grade switches back to the peer-average path.
- A manual requeue from the tutor dashboard resets the escalation flag so the automatic cycle can run again.
- After the scheduled review deadline passes, dead open assignments are cancelled automatically and their reviewers notified.

## 14. Regression smoke checks
- No PHP errors in page load, submit, review, requeue, manual assign, or grade override flows.
- No broken links for submission file downloads.
- Existing activities still load after plugin upgrade.
