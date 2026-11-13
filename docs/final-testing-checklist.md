# Peer Review Studio manual testing checklist

The automated tests (`tests/`) cover the logic behind every section below.
This list is for checking the screens by hand on a real site, with cron
running. Items marked (auto) are also covered by a PHPUnit test.

## 1. Installation and upgrade
- Install the plugin on a clean Moodle site; upgrade it on a site that has
  activities. No errors in either case.
- After an upgrade, `php admin/cli/check_database_schema.php` reports
  "Database structure is ok".
- The scheduled task "Match reviewers and follow up pending peer reviews" is
  registered and runs every 10 minutes.
- The activity icon appears in the activity chooser and on the course page.
- `/mod/peerreviewstudio/index.php?id={courseid}` lists the activities.

## 2. Activity settings
- The form opens with the default rubric (3 criteria, 4 levels).
- Scheduled mode without a close date or a review due date is rejected with a
  clear message; the dates are hidden in manual and open modes.
- The reassignment threshold must be greater than the reminder threshold.
- A criterion with fewer than 2 levels, or whose levels all score 0, is
  rejected.
- After a review exists, removing a criterion or a level is rejected, while
  renaming them or changing weights and scores is accepted and the stored
  grades follow (auto).
- Grade category and grade to pass are saved to the grade item.
- The completion section offers "Learners must submit their work" and
  "Learners must complete the required peer reviews" (auto).
- Changing the maximum submission grade rescales the stored grades (auto).

## 3. Learner submission
- Online text, file, and "both" activities each accept and validate their
  content; an empty submission is rejected.
- The learner can edit the submission until it is matched with reviewers;
  after that the page says it can no longer be edited (auto).
- Scheduled mode: before the open date the page says when submissions open;
  after the close date it says submissions are closed.

## 4. Matching
- Open mode: reviewers are matched as submissions arrive (auto).
- Scheduled mode: nobody is matched or notified until the close date; then
  the scheduled task matches everybody (auto).
- Manual mode: matching happens in the allocation and review phases;
  reviewers are told when the review phase opens (auto).
- A learner who left the course, or whose enrolment is suspended, is not
  matched (auto).
- Reciprocal blocking and the group pool modes (any, same group, different
  group) restrict the reviewers as described (auto).
- "Match reviewers now" on the dashboard runs the matching immediately.

## 5. Reviewer experience
- The reviewer sees the list of their reviews with a state each, the work to
  review (text and files), and the rubric form.
- A review without a level for every criterion, or without an overall
  comment, is rejected.
- With several reviews pending, "Start" switches to another one.
- After submitting, the review shows as completed and the progress counter
  goes up.

## 6. Author feedback
- Before release the page says when feedback will be available.
- After release the author sees the grade breakdown, each review (rubric
  answers, comments, proposed grade) without reviewer identities, and the AI
  assessment when there is one.
- Open mode: feedback appears as soon as the submission has all its reviews.

## 7. Tutor dashboard and submission page
- The dashboard shows the counters, the "needs attention" list, and the
  participants table with filter, search, and paging (auto).
- Email addresses appear, and are searched, only when the site shows them to
  that tutor.
- With groups, the group selector filters the dashboard; in separate groups a
  tutor without "access all groups" only sees their groups, and cannot open
  the submission page of other learners (auto for the filter).
- The submission page shows the work, files, grade breakdown, every reviewer
  with the review or its state, and the AI assessments.
- From it: assign a reviewer by hand, withdraw open reviews and match again,
  set or clear the final grade. A non-numeric grade is rejected with a
  message. Each action is logged as an event.

## 8. Grading
- Submission grade = average of the grades proposed by the reviews received;
  reviewing grade = share of the required reviews completed (auto).
- Grades reach the gradebook when feedback is released, not before; a
  submission nobody reviewed stays ungraded (auto).
- A teacher override is published at once and replaces the final grade (auto).
- "Regrade" in the gradebook pulls the same values.

## 9. Permissions
- A student cannot open the dashboard or `submission.php`.
- A non-editing teacher can use the dashboard and override grades.
- A learner can download a submission file only if they are its author or a
  reviewer matched to it; the file is sent as a download.
- A site administrator sees the dashboard, not the learner area.

## 10. Notifications
- Submission received (once, not on edits); review assigned (once the review
  can be started); reminder; review withdrawn; feedback available (once);
  digest to tutors for submissions without review (auto for all).
- Messages arrive in the recipient's language and link to the activity.
- The providers appear under Preferences > Notification preferences.

## 11. Automatic follow-up
- Reminder after the reminder threshold, exactly once (auto).
- Withdrawal and reassignment after the reassignment threshold; a withdrawn
  reviewer never gets the same submission again (auto).
- Scheduled mode: the clocks start at the close date; after the review due
  date open reviews are cleared and unreviewed submissions are escalated (auto).
- Escalation happens once per submission; a tutor action restarts the cycle
  (auto).

## 12. AI review (optional)
- Plugin settings: the page says whether the site has an AI provider for
  "Generate text" and links to the AI providers page; "Test AI review"
  reports success or the provider's error.
- With no provider available, or with AI tools turned off for the course
  (Moodle 5.1+), enabling AI review in an activity is rejected.
- Learners see the AI notice when AI review is enabled. A learner who has
  not accepted the site AI policy sees the policy and can accept it; the
  notice then changes.
- Content of a learner who has not accepted the AI policy is never sent;
  once they accept, the evaluations that were waiting are queued (auto).
- Each request appears in the site AI usage report for the learner who
  wrote the content (auto, through the real AI subsystem with a fake HTTP
  response).
- After a review, the AI task grades the submission and the review; the
  grades blend with the configured weight (auto, with a fake provider).
- A submission with a PDF or an image is not graded by the AI (auto).
- AI fallback: an unreviewed submission is graded by the AI and closed; if
  the AI cannot do it, the tutors are notified (auto).
- Nothing sent to the provider contains names or emails (auto).
- A real request with the provider and model of the site: "Test AI review".

## 13. Calendar, completion, and course tools
- Scheduled mode creates two calendar events; the timeline offers "Submit my
  work" and "Complete your reviews" to the learners who still have to (auto).
- The activity page shows the dates (auto).
- Completion ticks when the enabled rules are met (auto).
- Course reset removes submissions, reviews, files, and grades, and shifts
  the dates (auto).
- Backup and restore with user data keeps submissions, reviews, rubric
  answers, and files; without user data only the design; restoring with logs
  works (auto). Duplicating the activity copies the design only (auto).

## 14. Privacy
- The privacy registry lists the tables, the files and messaging subsystems,
  and the AI subsystem (auto).
- Exporting a learner's data includes the submission, its files, the
  feedback received without reviewer identities, and the reviews written
  (auto).
- Deleting an author erases the work and frees the reviewers; deleting a
  reviewer keeps the grade they proposed but removes identity and comments
  (auto).
