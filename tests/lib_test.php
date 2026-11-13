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

use mod_peerreviewstudio\completion\custom_completion;

/**
 * Unit tests for the module API callbacks in lib.php.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      ::peerreviewstudio_add_instance
 * @covers      ::peerreviewstudio_update_instance
 * @covers      ::peerreviewstudio_delete_instance
 * @covers      ::peerreviewstudio_reset_userdata
 * @covers      ::peerreviewstudio_set_calendar_events
 * @covers      ::mod_peerreviewstudio_core_calendar_provide_event_action
 * @covers      \mod_peerreviewstudio\completion\custom_completion
 * @covers      \mod_peerreviewstudio\dates
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_add_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_update_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_delete_instance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_reset_userdata')]
#[\PHPUnit\Framework\Attributes\CoversFunction('peerreviewstudio_set_calendar_events')]
#[\PHPUnit\Framework\Attributes\CoversFunction('mod_peerreviewstudio_core_calendar_provide_event_action')]
#[\PHPUnit\Framework\Attributes\CoversClass(custom_completion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(dates::class)]
final class lib_test extends \advanced_testcase {
    /** @var \mod_peerreviewstudio_generator Plugin generator. */
    protected \mod_peerreviewstudio_generator $plugingenerator;

    /**
     * Common set-up.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

        $this->resetAfterTest();
        $this->plugingenerator = $this->getDataGenerator()->get_plugin_generator('mod_peerreviewstudio');
        $this->redirectMessages();
    }

    public function test_instance_lifecycle_creates_and_removes_everything(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->plugingenerator->create_instance([
            'course' => $course->id,
            'grademaxsubmission' => 80,
            'grademaxreview' => 20,
            'workflowmode' => 'scheduled',
            'timeclose' => time() + DAYSECS,
            'timereviewdue' => time() + 2 * DAYSECS,
            // A prompt equal to the default is stored empty.
            'aipromptsubmission' => get_string('aipromptsubmission_default', 'mod_peerreviewstudio'),
        ]);
        $activity = $this->plugingenerator->get_activity($instance);

        $this->assertSame('', $activity->aipromptsubmission);
        $this->assertCount(3, peerreviewstudio_get_activity_rubric((int)$activity->id));
        $gradeitem = \grade_item::fetch(['itemmodule' => 'peerreviewstudio', 'iteminstance' => $activity->id]);
        $this->assertEqualsWithDelta(100.0, (float)$gradeitem->grademax, 0.0001);
        $this->assertSame(2, $DB->count_records('event', ['modulename' => 'peerreviewstudio', 'instance' => $activity->id]));

        // Leaving scheduled mode removes the deadlines from the calendar.
        $this->setAdminUser();
        $data = clone $activity;
        $data->instance = $activity->id;
        $data->coursemodule = $instance->cmid;
        $data->workflowmode = 'open';
        $data->grademaxreview = 0;
        peerreviewstudio_update_instance($data);
        $this->assertSame(0, $DB->count_records('event', ['modulename' => 'peerreviewstudio', 'instance' => $activity->id]));
        $gradeitem = \grade_item::fetch(['itemmodule' => 'peerreviewstudio', 'iteminstance' => $activity->id]);
        $this->assertEqualsWithDelta(80.0, (float)$gradeitem->grademax, 0.0001);

        // Learner data, then deletion of the whole activity.
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3
        );

        if (method_exists('\core_courseformat\local\cmactions', 'delete')) {
            \core_courseformat\formatactions::cm($course)->delete((int)$instance->cmid);
        } else {
            course_delete_module($instance->cmid);
        }
        foreach (
            [
            'peerreviewstudio', 'peerreviewstudio_submissions', 'peerreviewstudio_assignments', 'peerreviewstudio_reviews',
            'peerreviewstudio_r_answers', 'peerreviewstudio_queue', 'peerreviewstudio_rubrics',
            'peerreviewstudio_r_criteria', 'peerreviewstudio_r_levels',
            ] as $table
        ) {
            $this->assertSame(0, $DB->count_records($table), $table);
        }
        $this->assertFalse(\grade_item::fetch(['itemmodule' => 'peerreviewstudio', 'iteminstance' => $activity->id]));
    }

    public function test_completion_rules_follow_submission_and_reviews(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $instance = $this->plugingenerator->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
            'completionreviews' => 1,
            'reviewcountrequired' => 1,
        ]);
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $cm = get_fast_modinfo($course)->get_cm($instance->cmid);
        $completion = new \completion_info($course);

        $custom = new custom_completion($cm, (int)$a->id);
        $this->assertSame(['completionsubmit', 'completionreviews'], custom_completion::get_defined_custom_rules());
        $this->assertSame(COMPLETION_INCOMPLETE, $custom->get_state('completionsubmit'));
        $this->assertArrayHasKey('completionreviews', $custom->get_custom_rule_descriptions());

        // Submitting satisfies one rule only.
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $subb = $this->plugingenerator->create_submission($instance, $b->id);
        $this->assertSame(COMPLETION_COMPLETE, $custom->get_state('completionsubmit'));
        $this->assertSame(COMPLETION_INCOMPLETE, $custom->get_state('completionreviews'));
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $a->id)->completionstate);

        // Completing the required review completes the activity for the reviewer.
        $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$subb->id, (int)$a->id),
            2
        );
        $this->assertSame(COMPLETION_COMPLETE, $custom->get_state('completionreviews'));
        $this->assertEquals(COMPLETION_COMPLETE, $completion->get_data($cm, false, $a->id)->completionstate);
        $this->assertEquals(COMPLETION_INCOMPLETE, $completion->get_data($cm, false, $b->id)->completionstate);
        $this->assertNotEmpty($suba->id);
    }

    public function test_calendar_events_offer_the_pending_action(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->plugingenerator->create_instance([
            'course' => $course->id,
            'workflowmode' => 'scheduled',
            'timeclose' => time() + DAYSECS,
            'timereviewdue' => time() + 2 * DAYSECS,
        ]);
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $factory = new \core_calendar\action_factory();
        $load = static function (string $type) use ($DB, $instance): \calendar_event {
            return \calendar_event::load($DB->get_field('event', 'id', [
                'modulename' => 'peerreviewstudio',
                'instance' => $instance->id,
                'eventtype' => $type,
            ], MUST_EXIST));
        };

        // Dates on the activity page come from the same settings.
        $cm = get_fast_modinfo($course, $a->id)->get_cm($instance->cmid);
        $dates = \core\activity_dates::get_dates_for_module($cm, (int)$a->id);
        $this->assertSame(['timeclose', 'timereviewdue'], array_column($dates, 'dataid'));

        // A learner who has not submitted is asked to; a teacher is not.
        $submitevent = $load(PEERREVIEWSTUDIO_EVENT_TYPE_SUBMISSION);
        $action = mod_peerreviewstudio_core_calendar_provide_event_action($submitevent, $factory, (int)$a->id);
        $this->assertNotNull($action);
        $this->assertTrue($action->is_actionable());
        $this->assertNull(mod_peerreviewstudio_core_calendar_provide_event_action($submitevent, $factory, (int)$teacher->id));

        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);
        $this->assertNull(mod_peerreviewstudio_core_calendar_provide_event_action($submitevent, $factory, (int)$a->id));

        // No review is pending until reviewers are matched.
        $reviewevent = $load(PEERREVIEWSTUDIO_EVENT_TYPE_REVIEW);
        $this->assertNull(mod_peerreviewstudio_core_calendar_provide_event_action($reviewevent, $factory, (int)$b->id));

        $DB->set_field('peerreviewstudio', 'timeclose', time() - 60, ['id' => $instance->id]);
        peerreviewstudio_process_assignment_queue($this->plugingenerator->get_activity($instance));
        $action = mod_peerreviewstudio_core_calendar_provide_event_action($reviewevent, $factory, (int)$b->id);
        $this->assertNotNull($action);
        $this->assertSame(1, $action->get_item_count());
        $this->assertTrue($action->is_actionable());

        $this->plugingenerator->create_review(
            $instance,
            $this->plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            1
        );
        $this->assertNull(mod_peerreviewstudio_core_calendar_provide_event_action($reviewevent, $factory, (int)$b->id));
    }

    public function test_course_reset_removes_learner_data_and_shifts_dates(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $timeclose = time() - DAYSECS;
        $instance = $this->plugingenerator->create_instance([
            'course' => $course->id,
            'workflowmode' => 'scheduled',
            'timeclose' => $timeclose + 2 * DAYSECS,
            'timereviewdue' => $timeclose + 3 * DAYSECS,
        ]);
        $a = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $b = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suba = $this->plugingenerator->create_submission($instance, $a->id);
        $this->plugingenerator->create_submission($instance, $b->id);

        // Attach a file to check that files go too.
        $context = \context_module::instance($instance->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_peerreviewstudio',
            'filearea' => 'submission_files',
            'itemid' => $suba->id,
            'filepath' => '/',
            'filename' => 'essay.txt',
        ], 'Essay');

        $defaults = peerreviewstudio_reset_course_form_defaults($course);
        $this->assertSame(['reset_peerreviewstudio' => 1], $defaults);

        $status = peerreviewstudio_reset_userdata((object) [
            'courseid' => $course->id,
            'reset_peerreviewstudio' => 1,
            'timeshift' => 7 * DAYSECS,
        ]);
        $this->assertCount(2, $status);
        $this->assertSame([false, false], array_column($status, 'error'));

        $this->assertSame(0, $DB->count_records('peerreviewstudio_submissions'));
        $this->assertSame(0, $DB->count_records('peerreviewstudio_queue'));
        $this->assertSame([], get_file_storage()->get_area_files($context->id, 'mod_peerreviewstudio', 'submission_files'));
        // The rubric is part of the activity design and stays.
        $this->assertCount(3, peerreviewstudio_get_activity_rubric((int)$instance->id));

        $activity = $this->plugingenerator->get_activity($instance);
        $this->assertEquals($timeclose + 9 * DAYSECS, (int)$activity->timeclose);
        $this->assertEquals(
            (int)$activity->timeclose,
            (int)$DB->get_field('event', 'timestart', [
                'modulename' => 'peerreviewstudio',
                'instance' => $instance->id,
                'eventtype' => PEERREVIEWSTUDIO_EVENT_TYPE_SUBMISSION,
            ])
        );
    }
}
