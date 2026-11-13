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

/**
 * Unit tests for the workflow, matching, grading, and follow-up logic.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      ::peerreviewstudio_get_current_phase
 * @covers      ::peerreviewstudio_process_assignment_queue
 * @covers      ::peerreviewstudio_get_reviewer_candidates
 * @covers      ::peerreviewstudio_save_submission
 * @covers      ::peerreviewstudio_save_review
 * @covers      ::peerreviewstudio_refresh_submission_grade
 * @covers      ::peerreviewstudio_process_review_followups
 * @covers      \mod_peerreviewstudio\local\service\dashboard_service
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_get_current_phase')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_process_assignment_queue')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_get_reviewer_candidates')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_save_submission')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_save_review')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_refresh_submission_grade')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_process_review_followups')]
#[\PHPUnit\Framework\Attributes\CoversClass(dashboard_service::class)]
final class locallib_test extends \advanced_testcase {
    /** @var \stdClass Course used by the test. */
    protected \stdClass $course;

    /** @var \mod_peerreviewstudio_generator Plugin generator. */
    protected \mod_peerreviewstudio_generator $plugingenerator;

    /** @var \phpunit_message_sink Sink collecting the notifications sent. */
    protected \phpunit_message_sink $sink;

    /**
     * Common set-up: a course, the plugin generator, and a message sink.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_peerreviewstudio');
        $this->sink = $this->redirectMessages();
    }

    /**
     * Create an activity in the test course and return its stored record.
     *
     * @param array $settings Activity settings.
     * @return \stdClass
     */
    protected function create_activity(array $settings = []): \stdClass {
        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id] + $settings);
        return $this->plugingenerator->get_activity($instance);
    }

    /**
     * Enrol a number of new students in the test course.
     *
     * @param int $count How many.
     * @return \stdClass[]
     */
    protected function create_students(int $count): array {
        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $students[] = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        }
        return $students;
    }

    /**
     * Names of the notifications sent to one user since the sink was last cleared.
     *
     * @param int $userid Recipient.
     * @return string[]
     */
    protected function notifications_for(int $userid): array {
        $names = [];
        foreach ($this->sink->get_messages() as $message) {
            if ((int)$message->useridto === $userid && $message->component === 'mod_peerreviewstudio') {
                $names[] = $message->eventtype;
            }
        }
        return $names;
    }

    /**
     * Reload one submission.
     *
     * @param \stdClass $submission Submission record.
     * @return \stdClass
     */
    protected function reload(\stdClass $submission): \stdClass {
        global $DB;
        return $DB->get_record('peerreviewstudio_submissions', ['id' => $submission->id], '*', MUST_EXIST);
    }

    /**
     * Gradebook grade of one user, or null when not graded.
     *
     * @param \stdClass $activity Activity record.
     * @param int $userid User id.
     * @return float|null
     */
    protected function gradebook_grade(\stdClass $activity, int $userid): ?float {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $grades = grade_get_grades($activity->course, 'mod', 'peerreviewstudio', $activity->id, $userid);
        $grade = $grades->items[0]->grades[$userid]->grade ?? null;
        return $grade === null ? null : (float)$grade;
    }

    public function test_scheduled_phases_follow_the_dates(): void {
        $now = time();
        $activity = (object) [
            'workflowmode' => 'scheduled',
            'timeopen' => $now + HOURSECS,
            'timeclose' => $now + 2 * HOURSECS,
            'timereviewdue' => $now + 3 * HOURSECS,
        ];

        // Before the opening date: submission phase, but nothing can be submitted yet.
        $this->assertSame('submission', peerreviewstudio_get_current_phase($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_submissions($activity));
        $this->assertFalse(peerreviewstudio_matching_allowed($activity));

        $activity->timeopen = $now - HOURSECS;
        $this->assertTrue(peerreviewstudio_phase_allows_submissions($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_reviews($activity));

        $activity->timeclose = $now - 60;
        $this->assertSame('review', peerreviewstudio_get_current_phase($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_submissions($activity));
        $this->assertTrue(peerreviewstudio_matching_allowed($activity));
        $this->assertTrue(peerreviewstudio_phase_allows_reviews($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_feedback($activity));
        $this->assertFalse(peerreviewstudio_review_window_over($activity));

        $activity->timereviewdue = $now - 30;
        $this->assertSame('feedback', peerreviewstudio_get_current_phase($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_reviews($activity));
        $this->assertTrue(peerreviewstudio_phase_allows_feedback($activity));
        $this->assertTrue(peerreviewstudio_review_window_over($activity));

        $this->assertSame(
            ['submission', 'review', 'feedback'],
            array_keys(peerreviewstudio_visible_workflow_phases($activity))
        );
    }

    public function test_manual_and_open_modes_ignore_dates(): void {
        // Stale dates from an earlier scheduled configuration must not matter.
        $activity = (object) [
            'workflowmode' => 'manual',
            'manualphase' => 'submission',
            'timeopen' => time() - 3 * DAYSECS,
            'timeclose' => time() - 2 * DAYSECS,
            'timereviewdue' => time() - DAYSECS,
        ];
        $this->assertTrue(peerreviewstudio_phase_allows_submissions($activity));
        $this->assertFalse(peerreviewstudio_matching_allowed($activity));

        $activity->manualphase = 'allocation';
        $this->assertFalse(peerreviewstudio_phase_allows_submissions($activity));
        $this->assertTrue(peerreviewstudio_matching_allowed($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_reviews($activity));

        $activity->manualphase = 'review';
        $this->assertTrue(peerreviewstudio_phase_allows_reviews($activity));
        $this->assertFalse(peerreviewstudio_review_window_over($activity));

        $activity->manualphase = 'closed';
        $this->assertTrue(peerreviewstudio_phase_allows_feedback($activity));
        $this->assertCount(5, peerreviewstudio_visible_workflow_phases($activity));

        $activity->workflowmode = 'open';
        $this->assertSame('ongoing', peerreviewstudio_get_current_phase($activity));
        $this->assertTrue(peerreviewstudio_phase_allows_submissions($activity));
        $this->assertTrue(peerreviewstudio_matching_allowed($activity));
        $this->assertTrue(peerreviewstudio_phase_allows_reviews($activity));
        // Feedback is gated per submission in open mode.
        $this->assertFalse(peerreviewstudio_phase_allows_feedback($activity));
        $this->assertFalse(peerreviewstudio_phase_allows_feedback($activity, (object)['status' => 'assigned']));
        $this->assertTrue(peerreviewstudio_phase_allows_feedback($activity, (object)['status' => 'closed']));
    }

    public function test_rubric_definition_is_extracted_from_form_slots(): void {
        $definition = peerreviewstudio_extract_rubric_definition_from_form([
            'rubriccriterionlabel_2' => 'Second',
            'rubriccriterionweight_2' => 2,
            'rubriclevellabel_2_1' => 'Low',
            'rubriclevelscore_2_1' => 0,
            'rubriclevellabel_2_3' => 'High',
            'rubriclevelscore_2_3' => 5,
            // A criterion without levels is ignored.
            'rubriccriterionlabel_4' => 'No levels',
        ]);

        $this->assertCount(1, $definition);
        $this->assertSame(2, $definition[0]['slot']);
        $this->assertSame('Second', $definition[0]['label']);
        $this->assertEqualsWithDelta(2.0, $definition[0]['weight'], 0.0001);
        $this->assertSame(['Low', 'High'], array_column($definition[0]['levels'], 'label'));
    }

    public function test_rubric_result_is_the_weighted_share_of_points(): void {
        $rubric = [
            ['id' => 1, 'weight' => 1.0, 'levels' => [['id' => 11, 'score' => 0], ['id' => 12, 'score' => 4]]],
            ['id' => 2, 'weight' => 3.0, 'levels' => [['id' => 21, 'score' => 0], ['id' => 22, 'score' => 2]]],
        ];
        $result = peerreviewstudio_calculate_rubric_result($rubric, [
            'criterion_1' => 12,
            'criterion_2' => 21,
            'criterioncomment_1' => ' Clear ',
        ], 80.0);

        // Points: 4*1 + 0*3 = 4 of 4*1 + 2*3 = 10, so 40% of 80.
        $this->assertEqualsWithDelta(32.0, $result['grade'], 0.0001);
        $this->assertCount(2, $result['answers']);
        $this->assertSame('Clear', $result['answers'][0]['commenttext']);

        $ungraded = peerreviewstudio_calculate_rubric_result($rubric, ['criterion_1' => 12], 0.0);
        $this->assertEqualsWithDelta(0.0, $ungraded['grade'], 0.0001);
    }

    public function test_rubric_sync_keeps_ids_so_answers_survive_edits(): void {
        global $DB;

        $activity = $this->create_activity();
        $before = peerreviewstudio_get_activity_rubric((int)$activity->id);
        $this->assertCount(3, $before);

        [$author, $reviewer] = $this->create_students(2);
        $submission = $this->plugingenerator->create_submission($activity, $author->id);
        $this->plugingenerator->create_submission($activity, $reviewer->id);
        $assignment = $this->plugingenerator->get_open_assignment((int)$submission->id, (int)$reviewer->id);
        $this->plugingenerator->create_review($activity, $assignment, 3);
        $this->assertTrue(peerreviewstudio_activity_has_rubric_answers((int)$activity->id));

        // Rename the criteria and halve the score of the top level.
        $definition = $before;
        foreach ($definition as &$criterion) {
            $criterion['label'] .= ' (edited)';
            $criterion['levels'][3]['score'] = 8;
        }
        unset($criterion);
        peerreviewstudio_sync_activity_rubric((int)$activity->id, $definition);

        $after = peerreviewstudio_get_activity_rubric((int)$activity->id);
        $this->assertSame(array_column($before, 'id'), array_column($after, 'id'));
        $this->assertStringEndsWith('(edited)', $after[0]['label']);
        $this->assertSame(3, $DB->count_records('peerreviewstudio_r_answers'));

        // The stored review follows the new scores: still the top level, so still the maximum.
        peerreviewstudio_recalculate_review_grades($activity);
        $this->assertEqualsWithDelta(100.0, (float)$DB->get_field('peerreviewstudio_reviews', 'grade', []), 0.0001);
        $score = $DB->get_field('peerreviewstudio_r_answers', 'score', [], IGNORE_MULTIPLE);
        $this->assertEqualsWithDelta(8.0, (float)$score, 0.0001);
    }

    public function test_open_mode_matches_reviewers_as_submissions_arrive(): void {
        global $DB;

        $activity = $this->create_activity();
        [$a, $b, $c] = $this->create_students(3);

        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        // Nobody else has submitted: the first submission waits.
        $this->assertSame('queued', $suba->status);
        $this->assertSame(['submissionreceived'], $this->notifications_for($a->id));

        $subb = $this->plugingenerator->create_submission($activity, $b->id);
        $this->assertSame('assigned', $this->reload($suba)->status);
        $this->assertSame('assigned', $subb->status);
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id));
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$subb->id, (int)$a->id));
        $this->assertContains('reviewassigned', $this->notifications_for($a->id));
        $this->assertContains('reviewassigned', $this->notifications_for($b->id));

        // The third submission goes to the least loaded reviewer, earliest submitter first.
        $subc = $this->plugingenerator->create_submission($activity, $c->id);
        $this->assertSame('assigned', $subc->status);
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$subc->id, (int)$a->id));
        $this->assertSame(0, $DB->count_records('peerreviewstudio_queue', ['status' => 'pending']));
        $this->assertSame(3, $DB->count_records('peerreviewstudio_assignments'));
    }

    public function test_scheduled_mode_matches_when_submissions_close(): void {
        global $DB;

        $activity = $this->create_activity([
            'workflowmode' => 'scheduled',
            'timeclose' => time() + HOURSECS,
            'timereviewdue' => time() + 2 * HOURSECS,
        ]);
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $subb = $this->plugingenerator->create_submission($activity, $b->id);

        // During the submission phase nobody is matched or told to review.
        $this->assertSame('queued', $suba->status);
        $this->assertSame('queued', $subb->status);
        $this->assertSame(0, $DB->count_records('peerreviewstudio_assignments'));
        $this->assertSame([], peerreviewstudio_get_tutor_incident_rows($activity));
        $this->assertNotContains('reviewassigned', $this->notifications_for($a->id));

        // Submissions close: the scheduled task matches everybody.
        $DB->set_field('peerreviewstudio', 'timeclose', time() - 60, ['id' => $activity->id]);
        $activity = $this->plugingenerator->get_activity($activity);
        (new \mod_peerreviewstudio\task\process_assignment_queue_task())->execute();

        $this->assertSame('assigned', $this->reload($suba)->status);
        $this->assertSame('assigned', $this->reload($subb)->status);
        $this->assertContains('reviewassigned', $this->notifications_for($a->id));
        $this->assertContains('reviewassigned', $this->notifications_for($b->id));
    }

    public function test_only_active_participants_who_can_review_are_candidates(): void {
        $activity = $this->create_activity(['autoassignenabled' => 0]);
        [$author, $active, $suspended, $unenrolled] = $this->create_students(4);
        $submission = $this->plugingenerator->create_submission($activity, $author->id);
        foreach ([$active, $suspended, $unenrolled] as $user) {
            $this->plugingenerator->create_submission($activity, $user->id);
        }

        // One enrolment is suspended and another one removed after submitting.
        $enrol = enrol_get_plugin('manual');
        $instances = enrol_get_instances($this->course->id, true);
        $manual = reset($instances);
        $enrol->update_user_enrol($manual, $suspended->id, ENROL_USER_SUSPENDED);
        $enrol->unenrol_user($manual, $unenrolled->id);

        $candidates = peerreviewstudio_get_reviewer_candidates($activity, $submission);
        $this->assertSame([(int)$active->id], array_map(static fn($c) => (int)$c->userid, $candidates['eligible']));
        $this->assertCount(2, $candidates['ineligible']);
        foreach ($candidates['ineligible'] as $candidate) {
            $this->assertSame(['candidateinactive'], $candidate->reasons);
        }
        $this->assertStringContainsString('(2)', peerreviewstudio_ineligible_candidates_summary($candidates['ineligible']));

        // The author is never a candidate for their own work.
        $this->assertFalse(peerreviewstudio_assign_submission_to_reviewer($activity, (int)$submission->id, (int)$author->id));
        $this->assertFalse(peerreviewstudio_assign_submission_to_reviewer($activity, (int)$submission->id, (int)$suspended->id));
        $this->assertTrue(peerreviewstudio_assign_submission_to_reviewer($activity, (int)$submission->id, (int)$active->id));
        // Nobody reviews the same submission twice.
        $this->assertFalse(peerreviewstudio_assign_submission_to_reviewer($activity, (int)$submission->id, (int)$active->id));
    }

    public function test_reciprocal_rule_blocks_the_mutual_pair_only(): void {
        $activity = $this->create_activity(['avoidreciprocalreviews' => 1]);
        [$a, $b, $c] = $this->create_students(3);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $subb = $this->plugingenerator->create_submission($activity, $b->id);

        // B reviews A, so A may not review B: B waits for somebody else.
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id));
        $this->assertSame('queued', $subb->status);
        $candidates = peerreviewstudio_get_reviewer_candidates($activity, $subb);
        $this->assertSame([], $candidates['eligible']);
        $this->assertSame(['reciprocal'], $candidates['ineligible'][0]->reasons);

        $subc = $this->plugingenerator->create_submission($activity, $c->id);
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$subb->id, (int)$c->id));
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$subc->id, (int)$a->id));
    }

    public function test_group_pool_modes_restrict_the_reviewers(): void {
        global $DB;

        [$a, $b, $c] = $this->create_students(3);
        $group1 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $group2 = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        groups_add_member($group1, $a);
        groups_add_member($group1, $b);
        groups_add_member($group2, $c);

        $activity = $this->create_activity(['autoassignenabled' => 0, 'reviewpoolmode' => 'samegroup']);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $this->plugingenerator->create_submission($activity, $b->id);
        $this->plugingenerator->create_submission($activity, $c->id);

        $candidates = peerreviewstudio_get_reviewer_candidates($activity, $suba);
        $this->assertSame([(int)$b->id], array_map(static fn($x) => (int)$x->userid, $candidates['eligible']));
        $this->assertSame(['groupmismatch'], $candidates['ineligible'][0]->reasons);

        $DB->set_field('peerreviewstudio', 'reviewpoolmode', 'differentgroup', ['id' => $activity->id]);
        $candidates = peerreviewstudio_get_reviewer_candidates($this->plugingenerator->get_activity($activity), $suba);
        $this->assertSame([(int)$c->id], array_map(static fn($x) => (int)$x->userid, $candidates['eligible']));
        $this->assertSame(['samegroupblocked'], $candidates['ineligible'][0]->reasons);
    }

    public function test_status_only_reflects_the_reviews_a_submission_received(): void {
        $activity = $this->create_activity(['reviewsreceivedtarget' => 2, 'reviewcountrequired' => 2]);
        [$a, $b, $c] = $this->create_students(3);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $subb = $this->plugingenerator->create_submission($activity, $b->id);
        $subc = $this->plugingenerator->create_submission($activity, $c->id);

        // A completes both reviews they owe: A's own submission does not move.
        foreach ([$subb, $subc] as $target) {
            $assignment = $this->plugingenerator->get_open_assignment((int)$target->id, (int)$a->id);
            $this->plugingenerator->create_review($activity, $assignment, 2);
        }
        $this->assertSame(2, peerreviewstudio_count_completed_reviews((int)$activity->id, (int)$a->id));
        $this->assertSame('assigned', $this->reload($suba)->status);
        $this->assertSame('partiallyreviewed', $this->reload($subb)->status);

        // A's submission closes when it receives its two reviews.
        foreach ([$b, $c] as $reviewer) {
            $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$reviewer->id);
            $this->plugingenerator->create_review($activity, $assignment, 3);
        }
        $this->assertSame('closed', $this->reload($suba)->status);
        $this->assertSame(
            'nextstep_viewfeedback',
            peerreviewstudio_learner_next_step($activity, $this->reload($suba), 0, 2, true)
        );
    }

    public function test_submission_is_frozen_once_reviewers_are_matched(): void {
        global $DB;

        $activity = $this->create_activity();
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($activity, $a->id, 'First version');
        $this->assertTrue(peerreviewstudio_submission_is_editable($activity, $suba));

        // Editing before the match changes the content and nothing else.
        $suba = $this->plugingenerator->create_submission($activity, $a->id, 'Second version');
        $this->assertSame('Second version', $suba->onlinetext);
        $this->assertSame(1, $DB->count_records('peerreviewstudio_submissions'));
        $this->assertSame(1, $DB->count_records('peerreviewstudio_queue'));
        $this->assertSame(['submissionreceived'], $this->notifications_for($a->id));

        $this->plugingenerator->create_submission($activity, $b->id);
        $this->assertFalse(peerreviewstudio_submission_is_editable($activity, $this->reload($suba)));

        // A tutor requeue with no eligible replacement reopens it for editing.
        $DB->delete_records('peerreviewstudio_submissions', ['userid' => $b->id]);
        peerreviewstudio_requeue_submission($activity, (int)$suba->id);
        $this->assertSame('queued', $this->reload($suba)->status);
        $this->assertTrue(peerreviewstudio_submission_is_editable($activity, $this->reload($suba)));
    }

    public function test_open_mode_releases_grade_when_the_submission_is_complete(): void {
        $activity = $this->create_activity(['grademaxsubmission' => 80, 'grademaxreview' => 20]);
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $subb = $this->plugingenerator->create_submission($activity, $b->id);

        // Nothing is graded, here or in the gradebook, until a review arrives.
        $this->assertNull($suba->finalgrade);
        $this->assertNull($this->gradebook_grade($activity, (int)$a->id));

        // B reviews A with the second level of four: 50% of 80.
        $this->sink->clear();
        $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id);
        $this->plugingenerator->create_review($activity, $assignment, 1);

        $suba = $this->reload($suba);
        $this->assertSame('closed', $suba->status);
        $this->assertEqualsWithDelta(40.0, (float)$suba->submissiongrade, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float)$suba->reviewgrade, 0.0001);
        $this->assertEqualsWithDelta(40.0, $this->gradebook_grade($activity, (int)$a->id), 0.0001);
        $this->assertSame(['gradereceived'], $this->notifications_for($a->id));

        // B earned the reviewing credit, but B's own work is still unreviewed.
        $subb = $this->reload($subb);
        $this->assertEqualsWithDelta(20.0, (float)$subb->reviewgrade, 0.0001);
        $this->assertNull($subb->finalgrade);
        $this->assertNull($this->gradebook_grade($activity, (int)$b->id));

        // A reviews B with the top level: 80 + B's 20 of reviewing credit.
        $assignment = $this->plugingenerator->get_open_assignment((int)$subb->id, (int)$a->id);
        $this->plugingenerator->create_review($activity, $assignment, 3);
        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($activity, (int)$b->id), 0.0001);
        $this->assertEqualsWithDelta(60.0, $this->gradebook_grade($activity, (int)$a->id), 0.0001);
    }

    public function test_scheduled_mode_releases_grades_after_the_review_deadline(): void {
        global $DB;

        $activity = $this->create_activity([
            'workflowmode' => 'scheduled',
            'timeclose' => time() - HOURSECS,
            'timereviewdue' => time() + HOURSECS,
        ]);
        [$a, $b] = $this->create_students(2);
        // Submissions were made before the close date.
        $DB->set_field('peerreviewstudio', 'timeclose', time() + HOURSECS, ['id' => $activity->id]);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $this->plugingenerator->create_submission($activity, $b->id);
        $DB->set_field('peerreviewstudio', 'timeclose', time() - HOURSECS, ['id' => $activity->id]);
        $activity = $this->plugingenerator->get_activity($activity);
        peerreviewstudio_process_assignment_queue($activity);

        $this->sink->clear();
        $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id);
        $this->plugingenerator->create_review($activity, $assignment, 3);

        // The grade exists but stays private while reviews are still open.
        $this->assertEqualsWithDelta(100.0, (float)$this->reload($suba)->finalgrade, 0.0001);
        $this->assertNull($this->gradebook_grade($activity, (int)$a->id));
        $this->assertSame([], $this->notifications_for($a->id));

        // The deadline passes: the scheduled task publishes grades and tells the authors, once.
        $DB->set_field('peerreviewstudio', 'timereviewdue', time() - 60, ['id' => $activity->id]);
        $task = new \mod_peerreviewstudio\task\process_assignment_queue_task();
        $task->execute();
        $this->assertEqualsWithDelta(100.0, $this->gradebook_grade($activity, (int)$a->id), 0.0001);
        $this->assertSame(['gradereceived'], $this->notifications_for($a->id));
        $this->assertGreaterThan(0, (int)$DB->get_field('peerreviewstudio', 'timegradesreleased', ['id' => $activity->id]));

        $task->execute();
        $this->assertSame(['gradereceived'], $this->notifications_for($a->id));
    }

    public function test_teacher_override_replaces_the_final_grade_and_is_released_at_once(): void {
        $activity = $this->create_activity(['workflowmode' => 'manual', 'manualphase' => 'submission']);
        [$a] = $this->create_students(1);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $this->sink->clear();

        $this->assertNull(peerreviewstudio_normalize_teacher_grade_override($activity, '  '));
        $this->assertEqualsWithDelta(100.0, peerreviewstudio_normalize_teacher_grade_override($activity, '250'), 0.0001);
        $this->assertEqualsWithDelta(0.0, peerreviewstudio_normalize_teacher_grade_override($activity, '-3'), 0.0001);
        try {
            peerreviewstudio_normalize_teacher_grade_override($activity, 'seven');
            $this->fail('A non-numeric override must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidgradeoverride', $e->errorcode);
        }

        peerreviewstudio_set_teacher_grade_override($activity, (int)$suba->id, 77.5);
        $suba = $this->reload($suba);
        $this->assertEqualsWithDelta(77.5, (float)$suba->finalgrade, 0.0001);
        $this->assertNull($suba->submissiongrade);
        $this->assertEqualsWithDelta(77.5, $this->gradebook_grade($activity, (int)$a->id), 0.0001);
        $this->assertSame(['gradereceived'], $this->notifications_for($a->id));

        // Clearing the override hides the grade again: feedback is not released yet.
        peerreviewstudio_set_teacher_grade_override($activity, (int)$suba->id, null);
        $this->assertNull($this->reload($suba)->finalgrade);
        $this->assertNull($this->gradebook_grade($activity, (int)$a->id));
    }

    public function test_override_accepts_the_grade_as_the_form_displays_it(): void {
        $activity = $this->create_activity();

        // The form shows the stored override with format_float(), so whatever
        // that prints in the user's language must be accepted back.
        $this->assertEqualsWithDelta(
            7.5,
            peerreviewstudio_normalize_teacher_grade_override($activity, format_float(7.5, 2)),
            0.0001
        );
        $this->assertEqualsWithDelta(7.0, peerreviewstudio_normalize_teacher_grade_override($activity, ' 7 '), 0.0001);
    }

    public function test_ai_grade_blends_with_peers_and_stands_in_when_nobody_reviewed(): void {
        global $DB;

        $activity = $this->create_activity(['aireviewenabled' => 1, 'aigradeweight' => 25]);
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);

        // No review and no fallback: ungraded, even with an AI grade stored.
        $DB->set_field('peerreviewstudio_submissions', 'aigrade', 60, ['id' => $suba->id]);
        $this->assertNull(peerreviewstudio_calculate_submission_grade($activity, (int)$suba->id));

        // With the fallback enabled the AI grade stands in for the missing peers.
        $activity->aifallbackenabled = 1;
        $this->assertEqualsWithDelta(60.0, peerreviewstudio_calculate_submission_grade($activity, (int)$suba->id), 0.0001);

        // A peer review of 100 arrives: 75% peers + 25% AI.
        $this->plugingenerator->create_submission($activity, $b->id);
        $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id);
        $this->plugingenerator->create_review($activity, $assignment, 3);
        $this->assertEqualsWithDelta(90.0, peerreviewstudio_calculate_submission_grade($activity, (int)$suba->id), 0.0001);

        // The AI quality assessment scales the reviewer's participation credit.
        $activity->grademaxreview = 20;
        $this->assertEqualsWithDelta(20.0, peerreviewstudio_calculate_review_grade($activity, (int)$b->id), 0.0001);
        $DB->set_field('peerreviewstudio_reviews', 'aireviewergrade', 50, ['reviewerid' => $b->id]);
        $this->assertEqualsWithDelta(10.0, peerreviewstudio_calculate_review_grade($activity, (int)$b->id), 0.0001);
    }

    public function test_changing_the_maximum_grade_rescales_stored_grades(): void {
        global $DB;

        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id]);
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id);
        $this->plugingenerator->create_review($instance, $assignment, 1);
        $this->assertEqualsWithDelta(50.0, (float)$this->reload($suba)->finalgrade, 0.0001);

        // The teacher changes the maximum submission grade from 100 to 10.
        $data = $this->plugingenerator->get_activity($instance);
        $data->instance = $data->id;
        $data->coursemodule = $instance->cmid;
        $data->grademaxsubmission = 10;
        $this->setAdminUser();
        peerreviewstudio_update_instance($data);

        $this->assertEqualsWithDelta(5.0, (float)$DB->get_field('peerreviewstudio_reviews', 'grade', []), 0.0001);
        $this->assertEqualsWithDelta(5.0, (float)$this->reload($suba)->finalgrade, 0.0001);
        $activity = $this->plugingenerator->get_activity($instance);
        $this->assertEqualsWithDelta(5.0, $this->gradebook_grade($activity, (int)$a->id), 0.0001);
    }

    public function test_followups_remind_once_then_withdraw_and_reassign(): void {
        global $DB;

        $activity = $this->create_activity(['reviewreminderdays' => 2, 'reviewreassigndays' => 4]);
        [$a, $b, $c] = $this->create_students(3);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $this->plugingenerator->create_submission($activity, $b->id);
        $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id);
        $this->sink->clear();

        // Nothing happens while the reviewer is within the reminder window.
        peerreviewstudio_process_review_followups($activity);
        $this->assertSame([], $this->notifications_for($b->id));

        // Three days of inactivity: one reminder, and only one.
        $DB->set_field('peerreviewstudio_assignments', 'timeassigned', time() - 3 * DAYSECS, ['id' => $assignment->id]);
        peerreviewstudio_process_review_followups($activity);
        peerreviewstudio_process_review_followups($activity);
        $this->assertSame(['reviewreminder'], $this->notifications_for($b->id));

        // Five days: the review is withdrawn and goes to a new reviewer.
        $this->plugingenerator->create_submission($activity, $c->id);
        $DB->set_field('peerreviewstudio_assignments', 'timeassigned', time() - 5 * DAYSECS, ['id' => $assignment->id]);
        $this->sink->clear();
        peerreviewstudio_process_review_followups($activity);

        $this->assertSame('cancelled', $DB->get_field('peerreviewstudio_assignments', 'status', ['id' => $assignment->id]));
        $this->assertContains('reviewwithdrawn', $this->notifications_for($b->id));
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$suba->id, (int)$c->id));
        $this->assertSame('assigned', $this->reload($suba)->status);
    }

    public function test_followups_tell_tutors_once_when_nothing_else_can_be_done(): void {
        global $DB;

        $activity = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $subb = $this->plugingenerator->create_submission($activity, $b->id);

        // Both submissions already went through three withdrawn reviewers.
        $DB->set_field('peerreviewstudio_assignments', 'status', 'cancelled', []);
        foreach ([$suba, $subb] as $submission) {
            for ($i = 0; $i < 2; $i++) {
                $DB->insert_record('peerreviewstudio_assignments', (object) [
                    'peerreviewstudioid' => $activity->id,
                    'submissionid' => $submission->id,
                    'reviewerid' => 0,
                    'status' => 'cancelled',
                ]);
            }
        }
        $this->sink->clear();

        peerreviewstudio_process_review_followups($activity);
        peerreviewstudio_process_review_followups($activity);

        // One digest covering both learners, sent once.
        $this->assertSame(['tutorescalation'], $this->notifications_for($teacher->id));
        $messages = $this->sink->get_messages();
        $digest = end($messages);
        $this->assertStringContainsString(fullname($a), $digest->fullmessage);
        $this->assertStringContainsString(fullname($b), $digest->fullmessage);
        $this->assertGreaterThan(0, (int)$this->reload($suba)->timeescalated);

        $incidents = peerreviewstudio_get_tutor_incident_rows($activity);
        $this->assertCount(2, $incidents);
        $this->assertSame('escalated', reset($incidents)->incidenttype);

        // A tutor stepping in restarts the automatic cycle for that submission.
        peerreviewstudio_requeue_submission($activity, (int)$suba->id);
        $this->assertSame(0, (int)$this->reload($suba)->timeescalated);
    }

    public function test_followups_clear_open_reviews_after_the_scheduled_deadline(): void {
        global $DB;

        $activity = $this->create_activity([
            'workflowmode' => 'scheduled',
            'timeclose' => time() + HOURSECS,
            'timereviewdue' => time() + 2 * HOURSECS,
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $this->plugingenerator->create_submission($activity, $b->id);
        $DB->set_field('peerreviewstudio', 'timeclose', time() - 2 * HOURSECS, ['id' => $activity->id]);
        peerreviewstudio_process_assignment_queue($this->plugingenerator->get_activity($activity));
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id));

        // Nobody reviewed and the deadline passed.
        $DB->set_field('peerreviewstudio', 'timereviewdue', time() - HOURSECS, ['id' => $activity->id]);
        $this->sink->clear();
        peerreviewstudio_process_review_followups($this->plugingenerator->get_activity($activity));

        $this->assertSame(0, $DB->count_records('peerreviewstudio_assignments', ['status' => 'assigned']));
        $this->assertSame(['tutorescalation'], $this->notifications_for($teacher->id));
        $this->assertGreaterThan(0, (int)$this->reload($suba)->timeescalated);
    }

    public function test_reviewers_matched_before_the_review_phase_are_told_when_it_opens(): void {
        $instance = $this->plugingenerator->create_instance([
            'course' => $this->course->id,
            'workflowmode' => 'manual',
            'manualphase' => 'submission',
        ]);
        [$a, $b] = $this->create_students(2);
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $this->sink->clear();
        $this->setAdminUser();

        // Allocation phase: reviewers are matched but cannot start yet.
        $data = $this->plugingenerator->get_activity($instance);
        $data->instance = $data->id;
        $data->coursemodule = $instance->cmid;
        $data->manualphase = 'allocation';
        peerreviewstudio_update_instance($data);
        $this->assertNotFalse($this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id));
        $this->assertSame([], $this->notifications_for($b->id));
        $activity = $this->plugingenerator->get_activity($instance);
        $this->assertSame('nextstep_reviewsoon', peerreviewstudio_learner_next_step($activity, $suba, 1, 0, true));

        // Review phase: each reviewer is told once.
        $data->manualphase = 'review';
        peerreviewstudio_update_instance($data);
        peerreviewstudio_notify_pending_reviewers($this->plugingenerator->get_activity($instance));
        $this->assertSame(['reviewassigned'], $this->notifications_for($b->id));
    }

    public function test_dashboard_reports_progress_and_respects_separate_groups(): void {
        global $DB;

        $activity = $this->create_activity();
        // Fixed, distinct names: the search below must match exactly one learner.
        $generator = $this->getDataGenerator();
        $a = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Alba', 'lastname' => 'Zuzunaga']);
        $b = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Bruno', 'lastname' => 'Quintanilla']);
        $c = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Carla', 'lastname' => 'Etxeberria']);
        $group = $generator->create_group(['courseid' => $this->course->id]);
        groups_add_member($group, $a);

        $suba = $this->plugingenerator->create_submission($activity, $a->id);
        $this->plugingenerator->create_submission($activity, $b->id);
        $this->plugingenerator->create_submission($activity, $c->id);
        $assignment = $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id);
        $this->plugingenerator->create_review($activity, $assignment, 3);

        $dashboard = dashboard_service::tutor_dashboard($activity);
        $this->assertSame(['total' => 3, 'waiting' => 0, 'inreview' => 2, 'complete' => 1], $dashboard['counts']);
        $this->assertSame(3, $dashboard['total']);
        $this->assertCount(3, $dashboard['rows']);
        $row = $dashboard['rows'][$suba->id];
        $this->assertSame(1, (int)$row->completedcount);
        $this->assertEqualsWithDelta(100.0, (float)$row->finalgrade, 0.0001);

        // Filters: status, name search, and email search only when allowed.
        $this->assertCount(1, dashboard_service::tutor_dashboard($activity, 'closed')['rows']);
        $this->assertCount(1, dashboard_service::tutor_dashboard($activity, '', $a->lastname)['rows']);
        $this->assertCount(0, dashboard_service::tutor_dashboard($activity, '', $a->email)['rows']);
        $this->assertCount(1, dashboard_service::tutor_dashboard($activity, '', $a->email, 0, 0, true)['rows']);

        // Group restriction: one group, and nobody at all.
        $grouped = dashboard_service::tutor_dashboard($activity, '', '', 0, (int)$group->id);
        $this->assertSame([(int)$suba->id], array_map('intval', array_keys($grouped['rows'])));
        $this->assertSame(1, $grouped['counts']['total']);
        $nobody = dashboard_service::tutor_dashboard($activity, '', '', 0, null);
        $this->assertSame(0, $nobody['total']);
        $this->assertSame([], $nobody['incidents']);

        // A submission whose author was erased by a privacy request is not listed.
        $DB->set_field('peerreviewstudio_submissions', 'userid', 0, ['id' => $suba->id]);
        $this->assertSame(2, dashboard_service::tutor_dashboard($activity)['total']);
    }
}
