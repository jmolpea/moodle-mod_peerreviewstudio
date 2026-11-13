# AGENTS.md

## Context

This folder contains the Moodle activity plugin `mod_peerreviewstudio`
(Peer Review Studio): learners submit work, review peers with a rubric, and
receive anonymous feedback and a grade. It supports Moodle 4.5 to 5.3.

`README.md` describes the features, `CHANGES.md` the history, and
`docs/final-testing-checklist.md` the manual validation list.

## Layout

- `view.php`: activity page (learner area and tutor dashboard).
- `submission.php`: tutor page for one submission, with the tutor actions.
- `lib.php`: Moodle module callbacks (instances, gradebook, completion,
  calendar, course reset, file serving).
- `locallib.php`: workflow phases, rubric, matching, grading, follow-up,
  notifications, dashboard queries.
- `classes/local/ai/`: client of the Moodle AI subsystem (core_ai) and
  submission text reader.
- `classes/task/`: scheduled matching and follow-up task; AI review tasks.
- `classes/output/renderer.php`: all HTML output.
- `classes/privacy/provider.php`, `backup/moodle2/`, `db/`, `lang/`.
- `tests/`: PHPUnit tests and the data generator.

## Rules that are easy to break

- A submission's `status` is derived from the reviews it received
  (`peerreviewstudio_derive_submission_status`). Never set it by hand; call
  `peerreviewstudio_update_submission_status` after changing assignments,
  reviews, or the queue.
- Grades reach the gradebook only when feedback is released
  (`peerreviewstudio_grade_is_released`). A submission with no review has no
  grade (null), not 0.
- Reviewers are only matched while `peerreviewstudio_matching_allowed`, and
  matching runs under a lock (`peerreviewstudio_process_assignment_queue`).
- Module capabilities must not use `clonepermissionsfrom` (it overrides the
  archetypes); only `addinstance` does.
- Every schema change needs an upgrade step, and the upgraded schema must be
  identical to `db/install.xml`.
- Every user-facing text is a language string, in English and Spanish, kept
  in alphabetical order.
- AI review only goes through the Moodle AI subsystem (`client::instance()`,
  a core "generate text" action). The plugin has no API key and makes no
  HTTP request of its own.
- Content written by a learner is only sent to the AI once that learner has
  accepted the site AI policy. No names or emails are ever added to it, and
  it is always wrapped with `client::wrap_untrusted()`.
- An AI evaluation that was tried and abandoned has `aitimegraded` set and
  no grade; one that is still waiting has `aitimegraded = 0`.

## Verifying a change

Syntax checks are not enough: several serious defects in this plugin were
only found by running the tests inside a real Moodle site.

1. `phpcs --standard=moodle --extensions=php .` must report no errors and no
   warnings.
2. The PHPDoc checker (`local_moodlecheck`, or `moodle-plugin-ci phpdoc`)
   must report no errors.
3. Run the PHPUnit tests in `tests/` inside a Moodle site, on the oldest and
   newest supported versions at least.
4. After a schema change, upgrade a site that has the previous version and
   run `php admin/cli/check_database_schema.php`.
5. `.github/workflows/ci.yml` runs all of this with moodle-plugin-ci on
   Moodle 4.5 to 5.3.

When reviewing, report findings with severity, file, the exact issue, why it
matters, and a suggested fix, separating blockers from the rest.
