# Peer Review Studio (mod_peerreviewstudio)

Peer Review Studio is a Moodle activity for structured peer review. Each
learner submits work (online text, files, or both), reviews the work of peers
with a teacher-defined rubric, and receives anonymous feedback and a grade.
Reviewers are matched automatically, inactive reviewers are reminded and
replaced, and tutors follow everything from a dashboard where they can step in.

## Features

- **Submissions**: online text, file upload, or both. A submission can be
  edited until it is matched with reviewers; from then on it is frozen, so
  reviewers assess the same work the author is graded on.
- **Three workflow modes**:
  - *Scheduled*: submissions until a close date, reviews until a due date,
    then feedback and grades are released.
  - *Manual*: the teacher switches phase (submission, allocation, review,
    feedback, closed).
  - *Open (self-paced)*: no dates; work is matched continuously and each
    learner gets feedback when their submission has all its reviews.
- **Automatic reviewer matching**: spreads the reviewing load evenly, only
  uses active participants who can review, never repeats a reviewer on the
  same submission, and can block reciprocal reviews or restrict reviewers to
  the same or a different course group.
- **Rubric reviews**: up to 5 weighted criteria with 2 to 4 scored levels,
  a comment per criterion, and an overall comment. The rubric produces the
  grade each review proposes.
- **Anonymous feedback**: authors see rubric answers and comments, never who
  wrote them.
- **Automatic follow-up**: reminders to inactive reviewers, withdrawal and
  reassignment of stale reviews, and escalation of submissions that received
  no review (AI fallback review, or one digest to the tutors).
- **Tutor dashboard**: progress per participant, filters, group selector, and
  a "needs attention" list. Each participant opens a detail page with the
  submission, every review received, the grade breakdown, and the actions:
  assign a reviewer by hand, match again, or set the final grade.
- **Grading**: submission grade = average of the grades proposed by the
  reviews received; optional reviewing grade for completing the required
  reviews. Grades reach the gradebook when feedback is released. Supports
  grade category, grade to pass, and teacher override.
- **Optional AI second review** through the Moodle AI subsystem, with any AI
  provider the site has set up: grades each submission (blended with the
  peer average by a configurable weight) and assesses the quality of each
  review. Prompts are per activity.
- **Moodle integration**: activity completion rules (submit; complete the
  required reviews), calendar and timeline deadlines, activity dates,
  notifications, groups and groupings, course reset, backup and restore with
  user data, events, and the Privacy API.

## Requirements

- Moodle 4.5 to 5.3.
- For the optional AI review: an AI provider enabled in the site with the
  "Generate text" action.
- PHP 8.1 or later (as required by the Moodle version).
- Cron running: reviewer matching in scheduled mode, reminders, and the
  release of feedback happen in a scheduled task that runs every 10 minutes.

## Installation

1. Copy the plugin folder to `{moodle}/mod/peerreviewstudio`
   (`{moodle}/public/mod/peerreviewstudio` from Moodle 5.1).
2. Visit **Site administration > Notifications** to complete the installation.
3. Add a *Peer Review Studio* activity to a course.

Or install the packaged zip from **Site administration > Plugins > Install
plugins**.

## Quick start

1. Create the activity and choose the submission type.
2. Pick a workflow mode. Scheduled mode needs *Close submissions* and
   *Reviews due*.
3. Set how many reviews each learner must complete and how many each
   submission should receive (2 and 2 by default).
4. Adjust the rubric.
5. Learners submit, review the peers assigned to them, and read their
   feedback. You supervise from the dashboard.

Once peer reviews exist, the rubric structure is locked: labels,
descriptions, weights, and scores can still be edited (stored grades are
recalculated), but criteria and levels cannot be removed.

## Grading model

- **Submission component** (*Maximum submission grade*): every review
  proposes a grade on this scale from the weighted rubric selections. The
  submission grade is the average of the proposals received.
- **Reviewing component** (*Maximum review grade*): proportional credit for
  completing the required number of reviews. Set to 0 to disable.
- **Final grade** = submission + reviewing, unless the teacher sets an
  override. A submission that received no review is left ungraded, and the
  tutors are told, instead of getting a zero.
- **Release**: grades and feedback are published together: in the feedback
  phase (scheduled and manual modes), or as soon as a submission has all its
  reviews (open mode). A teacher override is published at once.

## AI review (optional)

AI review uses the Moodle AI subsystem (core_ai). The plugin has no API key
or model of its own and makes no request to any external service itself: it
asks Moodle for a "Generate text" action, and Moodle sends it to the AI
provider the site administrator set up in **Site administration > General >
AI > AI providers**. The site's rate limits and AI usage reports apply. The
plugin settings page shows whether a provider is available and links to a
page that tests it. Teachers then enable AI review per activity.

- A learner's content is only sent to the AI once that learner has accepted
  the site AI policy. Learners are shown the policy in the activity and can
  accept it there. Until they do, their work is reviewed by peers only.
- When AI tools are turned off in the course settings (Moodle 5.1 or
  later), AI review cannot be enabled and does not run.
- The AI reads online text, plain-text files, and word processor documents
  (.docx, .odt). A submission that includes any other kind of file (PDF,
  images, archives) is not graded by the AI.
- Learner content is sent wrapped as untrusted data, with instructions to
  ignore anything it says (prompt-injection defence). The plugin never adds
  names or emails to what is sent.
- Learners see a notice in the activity when AI review is enabled.
- With the AI fallback enabled, a submission nobody reviewed is graded by the
  AI. If the AI cannot grade it (the learner has not accepted the AI policy,
  the work cannot be read, or the provider keeps failing), the tutors are
  notified instead.
- The model must be able to answer with a small JSON object. Current chat
  models do; the test page confirms it for the model the site uses.

## Capabilities

| Capability | Student | Teacher | Editing teacher | Manager |
| --- | --- | --- | --- | --- |
| `mod/peerreviewstudio:view` | yes | yes | yes | yes |
| `mod/peerreviewstudio:viewownstatus` | yes | no | no | no |
| `mod/peerreviewstudio:submit` | yes | no | no | no |
| `mod/peerreviewstudio:review` | yes | no | no | no |
| `mod/peerreviewstudio:viewreport` | no | yes | yes | yes |
| `mod/peerreviewstudio:manageassignments` | no | yes | yes | yes |
| `mod/peerreviewstudio:grade` | no | yes | yes | yes |
| `mod/peerreviewstudio:manageworkflow` | no | yes | yes | yes |
| `mod/peerreviewstudio:addinstance` | no | no | yes | yes |

For a read-only auditor, grant `view` and `viewreport` only. In separate
groups mode, tutors without "access all groups" only see and act on learners
in their own groups.

## Privacy

The plugin implements the Moodle Privacy API: it declares what it stores and
what it passes to the Moodle AI subsystem, exports a user's submission, the feedback
received (without reviewer identities), and the reviews written, and deletes
or anonymises data on request. Deleting an author erases the work and its
files; deleting a reviewer strips identity and comments from the reviews but
keeps the proposed grade, so other learners' grades do not change.

## Security notes

- Capability checks on every page and action; `sesskey` on every
  state-changing request.
- Submission files are served through `pluginfile.php`, always as downloads,
  and only to the author, the reviewers matched to the submission, and
  tutors who may see the author.
- All database access uses Moodle's parameterised DB API.
- The plugin stores no API key and calls no external service: AI requests
  are made by the Moodle AI subsystem.

## Testing

PHPUnit tests live in `tests/` (workflow, matching, grading, follow-up,
privacy, backup and restore, completion, calendar, AI review and its tasks):

```
vendor/bin/phpunit --testsuite mod_peerreviewstudio_testsuite
```

Behat scenarios for the learner flow, the tutor pages, and the AI policy are in
`tests/behat/`:

```
vendor/bin/behat --config {behat config} --tags=@mod_peerreviewstudio
```

`.github/workflows/ci.yml` runs moodle-plugin-ci on Moodle 4.5 to 5.3. A
manual validation checklist is in `docs/final-testing-checklist.md`.

## Translations

The plugin package contains the English strings. Translations are published
through AMOS (lang.moodle.org) and arrive with the Moodle language packs.
The repository also keeps a Spanish translation in `lang/es/`, which is the
source of the Spanish strings contributed to AMOS.

## License

2026 Julio Molpeceres

GNU GPL v3 or later; the full text is in the `LICENSE` file.

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See <https://www.gnu.org/licenses/>.
