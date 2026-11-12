# Peer Review Studio (mod_peerreviewstudio)

Peer Review Studio is a Moodle activity module for structured peer review.
Each learner submits work (online text, files, or both), reviews peers with a
teacher-defined rubric, and receives anonymous feedback — while tutors monitor
progress, resolve matching incidents, and adjust grades from an operational
dashboard.

## Features

- **Submissions**: online text, file upload, or both; learners can edit their
  submission while the submission phase is open.
- **Automatic reviewer matching**: assigns reviewers as submissions arrive,
  balancing reviewer load, up to a configurable reviews-received target per
  submission. A scheduled task retries queued submissions automatically.
- **Matching rules**: block reciprocal reviews, restrict the reviewer pool by
  course group (same group / different group / any).
- **Rubric-based reviews**: up to 5 weighted criteria with up to 4 scored
  levels each, per-criterion comments, and a general comment. The rubric
  produces the grade each review proposes for the submission.
- **Anonymous feedback**: authors see rubric answers and comments, never the
  reviewer's identity.
- **Tutor dashboard**: participant status, received-review progress, matching
  eligibility with blocked-candidate reasons, reviewer load, pending queue,
  and an incident list (no eligible reviewers, stuck queue, unassigned).
- **Tutor actions**: requeue, manual reviewer assignment, final grade
  override — all audit-logged as Moodle events.
- **Grading**: submission grade = average of the grades proposed by received
  reviews; review grade = proportional participation credit for completed
  reviews; final grade syncs to the gradebook and can be overridden.
- **Workflow**: scheduled mode (phases derived from open/close/review-due
  dates) or manual mode (teacher switches phases directly).
- **Privacy API**: export and delete/anonymise submissions, reviews, rubric
  answers, and submission files.
- **Backup/restore**: full activity structure, user data (honouring the
  "include user information" setting), and submission files.

## Requirements

- Moodle 4.5 or later (`2024100700`).
- PHP 8.1 or later.

## Installation

1. Copy the plugin folder to `{moodle}/mod/peerreviewstudio`.
2. Visit **Site administration → Notifications** to complete the installation.
3. Add a *Peer Review Studio* activity to a course.

Or install the packaged zip via **Site administration → Plugins → Install
plugins**.

## Quick start

1. Create the activity, choose the submission type, and set the reviews each
   learner must complete and the reviews each submission should receive.
2. Pick a workflow mode:
   - **Scheduled by dates** — set *Close submissions* and *Reviews due*
     (both required in this mode): submissions run until close, reviews run
     until the due date, then feedback is released automatically.
   - **Manual** — you switch the phase (submission → allocation → review →
     feedback → closed) whenever you want.
3. Adjust the default rubric or write your own criteria and levels.
4. Learners submit, review their assigned peers, and read the feedback they
   received; you supervise everything from the tutor dashboard.

Note: once peer reviews exist, the rubric structure is locked — labels,
descriptions, weights, and scores can still be edited, but criteria and
levels cannot be removed (this protects stored feedback).

## Capabilities

| Capability | Student | Teacher | Editing teacher | Manager |
| --- | --- | --- | --- | --- |
| `mod/peerreviewstudio:view` | ✅ | ✅ | ✅ | ✅ |
| `mod/peerreviewstudio:viewownstatus` | ✅ | ❌ | ❌ | ❌ |
| `mod/peerreviewstudio:submit` | ✅ | ❌ | ❌ | ❌ |
| `mod/peerreviewstudio:review` | ✅ | ❌ | ❌ | ❌ |
| `mod/peerreviewstudio:viewreport` | ❌ | ✅ | ✅ | ✅ |
| `mod/peerreviewstudio:manageassignments` | ❌ | ✅ | ✅ | ✅ |
| `mod/peerreviewstudio:grade` | ❌ | ✅ | ✅ | ✅ |
| `mod/peerreviewstudio:manageworkflow` | ❌ | ✅ | ✅ | ✅ |
| `mod/peerreviewstudio:addinstance` | ❌ | ❌ | ✅ | ✅ |

For read-only auditors, grant `view` + `viewreport` only.

## Grading model

- **Submission component** (`Maximum submission grade`): every review
  proposes a grade on this scale, calculated from the weighted rubric
  selections. The submission grade is the average of the received proposals.
- **Review component** (`Maximum review grade`): participation credit for
  completing the required number of reviews (proportional; set 0 to disable).
- **Final grade** = submission + review components, unless the teacher sets
  an override. The gradebook item maximum is the sum of both components.

## Security notes

- Capability checks on every view and action; `sesskey` on all state-changing
  tutor actions submitted via POST forms.
- Submission files are served through `pluginfile.php` and restricted to the
  owner, currently/previously assigned reviewers, and staff with
  `viewreport`.
- Reviewer identities are never exposed to submission authors.
- All database access uses Moodle's parameterised DB API.

## Testing

PHPUnit tests live in `tests/locallib_test.php`. A manual validation
checklist is provided in `docs/final-testing-checklist.md`.

## License

2026 Julio Molpeceres

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See <https://www.gnu.org/licenses/>.
