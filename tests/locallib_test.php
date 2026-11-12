<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_peerreviewstudio;

use mod_peerreviewstudio\local\service\dashboard_service;
use mod_peerreviewstudio\privacy\provider;

/**
 * Unit tests for mod_peerreviewstudio local library functions.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_peerreviewstudio
 */
final class locallib_test extends \advanced_testcase {
    /**
     * Redirect messages so workflow notifications do not abort the tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->redirectMessages();
    }

    /**
     * Create one activity with sensible defaults for tests.
     *
     * @param array $overrides Field overrides.
     * @return array [course, activity]
     */
    protected function create_activity(array $overrides = []): array {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $activity = $generator->create_module('peerreviewstudio', array_merge([
            'course' => $course->id,
            'name' => 'Peer Review Studio Test',
            'submissiontype' => 'onlinetext',
            'reviewcountrequired' => 1,
            'reviewsreceivedtarget' => 1,
            'autoassignenabled' => 0,
            'reviewpoolmode' => 'any',
            'avoidreciprocalreviews' => 1,
            'workflowmode' => 'manual',
            'manualphase' => 'review',
            'grademaxsubmission' => 80,
            'grademaxreview' => 20,
        ], $overrides));

        return [$course, $activity];
    }

    /**
     * Insert one submission row directly.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass $user Owner.
     * @param string $status Submission status.
     * @return \stdClass
     */
    protected function create_submission(\stdClass $activity, \stdClass $user, string $status = 'submitted'): \stdClass {
        global $DB;

        $now = time();
        $submission = (object) [
            'peerreviewstudioid' => $activity->id,
            'userid' => $user->id,
            'status' => $status,
            'onlinetext' => 'Submission by ' . $user->id,
            'onlinetextformat' => FORMAT_HTML,
            'timesubmitted' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $submission->id = $DB->insert_record('peerreviewstudio_submissions', $submission);
        return $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
    }

    /**
     * Insert one assignment row directly.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass $submission Submission record.
     * @param \stdClass $reviewer Reviewer.
     * @param string $status Assignment status.
     * @return \stdClass
     */
    protected function create_assignment(
        \stdClass $activity,
        \stdClass $submission,
        \stdClass $reviewer,
        string $status = 'assigned'
    ): \stdClass {
        global $DB;

        $now = time();
        $assignment = (object) [
            'peerreviewstudioid' => $activity->id,
            'submissionid' => $submission->id,
            'reviewerid' => $reviewer->id,
            'status' => $status,
            'timeassigned' => $now,
            'timeopened' => 0,
            'timecompleted' => $status === 'completed' ? $now : 0,
            'timemodified' => $now,
        ];
        $assignment->id = $DB->insert_record('peerreviewstudio_assignments', $assignment);
        return $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
    }

    public function test_extract_rubric_definition_from_form_returns_criteria(): void {
        $data = [
            'rubriccriterionlabel_1' => 'Clarity',
            'rubriccriteriondescription_1' => 'Readable structure',
            'rubriccriterionweight_1' => 1,
            'rubriclevellabel_1_1' => 'Low',
            'rubriclevelscore_1_1' => 1,
            'rubriclevellabel_1_2' => 'High',
            'rubriclevelscore_1_2' => 4,
        ];

        $definition = \peerreviewstudio_extract_rubric_definition_from_form($data);
        $this->assertCount(1, $definition);
        $this->assertSame('Clarity', $definition[0]['label']);
        $this->assertSame(1, $definition[0]['slot']);
        $this->assertCount(2, $definition[0]['levels']);
    }

    public function test_extract_rubric_definition_keeps_original_slots(): void {
        $data = [
            'rubriccriterionlabel_2' => 'Only criterion in slot two',
            'rubriccriterionweight_2' => 1,
            'rubriclevellabel_2_1' => 'Low',
            'rubriclevelscore_2_1' => 1,
            'rubriclevellabel_2_2' => 'High',
            'rubriclevelscore_2_2' => 4,
        ];

        $definition = \peerreviewstudio_extract_rubric_definition_from_form($data);
        $this->assertCount(1, $definition);
        $this->assertSame(2, $definition[0]['slot']);
    }

    public function test_calculate_rubric_result_scales_grade_and_keeps_comments(): void {
        $rubric = [
            [
                'id' => 10,
                'weight' => 1,
                'levels' => [
                    ['id' => 100, 'label' => 'Low', 'score' => 1],
                    ['id' => 101, 'label' => 'High', 'score' => 4],
                ],
            ],
            [
                'id' => 20,
                'weight' => 1,
                'levels' => [
                    ['id' => 200, 'label' => 'Low', 'score' => 2],
                    ['id' => 201, 'label' => 'High', 'score' => 5],
                ],
            ],
        ];
        $formdata = [
            'criterion_10' => 101,
            'criterioncomment_10' => 'Muy claro',
            'criterion_20' => 200,
            'criterioncomment_20' => 'Mejorable',
        ];

        $result = \peerreviewstudio_calculate_rubric_result($rubric, $formdata, 10);
        $this->assertEqualsWithDelta(6.67, $result['grade'], 0.001);
        $this->assertSame('Muy claro', $result['answers'][0]['commenttext']);
        $this->assertSame('Mejorable', $result['answers'][1]['commenttext']);
    }

    public function test_calculate_rubric_result_returns_zero_without_grademax(): void {
        $rubric = [
            [
                'id' => 10,
                'weight' => 1,
                'levels' => [
                    ['id' => 100, 'label' => 'Low', 'score' => 1],
                    ['id' => 101, 'label' => 'High', 'score' => 4],
                ],
            ],
        ];
        $result = \peerreviewstudio_calculate_rubric_result($rubric, ['criterion_10' => 101], 0);
        $this->assertEqualsWithDelta(0.0, $result['grade'], 0.001);
        $this->assertCount(1, $result['answers']);
    }

    public function test_ineligible_candidates_summary_groups_labels(): void {
        $candidates = [
            (object)['reasonlabels' => ['Same group blocked', 'Reciprocal blocked']],
            (object)['reasonlabels' => ['Same group blocked']],
            (object)['reasonlabels' => ['Already assigned']],
        ];

        $summary = \peerreviewstudio_ineligible_candidates_summary($candidates);
        $this->assertStringContainsString('Same group blocked (2)', $summary);
        $this->assertStringContainsString('Reciprocal blocked (1)', $summary);
        $this->assertStringContainsString('Already assigned (1)', $summary);
    }

    public function test_dashboard_filter_rows_filters_by_status_and_search(): void {
        $rows = [
            (object)['status' => 'queued', 'firstname' => 'Ana', 'lastname' => 'Lopez', 'email' => 'ana@example.com'],
            (object)['status' => 'reviewed', 'firstname' => 'Luis', 'lastname' => 'Martin', 'email' => 'luis@example.com'],
        ];

        $filtered = dashboard_service::filter_rows($rows, 'queued', 'ana');
        $this->assertCount(1, $filtered);
        $this->assertSame('Ana', $filtered[0]->firstname);
    }

    public function test_calculate_review_completion_grade_caps_at_max(): void {
        $this->assertEqualsWithDelta(5.0, \peerreviewstudio_calculate_review_completion_grade(1, 2, 10), 0.001);
        $this->assertEqualsWithDelta(10.0, \peerreviewstudio_calculate_review_completion_grade(5, 2, 10), 0.001);
        $this->assertEqualsWithDelta(0.0, \peerreviewstudio_calculate_review_completion_grade(1, 2, 0), 0.001);
    }

    public function test_get_current_phase_supports_manual_mode(): void {
        $activity = (object) [
            'workflowmode' => 'manual',
            'manualphase' => 'feedback',
            'timeopen' => 0,
            'timeclose' => 0,
            'timereviewdue' => 0,
        ];

        $this->assertSame('feedback', \peerreviewstudio_get_current_phase($activity));
    }

    public function test_get_current_phase_scheduled_transitions(): void {
        $now = time();
        $base = (object) [
            'workflowmode' => 'scheduled',
            'manualphase' => 'submission',
            'timeopen' => 0,
            'timeclose' => 0,
            'timereviewdue' => 0,
        ];

        // Without dates the activity stays in the submission phase.
        $this->assertSame('submission', \peerreviewstudio_get_current_phase($base));

        // Before close: submission.
        $activity = clone $base;
        $activity->timeclose = $now + DAYSECS;
        $activity->timereviewdue = $now + 2 * DAYSECS;
        $this->assertSame('submission', \peerreviewstudio_get_current_phase($activity));

        // After close, before review due: review.
        $activity = clone $base;
        $activity->timeclose = $now - DAYSECS;
        $activity->timereviewdue = $now + DAYSECS;
        $this->assertSame('review', \peerreviewstudio_get_current_phase($activity));

        // After review due: feedback.
        $activity = clone $base;
        $activity->timeclose = $now - 2 * DAYSECS;
        $activity->timereviewdue = $now - DAYSECS;
        $this->assertSame('feedback', \peerreviewstudio_get_current_phase($activity));
    }

    public function test_visible_workflow_phases_hide_unreachable_scheduled_steps(): void {
        [, $activity] = $this->create_activity(['workflowmode' => 'scheduled']);
        $phases = \peerreviewstudio_visible_workflow_phases($activity);
        $this->assertSame(['submission', 'review', 'feedback'], array_keys($phases));

        [, $manualactivity] = $this->create_activity(['workflowmode' => 'manual']);
        $manualphases = \peerreviewstudio_visible_workflow_phases($manualactivity);
        $this->assertSame(['submission', 'allocation', 'review', 'feedback', 'closed'], array_keys($manualphases));

        [, $openactivity] = $this->create_activity(['workflowmode' => 'open']);
        $openphases = \peerreviewstudio_visible_workflow_phases($openactivity);
        $this->assertSame(['ongoing'], array_keys($openphases));
    }

    public function test_open_workflow_mode_never_gates_by_calendar_or_manual_phase(): void {
        // No dates set at all, unlike scheduled mode which would require them.
        $activity = (object) [
            'workflowmode' => 'open',
            'manualphase' => 'submission',
            'timeopen' => 0,
            'timeclose' => 0,
            'timereviewdue' => 0,
        ];

        $this->assertSame('ongoing', \peerreviewstudio_get_current_phase($activity));
        $this->assertTrue(\peerreviewstudio_phase_allows_submissions($activity));
        $this->assertTrue(\peerreviewstudio_phase_allows_reviews($activity));
        $this->assertTrue(\peerreviewstudio_submissions_open($activity));

        // Feedback is gated per-submission on its own status, not on a shared phase.
        $notyet = (object) ['status' => 'partiallyreviewed'];
        $this->assertFalse(\peerreviewstudio_phase_allows_feedback($activity, $notyet));
        $done = (object) ['status' => 'closed'];
        $this->assertTrue(\peerreviewstudio_phase_allows_feedback($activity, $done));
        $this->assertFalse(\peerreviewstudio_phase_allows_feedback($activity, null));
    }

    public function test_open_workflow_mode_ignores_stale_dates_from_a_previous_mode(): void {
        // Switching an activity from scheduled to open should not leave it
        // gated by dates a teacher configured before the switch.
        $now = time();
        $activity = (object) [
            'workflowmode' => 'open',
            'manualphase' => 'submission',
            'timeopen' => $now - 10 * DAYSECS,
            'timeclose' => $now - DAYSECS,
            'timereviewdue' => $now - DAYSECS,
        ];

        $this->assertTrue(\peerreviewstudio_phase_allows_submissions($activity));
        $this->assertTrue(\peerreviewstudio_submissions_open($activity));
    }

    public function test_normalize_teacher_grade_override_clamps_and_allows_empty(): void {
        $activity = (object) [
            'grademaxsubmission' => 80,
            'grademaxreview' => 20,
        ];

        $this->assertNull(\peerreviewstudio_normalize_teacher_grade_override($activity, ''));
        $this->assertEqualsWithDelta(100.0, \peerreviewstudio_normalize_teacher_grade_override($activity, '120'), 0.001);
        $this->assertEqualsWithDelta(0.0, \peerreviewstudio_normalize_teacher_grade_override($activity, '-4'), 0.001);
    }

    public function test_get_eligible_reviewers_respects_reciprocal_rule(): void {
        [$course, $activity] = $this->create_activity([
            'reviewpoolmode' => 'any',
            'avoidreciprocalreviews' => 1,
        ]);

        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'submitted');
        $reviewersubmission = $this->create_submission($activity, $reviewer, 'submitted');
        $this->create_assignment($activity, $reviewersubmission, $author, 'assigned');

        $eligible = \peerreviewstudio_get_eligible_reviewers_for_submission($activity, $authorsubmission);
        $this->assertCount(0, $eligible);

        $ineligible = \peerreviewstudio_get_ineligible_reviewers_for_submission($activity, $authorsubmission);
        $this->assertCount(1, $ineligible);
        $this->assertContains('Reciprocal reviews are blocked for this activity.', $ineligible[0]->reasonlabels);
    }

    public function test_get_eligible_reviewers_returns_a_genuinely_eligible_candidate(): void {
        // Regression test: peerreviewstudio_get_eligible_reviewers_for_submission()
        // used to select rows without the 'status' column, so
        // peerreviewstudio_evaluate_reviewer_candidate() read an undefined
        // property, coerced it to '', and rejected every candidate as
        // 'candidateinactive' — silently breaking auto-assignment for any
        // activity with more than one submission.
        [, $activity] = $this->create_activity([
            'reviewpoolmode' => 'any',
            'avoidreciprocalreviews' => 0,
        ]);

        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $candidate = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'submitted');
        $this->create_submission($activity, $candidate, 'submitted');

        $eligible = \peerreviewstudio_get_eligible_reviewers_for_submission($activity, $authorsubmission);
        $this->assertCount(1, $eligible);
        $this->assertSame((int)$candidate->id, (int)reset($eligible)->userid);

        $ineligible = \peerreviewstudio_get_ineligible_reviewers_for_submission($activity, $authorsubmission);
        $this->assertCount(0, $ineligible);
    }

    public function test_assign_submission_to_reviewer_creates_assignment_and_resolves_queue(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'queued');
        $reviewersubmission = $this->create_submission($activity, $reviewer, 'submitted');
        \peerreviewstudio_queue_submission($activity, (int)$authorsubmission->id, 'awaiting_reviewer');

        $assigned = \peerreviewstudio_assign_submission_to_reviewer($activity, (int)$authorsubmission->id, (int)$reviewer->id);

        $this->assertTrue($assigned);
        $assignment = $DB->get_record('peerreviewstudio_assignments', [
            'submissionid' => $authorsubmission->id,
            'reviewerid' => $reviewer->id,
        ], '*', MUST_EXIST);
        $this->assertSame('assigned', $assignment->status);

        $updatedsubmission = $DB->get_record('peerreviewstudio_submissions', ['id' => $authorsubmission->id], '*', MUST_EXIST);
        $this->assertSame('assigned', $updatedsubmission->status);

        $updatedreviewersubmission = $DB->get_record(
            'peerreviewstudio_submissions', ['id' => $reviewersubmission->id], '*', MUST_EXIST
        );
        $this->assertSame('assigned', $updatedreviewersubmission->status);

        $this->assertEquals(0, $DB->count_records('peerreviewstudio_queue', [
            'submissionid' => $authorsubmission->id,
            'status' => 'pending',
        ]));
    }

    public function test_requeue_submission_cancels_open_assignment_and_creates_pending_queue(): void {
        global $DB;

        [$course, $activity] = $this->create_activity([
            'autoassignenabled' => 0,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();
        $fallback = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'assigned');
        $reviewersubmission = $this->create_submission($activity, $reviewer, 'assigned');
        $fallbacksubmission = $this->create_submission($activity, $fallback, 'submitted');
        $assignment = $this->create_assignment($activity, $authorsubmission, $reviewer, 'assigned');

        \peerreviewstudio_requeue_submission($activity, (int)$authorsubmission->id);

        $cancelled = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame('cancelled', $cancelled->status);

        $updatedreviewersubmission = $DB->get_record(
            'peerreviewstudio_submissions', ['id' => $reviewersubmission->id], '*', MUST_EXIST
        );
        $this->assertSame('submitted', $updatedreviewersubmission->status);

        $updatedsubmission = $DB->get_record('peerreviewstudio_submissions', ['id' => $authorsubmission->id], '*', MUST_EXIST);
        $this->assertContains($updatedsubmission->status, ['queued', 'assigned']);

        $this->assertGreaterThanOrEqual(1, $DB->count_records('peerreviewstudio_queue', [
            'submissionid' => $authorsubmission->id,
        ]));

        $newassignment = $DB->get_record_select(
            'peerreviewstudio_assignments',
            'submissionid = :submissionid AND reviewerid = :reviewerid',
            [
                'submissionid' => $authorsubmission->id,
                'reviewerid' => $fallback->id,
            ]
        );
        $this->assertNotFalse($newassignment);
        $this->assertSame('assigned', $newassignment->status);
    }

    public function test_process_assignment_queue_assigns_up_to_received_target(): void {
        global $DB;

        [$course, $activity] = $this->create_activity([
            'autoassignenabled' => 1,
            'reviewsreceivedtarget' => 2,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $peerone = $generator->create_user();
        $peertwo = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'queued');
        $this->create_submission($activity, $peerone, 'submitted');
        $this->create_submission($activity, $peertwo, 'submitted');

        \peerreviewstudio_queue_submission($activity, (int)$authorsubmission->id, 'awaiting_reviewer');
        \peerreviewstudio_process_assignment_queue($activity);

        $this->assertEquals(2, $DB->count_records('peerreviewstudio_assignments', [
            'submissionid' => $authorsubmission->id,
            'status' => 'assigned',
        ]));
        $reviewerids = $DB->get_fieldset_select(
            'peerreviewstudio_assignments', 'reviewerid', 'submissionid = :sid', ['sid' => $authorsubmission->id]
        );
        $this->assertEqualsCanonicalizing([(int)$peerone->id, (int)$peertwo->id], array_map('intval', $reviewerids));

        $this->assertEquals(0, $DB->count_records('peerreviewstudio_queue', [
            'submissionid' => $authorsubmission->id,
            'status' => 'pending',
        ]));

        $updated = $DB->get_record('peerreviewstudio_submissions', ['id' => $authorsubmission->id], '*', MUST_EXIST);
        $this->assertSame('assigned', $updated->status);
    }

    public function test_process_assignment_queue_keeps_entry_pending_without_reviewers(): void {
        global $DB;

        [$course, $activity] = $this->create_activity([
            'autoassignenabled' => 1,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'queued');
        \peerreviewstudio_queue_submission($activity, (int)$authorsubmission->id, 'awaiting_reviewer');
        \peerreviewstudio_process_assignment_queue($activity);

        $queueentry = $DB->get_record('peerreviewstudio_queue', ['submissionid' => $authorsubmission->id], '*', MUST_EXIST);
        $this->assertSame('pending', $queueentry->status);
        $this->assertEquals(1, (int)$queueentry->attempts);
        $this->assertEquals(0, $DB->count_records('peerreviewstudio_assignments', ['submissionid' => $authorsubmission->id]));
    }

    public function test_save_submission_edit_does_not_stack_extra_reviewers(): void {
        global $DB;

        [$course, $activity] = $this->create_activity([
            'autoassignenabled' => 1,
            'reviewsreceivedtarget' => 1,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $peer = $generator->create_user();

        $this->create_submission($activity, $peer, 'submitted');

        $submission = \peerreviewstudio_save_submission($activity, (int)$author->id, ['text' => 'v1', 'format' => FORMAT_HTML]);
        $this->assertEquals(1, $DB->count_records('peerreviewstudio_assignments', ['submissionid' => $submission->id]));
        $this->assertSame('assigned', $DB->get_field('peerreviewstudio_submissions', 'status', ['id' => $submission->id]));

        // Editing the submission must not queue or assign an extra reviewer.
        $submission = \peerreviewstudio_save_submission(
            $activity, (int)$author->id, ['text' => 'v2 edited', 'format' => FORMAT_HTML]
        );
        $this->assertEquals(1, $DB->count_records('peerreviewstudio_assignments', ['submissionid' => $submission->id]));
        $this->assertSame('assigned', (string)$submission->status);
        $this->assertSame('v2 edited', (string)$submission->onlinetext);
    }

    public function test_save_submission_actually_stores_grade_in_gradebook(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        [$course, $activity] = $this->create_activity([
            'autoassignenabled' => 0,
            'grademaxsubmission' => 80,
            'grademaxreview' => 20,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $generator->enrol_user($author->id, $course->id, 'student');

        \peerreviewstudio_save_submission($activity, (int)$author->id, ['text' => 'v1', 'format' => FORMAT_HTML]);

        // The grades array passed to grade_update() must be indexed by user
        // id; if it is indexed by position instead, it silently ignores the
        // grade and logs "Incorrect grade array index, must be user id!".
        $gradinginfo = grade_get_grades($course->id, 'mod', 'peerreviewstudio', $activity->id, $author->id);
        $this->assertNotEmpty($gradinginfo->items);
        $this->assertArrayHasKey((int)$author->id, $gradinginfo->items[0]->grades);
        $this->assertNotNull($gradinginfo->items[0]->grades[$author->id]->grade);
    }

    public function test_sync_activity_rubric_preserves_ids_and_answers(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $rubric = \peerreviewstudio_get_activity_rubric((int)$activity->id);
        $this->assertNotEmpty($rubric);
        $originalcriterionid = (int)$rubric[0]['id'];
        $originallevelid = (int)$rubric[0]['levels'][1]['id'];

        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();
        $authorsubmission = $this->create_submission($activity, $author, 'assigned');
        $this->create_submission($activity, $reviewer, 'assigned');
        $assignment = $this->create_assignment($activity, $authorsubmission, $reviewer, 'assigned');
        $review = \peerreviewstudio_save_review($activity, $assignment, (int)$reviewer->id, 'Comentario', 40.0, [[
            'criterionid' => $originalcriterionid,
            'levelid' => $originallevelid,
            'score' => (float)$rubric[0]['levels'][1]['score'],
            'commenttext' => 'Bien',
        ]]);

        // Teacher renames criteria and levels without changing the structure.
        $newdefinition = [];
        foreach ($rubric as $criterion) {
            $newdefinition[] = [
                'label' => $criterion['label'] . ' (edited)',
                'description' => $criterion['description'],
                'weight' => $criterion['weight'],
                'levels' => array_map(static function(array $level): array {
                    return ['label' => $level['label'] . ' (edited)', 'score' => $level['score']];
                }, $criterion['levels']),
            ];
        }
        \peerreviewstudio_sync_activity_rubric((int)$activity->id, $newdefinition);

        $updatedrubric = \peerreviewstudio_get_activity_rubric((int)$activity->id);
        $this->assertSame($originalcriterionid, (int)$updatedrubric[0]['id']);
        $this->assertSame($originallevelid, (int)$updatedrubric[0]['levels'][1]['id']);
        $this->assertStringEndsWith('(edited)', $updatedrubric[0]['label']);

        // The stored answer still resolves through the join used for display.
        $answers = \peerreviewstudio_get_rubric_answers_by_review([(int)$review->id]);
        $this->assertArrayHasKey((int)$review->id, $answers);
        $this->assertCount(1, $answers[(int)$review->id]);
        $this->assertTrue(\peerreviewstudio_activity_has_rubric_answers((int)$activity->id));
    }

    public function test_save_review_updates_states_answers_and_grades(): void {
        global $DB;

        [$course, $activity] = $this->create_activity([
            'grademaxsubmission' => 80,
            'grademaxreview' => 20,
            'reviewsreceivedtarget' => 1,
        ]);
        \peerreviewstudio_sync_activity_rubric((int)$activity->id, [[
            'label' => 'Quality',
            'description' => 'Quality criterion',
            'weight' => 1,
            'levels' => [
                ['label' => 'Basic', 'score' => 1],
                ['label' => 'Excellent', 'score' => 4],
            ],
        ]]);
        $rubric = \peerreviewstudio_get_activity_rubric((int)$activity->id);

        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'assigned');
        $reviewersubmission = $this->create_submission($activity, $reviewer, 'assigned');
        $assignment = $this->create_assignment($activity, $authorsubmission, $reviewer, 'assigned');

        $answer = [[
            'criterionid' => (int)$rubric[0]['id'],
            'levelid' => (int)$rubric[0]['levels'][1]['id'],
            'score' => (float)$rubric[0]['levels'][1]['score'],
            'commenttext' => 'Muy bien resuelto',
        ]];

        // The review proposes 60 out of the 80 submission points.
        $review = \peerreviewstudio_save_review($activity, $assignment, (int)$reviewer->id, 'Buen trabajo', 60.0, $answer);

        $this->assertSame('submitted', $review->status);
        $this->assertEqualsWithDelta(60.0, (float)$review->grade, 0.001);
        $this->assertEquals(1, $DB->count_records('peerreviewstudio_r_answers', ['reviewid' => $review->id]));

        $updatedassignment = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame('completed', $updatedassignment->status);

        $updatedsubmission = $DB->get_record('peerreviewstudio_submissions', ['id' => $authorsubmission->id], '*', MUST_EXIST);
        $this->assertSame('closed', $updatedsubmission->status);
        $this->assertEqualsWithDelta(60.0, (float)$updatedsubmission->submissiongrade, 0.001);
        $this->assertEqualsWithDelta(60.0, (float)$updatedsubmission->finalgrade, 0.001);

        $updatedreviewersubmission = $DB->get_record(
            'peerreviewstudio_submissions', ['id' => $reviewersubmission->id], '*', MUST_EXIST
        );
        $this->assertSame('reviewed', $updatedreviewersubmission->status);
        $this->assertEqualsWithDelta(20.0, (float)$updatedreviewersubmission->reviewgrade, 0.001);
        $this->assertEqualsWithDelta(20.0, (float)$updatedreviewersubmission->finalgrade, 0.001);
    }

    public function test_save_review_caps_grade_at_submission_maximum(): void {
        [$course, $activity] = $this->create_activity([
            'grademaxsubmission' => 50,
            'grademaxreview' => 0,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'assigned');
        $this->create_submission($activity, $reviewer, 'assigned');
        $assignment = $this->create_assignment($activity, $authorsubmission, $reviewer, 'assigned');

        $review = \peerreviewstudio_save_review($activity, $assignment, (int)$reviewer->id, 'Fuera de rango', 500.0);
        $this->assertEqualsWithDelta(50.0, (float)$review->grade, 0.001);

        $submissiongrade = \peerreviewstudio_calculate_submission_grade($activity, (int)$authorsubmission->id);
        $this->assertEqualsWithDelta(50.0, $submissiongrade, 0.001);
    }

    public function test_calculate_submission_grade_averages_received_reviews(): void {
        [$course, $activity] = $this->create_activity([
            'grademaxsubmission' => 100,
            'reviewsreceivedtarget' => 2,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewerone = $generator->create_user();
        $reviewertwo = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'assigned');
        $this->create_submission($activity, $reviewerone, 'assigned');
        $this->create_submission($activity, $reviewertwo, 'assigned');
        $assignmentone = $this->create_assignment($activity, $authorsubmission, $reviewerone, 'assigned');
        $assignmenttwo = $this->create_assignment($activity, $authorsubmission, $reviewertwo, 'assigned');

        \peerreviewstudio_save_review($activity, $assignmentone, (int)$reviewerone->id, 'A', 60.0);
        \peerreviewstudio_save_review($activity, $assignmenttwo, (int)$reviewertwo->id, 'B', 80.0);

        $this->assertEqualsWithDelta(
            70.0, \peerreviewstudio_calculate_submission_grade($activity, (int)$authorsubmission->id), 0.001
        );
    }

    public function test_set_teacher_grade_override_replaces_final_grade_only(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();

        $submission = $this->create_submission($activity, $author, 'reviewed');
        $record = $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $record->submissiongrade = 60;
        $record->reviewgrade = 10;
        $record->finalgrade = 70;
        $DB->update_record('peerreviewstudio_submissions', $record);

        \peerreviewstudio_set_teacher_grade_override($activity, (int)$submission->id, 55.5);

        $updated = $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(55.5, (float)$updated->teachergradeoverride, 0.001);
        $this->assertEqualsWithDelta(55.5, (float)$updated->finalgrade, 0.001);
    }

    public function test_tutor_incident_rows_flag_submission_without_reviewers(): void {
        [$course, $activity] = $this->create_activity([
            'autoassignenabled' => 1,
        ]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'queued');
        \peerreviewstudio_queue_submission($activity, (int)$authorsubmission->id, 'awaiting_reviewer');
        \peerreviewstudio_process_assignment_queue($activity);

        $incidents = \peerreviewstudio_get_tutor_incident_rows((int)$activity->id);
        $this->assertCount(1, $incidents);
        $this->assertSame('noreviewers', $incidents[0]->incidenttype);
        $this->assertEquals((int)$author->id, (int)$incidents[0]->userid);
    }

    public function test_privacy_anonymise_submission_owner_data_clears_personal_fields(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();

        $submission = $this->create_submission($activity, $author, 'closed');
        $record = $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $record->submissiongrade = 80;
        $record->reviewgrade = 20;
        $record->finalgrade = 100;
        $record->teachergradeoverride = 95;
        $DB->update_record('peerreviewstudio_submissions', $record);
        \peerreviewstudio_queue_submission($activity, (int)$submission->id, 'awaiting_reviewer');

        $context = \peerreviewstudio_get_activity_context((int)$activity->id);
        $method = new \ReflectionMethod(provider::class, 'anonymise_submission_owner_data');
        $method->setAccessible(true);
        $method->invoke(null, (int)$activity->id, $context, (int)$author->id);

        $updated = $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $this->assertSame(0, (int)$updated->userid);
        $this->assertSame('', $updated->onlinetext);
        $this->assertNull($updated->submissiongrade);
        $this->assertNull($updated->reviewgrade);
        $this->assertNull($updated->finalgrade);
        $this->assertNull($updated->teachergradeoverride);

        $queueitem = $DB->get_record('peerreviewstudio_queue', ['submissionid' => $submission->id], '*', MUST_EXIST);
        $this->assertSame('failed', $queueitem->status);
    }

    public function test_privacy_anonymise_reviewer_data_clears_reviewer_identity_and_comments(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        \peerreviewstudio_sync_activity_rubric((int)$activity->id, [[
            'label' => 'Quality',
            'description' => 'Quality criterion',
            'weight' => 1,
            'levels' => [
                ['label' => 'Basic', 'score' => 1],
                ['label' => 'Excellent', 'score' => 4],
            ],
        ]]);
        $rubric = \peerreviewstudio_get_activity_rubric((int)$activity->id);

        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $authorsubmission = $this->create_submission($activity, $author, 'assigned');
        $this->create_submission($activity, $reviewer, 'assigned');
        $assignment = $this->create_assignment($activity, $authorsubmission, $reviewer, 'assigned');
        $review = \peerreviewstudio_save_review($activity, $assignment, (int)$reviewer->id, 'Comentario sensible', 20.0, [[
            'criterionid' => (int)$rubric[0]['id'],
            'levelid' => (int)$rubric[0]['levels'][1]['id'],
            'score' => (float)$rubric[0]['levels'][1]['score'],
            'commenttext' => 'Detalle sensible',
        ]]);

        $method = new \ReflectionMethod(provider::class, 'anonymise_reviewer_data');
        $method->setAccessible(true);
        $method->invoke(null, (int)$activity->id, (int)$reviewer->id);

        $updatedassignment = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
        $updatedreview = $DB->get_record('peerreviewstudio_reviews', ['id' => $review->id], '*', MUST_EXIST);
        $answer = $DB->get_record('peerreviewstudio_r_answers', ['reviewid' => $review->id], '*', MUST_EXIST);

        $this->assertSame(0, (int)$updatedassignment->reviewerid);
        $this->assertSame(0, (int)$updatedreview->reviewerid);
        $this->assertSame('', $updatedreview->generalcomment);
        $this->assertSame('', $answer->commenttext);
    }

    public function test_workflow_notifications_are_sent(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        // 1. Submitting notifies the author that the work was received.
        $sink = $this->redirectMessages();
        $submission = \peerreviewstudio_save_submission($activity, (int)$author->id, ['text' => 'Hola', 'format' => FORMAT_HTML]);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('submissionreceived', reset($messages)->eventtype);
        $this->assertEquals($author->id, reset($messages)->useridto);

        // 2. Assigning a review notifies the reviewer.
        $sink->clear();
        $this->create_submission($activity, $reviewer);
        $this->assertTrue(
            \peerreviewstudio_assign_submission_to_reviewer($activity, (int)$submission->id, (int)$reviewer->id)
        );
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('reviewassigned', reset($messages)->eventtype);
        $this->assertEquals($reviewer->id, reset($messages)->useridto);

        // 3. A completed review notifies the submission author of the grade.
        $sink->clear();
        $assignment = $DB->get_record('peerreviewstudio_assignments', [
            'submissionid' => $submission->id,
            'reviewerid' => $reviewer->id,
        ], '*', MUST_EXIST);
        \peerreviewstudio_save_review($activity, $assignment, (int)$reviewer->id, 'Buen trabajo', 50.0);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('gradereceived', reset($messages)->eventtype);
        $this->assertEquals($author->id, reset($messages)->useridto);
    }

    public function test_followups_send_a_single_reminder_to_inactive_reviewers(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $submission = $this->create_submission($activity, $author);
        $this->create_submission($activity, $reviewer);
        $assignment = $this->create_assignment($activity, $submission, $reviewer);
        $DB->set_field('peerreviewstudio_assignments', 'timeassigned', time() - 3 * DAYSECS, ['id' => $assignment->id]);

        $sink = $this->redirectMessages();
        \peerreviewstudio_process_review_followups($activity);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('reviewreminder', reset($messages)->eventtype);
        $this->assertEquals($reviewer->id, reset($messages)->useridto);

        $updated = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int)$updated->timereminded);
        $this->assertSame('assigned', $updated->status);

        // A second run must not send a duplicate reminder.
        $sink->clear();
        \peerreviewstudio_process_review_followups($activity);
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_followups_expire_stale_assignment_and_reassign(): void {
        global $DB;

        [$course, $activity] = $this->create_activity(['autoassignenabled' => 1]);
        $generator = $this->getDataGenerator();
        $author = $generator->create_user();
        $slacker = $generator->create_user();
        $substitute = $generator->create_user();

        $submission = $this->create_submission($activity, $author);
        $this->create_submission($activity, $slacker);
        $this->create_submission($activity, $substitute);
        $assignment = $this->create_assignment($activity, $submission, $slacker);
        $DB->set_field('peerreviewstudio_assignments', 'timeassigned', time() - 5 * DAYSECS, ['id' => $assignment->id]);

        $sink = $this->redirectMessages();
        \peerreviewstudio_process_review_followups($activity);

        $updated = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame('cancelled', $updated->status);

        $replacement = $DB->get_record('peerreviewstudio_assignments', [
            'submissionid' => $submission->id,
            'status' => 'assigned',
        ], '*', MUST_EXIST);
        $this->assertEquals($substitute->id, (int)$replacement->reviewerid);

        $types = array_map(static fn($message): string => (string)$message->eventtype, $sink->get_messages());
        $this->assertContains('reviewwithdrawn', $types);
        $this->assertContains('reviewassigned', $types);
    }

    public function test_followups_escalate_to_tutors_when_cap_reached(): void {
        global $DB;

        [$course, $activity] = $this->create_activity();
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $author = $generator->create_user();

        $submission = $this->create_submission($activity, $author);
        for ($i = 0; $i < 3; $i++) {
            $ghost = $generator->create_user();
            $this->create_assignment($activity, $submission, $ghost, 'cancelled');
        }

        $sink = $this->redirectMessages();
        \peerreviewstudio_process_review_followups($activity);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('tutorescalation', reset($messages)->eventtype);
        $this->assertEquals($teacher->id, reset($messages)->useridto);

        $updated = $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
        $this->assertGreaterThan(0, (int)$updated->timeescalated);

        // Escalation fires only once.
        $sink->clear();
        \peerreviewstudio_process_review_followups($activity);
        $this->assertCount(0, $sink->get_messages());
    }

    public function test_followups_clear_dead_assignments_after_scheduled_review_window(): void {
        global $DB;

        $now = time();
        [$course, $activity] = $this->create_activity([
            'workflowmode' => 'scheduled',
            'timeopen' => 0,
            'timeclose' => $now - 5 * DAYSECS,
            'timereviewdue' => $now - DAYSECS,
        ]);
        $generator = $this->getDataGenerator();
        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $author = $generator->create_user();
        $reviewer = $generator->create_user();

        $submission = $this->create_submission($activity, $author);
        $this->create_submission($activity, $reviewer);
        $assignment = $this->create_assignment($activity, $submission, $reviewer);
        $DB->set_field('peerreviewstudio_assignments', 'timeassigned', $now - 4 * DAYSECS, ['id' => $assignment->id]);

        $sink = $this->redirectMessages();
        \peerreviewstudio_process_review_followups($activity);

        $updated = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
        $this->assertSame('cancelled', $updated->status);

        $types = array_map(static fn($message): string => (string)$message->eventtype, $sink->get_messages());
        $this->assertContains('reviewwithdrawn', $types);
        $this->assertContains('tutorescalation', $types);
    }

    public function test_submission_grade_uses_ai_fallback_when_no_reviews_exist(): void {
        global $DB;

        [$course, $activity] = $this->create_activity([
            'aireviewenabled' => 1,
            'aifallbackenabled' => 1,
            'grademaxsubmission' => 80,
        ]);
        $author = $this->getDataGenerator()->create_user();
        $submission = $this->create_submission($activity, $author);
        $DB->set_field('peerreviewstudio_submissions', 'aigrade', 75, ['id' => $submission->id]);

        $grade = \peerreviewstudio_calculate_submission_grade($activity, (int)$submission->id);
        $this->assertEqualsWithDelta(60.0, $grade, 0.001);

        // Without the fallback flag the AI grade must not stand in.
        $activity->aifallbackenabled = 0;
        $grade = \peerreviewstudio_calculate_submission_grade($activity, (int)$submission->id);
        $this->assertEqualsWithDelta(0.0, $grade, 0.001);
    }
}
