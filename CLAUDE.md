# CLAUDE.md

## Context

This folder contains the Moodle activity plugin `mod_peerreviewstudio`.

Goal of the current state:
- feature-complete and ready for final Moodle testing
- not a greenfield MVP anymore
- focus on code review, risk detection, edge cases, Moodle compliance, and runtime validation gaps

Recent relevant commits:
- `a45dc2e` — `feat(peerreviewstudio): finalize submission and review workflow for testing`
- `9befe43` — `docs(peerreviewstudio): add final testing checklist`

## What is already implemented

Core functional scope already present:
- learner submissions
- online text submissions
- file submissions
- mixed text + file submissions
- editing submission while submission phase is still open
- reviewer auto-assignment
- reciprocal-review avoidance option
- group-aware reviewer pool modes
- rubric-based review form
- tutor dashboard with filters and incidents
- manual assignment and requeue flows
- grade calculation and teacher override
- privacy provider
- backup/restore scaffolding
- pluginfile-based secure serving of submission files

## Important practical limitation

The current workspace/container did **not** have local `php` available during the final pass, so runtime validation here was limited.

That means your review should pay special attention to:
- Moodle API correctness
- PHP syntax/runtime risks
- form/filemanager handling
- pluginfile access control
- gradebook sync correctness
- privacy API correctness
- backup/restore completeness

## Highest-value review targets

Please review these files first:
- `view.php`
- `lib.php`
- `locallib.php`
- `mod_form.php`
- `classes/output/renderer.php`
- `classes/local/form/submission_form.php`
- `classes/local/form/review_form.php`
- `classes/privacy/provider.php`
- `db/install.xml`
- `db/upgrade.php`
- `tests/locallib_test.php`

## What to look for

Prioritize findings in this order:

1. fatal errors or obvious runtime breakage
2. Moodle API misuse
3. security/privacy/access control issues
4. file handling bugs
5. grading inconsistencies
6. workflow/state-transition bugs
7. edge cases in assignment/requeue/review completion
8. test gaps and maintainability concerns

## Expected output format

When reviewing, prefer a concrete report with:
- severity
- file
- exact issue
- why it matters
- suggested fix

If possible, separate:
- blockers before testing
- important but non-blocking issues
- nice-to-have refactors

## Final testing reference

Use this checklist as the intended validation target:
- `docs/final-testing-checklist.md`
