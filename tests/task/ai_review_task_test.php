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

namespace mod_peerreviewstudio\task;

use mod_peerreviewstudio\local\ai\client;

#[\PHPUnit\Framework\Attributes\CoversClass(ai_review_task::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(ai_fallback_review_task::class)]
/**
 * Unit tests for the AI review tasks.
 *
 * No test here talks to an AI provider: the client is replaced by one that
 * answers from memory.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_peerreviewstudio\task\ai_review_task
 * @covers      \mod_peerreviewstudio\task\ai_fallback_review_task
 */
final class ai_review_task_test extends \advanced_testcase {
    /** @var \stdClass Course used by the test. */
    protected \stdClass $course;

    /** @var \mod_peerreviewstudio_generator Plugin generator. */
    protected \mod_peerreviewstudio_generator $plugingenerator;

    /** @var \phpunit_message_sink Sink collecting the notifications sent. */
    protected \phpunit_message_sink $sink;

    /** @var \mod_peerreviewstudio\local\ai\fake_client AI client answering from memory. */
    protected \mod_peerreviewstudio\local\ai\fake_client $ai;

    /**
     * Common set-up.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_peerreviewstudio');
        $this->sink = $this->redirectMessages();
        $this->ai = $this->plugingenerator->use_fake_ai();
    }

    /**
     * Create a student who has accepted the site AI policy.
     *
     * @return \stdClass
     */
    protected function create_student(): \stdClass {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->plugingenerator->accept_ai_policy((int)$student->id);
        return $student;
    }

    /**
     * Run one task, discarding what it traces.
     *
     * @param \core\task\adhoc_task $task Task to run.
     * @param array $customdata Task custom data.
     */
    protected function run_task(\core\task\adhoc_task $task, array $customdata): void {
        $task->set_custom_data($customdata);
        ob_start();
        try {
            $task->execute();
        } finally {
            ob_end_clean();
        }
    }

    public function test_second_review_grades_the_submission_and_the_reviewer(): void {
        global $DB;

        $this->ai->answer_with(60.0);
        $instance = $this->plugingenerator->create_instance([
            'course' => $this->course->id,
            'aireviewenabled' => 1,
            'aigradeweight' => 50,
            'grademaxreview' => 20,
            'name' => 'Essay on tides',
        ]);
        $a = $this->create_student();
        $b = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id, 'The moon pulls the sea');
        $this->plugingenerator->create_submission($instance, $b->id);
        $review = $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3,
            'Solid explanation'
        );

        // Saving the review queued the AI task.
        $queued = \core\task\manager::get_adhoc_tasks(ai_review_task::class);
        $this->assertCount(1, $queued);
        $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);

        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $suba->id]);
        $this->assertEqualsWithDelta(60.0, (float)$submission->aigrade, 0.0001);
        $this->assertSame('AI feedback', $submission->aifeedback);
        // Peers proposed 100, the AI 60, weighted half and half.
        $this->assertEqualsWithDelta(80.0, (float)$submission->submissiongrade, 0.0001);

        $review = $DB->get_record('peerreviewstudio_reviews', ['id' => $review->id]);
        $this->assertEqualsWithDelta(60.0, (float)$review->aireviewergrade, 0.0001);
        // The reviewer's participation credit (20) is scaled by the AI assessment of the review.
        $this->assertEqualsWithDelta(
            12.0,
            (float)$DB->get_field('peerreviewstudio_submissions', 'reviewgrade', ['userid' => $b->id]),
            0.0001
        );

        // What was sent: the work and the review, wrapped as untrusted, and no identities.
        $requests = $this->ai->requests;
        $this->assertCount(2, $requests);
        $this->assertStringContainsString('Essay on tides', $requests[0]->prompt);
        $this->assertStringContainsString(client::wrap_untrusted('The moon pulls the sea'), $requests[0]->prompt);
        $this->assertStringContainsString('Solid explanation', $requests[1]->prompt);
        foreach ($requests as $request) {
            foreach ([$a, $b] as $user) {
                $this->assertStringNotContainsString($user->email, $request->prompt);
                $this->assertStringNotContainsString(fullname($user), $request->prompt);
            }
            $this->assertEquals(\context_module::instance($instance->cmid)->id, $request->contextid);
        }
        // Each request is made for the person who wrote the content: the author, then the reviewer.
        $this->assertEquals($a->id, $requests[0]->userid);
        $this->assertEquals($b->id, $requests[1]->userid);

        // Running the task again asks nothing: both assessments are stored.
        $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);
        $this->assertCount(2, $this->ai->requests);
    }

    public function test_a_failing_provider_fails_the_task_so_it_is_retried(): void {
        $this->ai->answer_with(null);
        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id, 'aireviewenabled' => 1]);
        $a = $this->create_student();
        $b = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $review = $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3
        );

        try {
            $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);
            $this->fail('The task must fail while the provider fails.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aireviewfailed', $e->errorcode);
        }

        // After retrying for long enough it gives up instead of failing forever.
        $task = new ai_review_task();
        $task->set_fail_delay(ai_review_task::GIVE_UP_FAIL_DELAY);
        $this->run_task($task, ['reviewid' => $review->id]);
    }

    public function test_work_the_ai_cannot_read_is_not_graded_by_it(): void {
        global $DB;

        $this->ai->answer_with(90.0);
        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id, 'aireviewenabled' => 1]);
        $a = $this->create_student();
        $b = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id, 'See the attached scan');
        $this->plugingenerator->create_submission($instance, $b->id);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($instance->cmid)->id,
            'component' => 'mod_peerreviewstudio',
            'filearea' => 'submission_files',
            'itemid' => $suba->id,
            'filepath' => '/',
            'filename' => 'scan.pdf',
        ], '%PDF-1.7');
        $review = $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3
        );

        $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);

        // Only the review itself was assessed.
        $this->assertNull($DB->get_field('peerreviewstudio_submissions', 'aigrade', ['id' => $suba->id]));
        $this->assertEqualsWithDelta(90.0, (float)$DB->get_field('peerreviewstudio_reviews', 'aireviewergrade', []), 0.0001);
        $this->assertCount(1, $this->ai->requests);
    }

    public function test_fallback_review_grades_and_closes_an_unreviewed_submission(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');

        $this->ai->answer_with(70.0);
        $instance = $this->plugingenerator->create_instance([
            'course' => $this->course->id,
            'aireviewenabled' => 1,
            'aifallbackenabled' => 1,
        ]);
        $activity = $this->plugingenerator->get_activity($instance);
        $a = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id, 'Lonely essay');

        // Waiting for a reviewer for longer than the reassignment threshold.
        $DB->set_field('peerreviewstudio_queue', 'timecreated', time() - 5 * DAYSECS, ['submissionid' => $suba->id]);
        $this->sink->clear();
        $this->assertSame(1, peerreviewstudio_escalate_unresolved_submissions($activity));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(ai_fallback_review_task::class));

        $this->run_task(new ai_fallback_review_task(), ['submissionid' => $suba->id]);

        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $suba->id]);
        $this->assertSame('closed', $submission->status);
        $this->assertEqualsWithDelta(70.0, (float)$submission->finalgrade, 0.0001);
        $this->assertSame(0, $DB->count_records('peerreviewstudio_queue', ['status' => 'pending']));
        $grades = grade_get_grades($this->course->id, 'mod', 'peerreviewstudio', $instance->id, $a->id);
        $this->assertEqualsWithDelta(70.0, (float)$grades->items[0]->grades[$a->id]->grade, 0.0001);
        $names = array_map(static fn($message) => $message->eventtype, $this->sink->get_messages());
        $this->assertSame(['gradereceived'], array_values($names));
        $this->assertSame([], peerreviewstudio_get_tutor_incident_rows($activity));
    }

    public function test_fallback_hands_over_to_tutors_when_the_ai_cannot_help(): void {
        global $DB;

        $this->ai->answer_with(null);
        $instance = $this->plugingenerator->create_instance([
            'course' => $this->course->id,
            'aireviewenabled' => 1,
            'aifallbackenabled' => 1,
        ]);
        $activity = $this->plugingenerator->get_activity($instance);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $a = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $DB->set_field('peerreviewstudio_queue', 'timecreated', time() - 5 * DAYSECS, ['submissionid' => $suba->id]);
        peerreviewstudio_escalate_unresolved_submissions($activity);
        $this->sink->clear();

        // First failures are retried.
        try {
            $this->run_task(new ai_fallback_review_task(), ['submissionid' => $suba->id]);
            $this->fail('The task must fail while the provider fails.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aifallbackfailed', $e->errorcode);
        }
        $this->assertSame([], $this->sink->get_messages());

        // Once it gives up, the tutors hear about it and the learner stays visible as an incident.
        $task = new ai_fallback_review_task();
        $task->set_fail_delay(ai_review_task::GIVE_UP_FAIL_DELAY);
        $this->run_task($task, ['submissionid' => $suba->id]);

        $messages = $this->sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('tutorescalation', reset($messages)->eventtype);
        $this->assertEquals($teacher->id, reset($messages)->useridto);
        $this->assertNull($DB->get_field('peerreviewstudio_submissions', 'finalgrade', ['id' => $suba->id]));
        $incidents = peerreviewstudio_get_tutor_incident_rows($activity);
        $this->assertSame('escalated', reset($incidents)->incidenttype);
    }

    public function test_content_is_only_sent_once_its_author_accepts_the_ai_policy(): void {
        global $DB;

        $this->ai->answer_with(75.0);
        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id, 'aireviewenabled' => 1]);
        $activity = $this->plugingenerator->get_activity($instance);
        // The author has not accepted the AI policy; the reviewer has.
        $a = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $b = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id, 'Private essay about glaciers');
        $this->plugingenerator->create_submission($instance, $b->id);
        $review = $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3,
            'Well argued'
        );
        $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);

        // Only the review was assessed, and the author's work was not shown to the AI.
        $this->assertCount(1, $this->ai->requests);
        $this->assertEquals($b->id, $this->ai->requests[0]->userid);
        $this->assertStringContainsString('Well argued', $this->ai->requests[0]->prompt);
        $this->assertStringNotContainsString('glaciers', $this->ai->requests[0]->prompt);
        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $suba->id]);
        $this->assertNull($submission->aigrade);
        $this->assertEquals(0, $submission->aitimegraded);

        // Nothing is waiting for the reviewer, and nothing can be queued for the author yet.
        $DB->delete_records('task_adhoc');
        $this->assertSame(0, peerreviewstudio_queue_pending_ai_reviews($activity, (int)$b->id));
        $this->assertSame(0, peerreviewstudio_queue_pending_ai_reviews($activity, (int)$a->id));

        // Once the author accepts the policy, the evaluation that was waiting is queued and done.
        $this->plugingenerator->accept_ai_policy((int)$a->id);
        $this->assertSame(1, peerreviewstudio_queue_pending_ai_reviews($activity, (int)$a->id));
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(ai_review_task::class));
        // Asking again does not queue the same review twice.
        peerreviewstudio_queue_pending_ai_reviews($activity, (int)$a->id);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(ai_review_task::class));

        $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);
        $this->assertCount(2, $this->ai->requests);
        $this->assertEquals($a->id, $this->ai->requests[1]->userid);
        $this->assertStringContainsString('glaciers', $this->ai->requests[1]->prompt);
        $this->assertEqualsWithDelta(
            75.0,
            (float)$DB->get_field('peerreviewstudio_submissions', 'aigrade', ['id' => $suba->id]),
            0.0001
        );
        $this->assertSame(0, peerreviewstudio_queue_pending_ai_reviews($activity, (int)$a->id));
    }

    public function test_a_request_limit_is_retried_for_longer_and_giving_up_is_final(): void {
        global $DB;

        $this->ai->answer_with(null, 429);
        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id, 'aireviewenabled' => 1]);
        $activity = $this->plugingenerator->get_activity($instance);
        $a = $this->create_student();
        $b = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $review = $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3
        );

        // Where another failure would be abandoned, a request limit is still retried.
        $task = new ai_review_task();
        $task->set_fail_delay(ai_review_task::GIVE_UP_FAIL_DELAY);
        try {
            $this->run_task($task, ['reviewid' => $review->id]);
            $this->fail('The task must keep failing while the request limit is reached.');
        } catch (\moodle_exception $e) {
            $this->assertSame('aireviewfailed', $e->errorcode);
        }
        $this->assertEquals(0, $DB->get_field('peerreviewstudio_reviews', 'aitimegraded', ['id' => $review->id]));

        // Once the task gives up, both evaluations are marked as attempted and are not queued again.
        $task = new ai_review_task();
        $task->set_fail_delay(ai_review_task::GIVE_UP_RATE_LIMIT_FAIL_DELAY);
        $this->run_task($task, ['reviewid' => $review->id]);
        $this->assertGreaterThan(0, $DB->get_field('peerreviewstudio_reviews', 'aitimegraded', ['id' => $review->id]));
        $this->assertGreaterThan(0, $DB->get_field('peerreviewstudio_submissions', 'aitimegraded', ['id' => $suba->id]));
        $this->assertNull($DB->get_field('peerreviewstudio_submissions', 'aigrade', ['id' => $suba->id]));
        $this->assertSame(0, peerreviewstudio_queue_pending_ai_reviews($activity, (int)$a->id));
        $this->assertSame(0, peerreviewstudio_queue_pending_ai_reviews($activity, (int)$b->id));
    }

    public function test_fallback_hands_over_to_tutors_when_the_author_has_not_accepted_the_ai_policy(): void {
        global $DB;

        $this->ai->answer_with(70.0);
        $instance = $this->plugingenerator->create_instance([
            'course' => $this->course->id,
            'aireviewenabled' => 1,
            'aifallbackenabled' => 1,
        ]);
        $activity = $this->plugingenerator->get_activity($instance);
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $a = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $DB->set_field('peerreviewstudio_queue', 'timecreated', time() - 5 * DAYSECS, ['submissionid' => $suba->id]);
        peerreviewstudio_escalate_unresolved_submissions($activity);
        $this->sink->clear();

        $this->run_task(new ai_fallback_review_task(), ['submissionid' => $suba->id]);

        $this->assertSame([], $this->ai->requests);
        $messages = $this->sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame('tutorescalation', reset($messages)->eventtype);
        $this->assertEquals($teacher->id, reset($messages)->useridto);
        $this->assertNull($DB->get_field('peerreviewstudio_submissions', 'aigrade', ['id' => $suba->id]));
    }

    public function test_nothing_is_queued_when_no_ai_provider_is_available(): void {
        $this->ai->available = false;
        $instance = $this->plugingenerator->create_instance(['course' => $this->course->id, 'aireviewenabled' => 1]);
        $a = $this->create_student();
        $b = $this->create_student();
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $review = $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3
        );

        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(ai_review_task::class));
        // A task left over from before the provider was removed does nothing.
        $this->run_task(new ai_review_task(), ['reviewid' => $review->id]);
        $this->assertSame([], $this->ai->requests);
    }
}
