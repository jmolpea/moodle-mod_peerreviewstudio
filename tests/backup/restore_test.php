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

namespace mod_peerreviewstudio\backup;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\backup_peerreviewstudio_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_peerreviewstudio_activity_structure_step::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\restore_peerreviewstudio_activity_task::class)]
/**
 * Backup and restore round-trip tests.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \backup_peerreviewstudio_activity_structure_step
 * @covers      \restore_peerreviewstudio_activity_structure_step
 * @covers      \restore_peerreviewstudio_activity_task
 */
final class restore_test extends \advanced_testcase {
    /**
     * Back up a course with user data and logs, and restore it as a new course.
     *
     * @param \stdClass $course Course to copy.
     * @return int Id of the restored course.
     */
    protected function backup_and_restore(\stdClass $course): int {
        global $CFG, $USER;

        // Keep the unpacked backup so it can be restored directly.
        $CFG->keeptempdirectoriesonbackup = 1;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->get_plan()->get_setting('logs')->set_value(true);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            $course->fullname . ' copy',
            $course->shortname . 'copy',
            $course->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        // Restoring logs calls the activity's restore log rules.
        $rc->get_plan()->get_setting('logs')->set_value(true);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    public function test_course_copy_with_user_data_restores_the_whole_activity(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_peerreviewstudio');
        $course = $generator->create_course(['startdate' => time() - 10 * DAYSECS]);
        $instance = $plugingenerator->create_instance([
            'course' => $course->id,
            'name' => 'Peer essay',
            'grademaxsubmission' => 80,
            'grademaxreview' => 20,
            'completionsubmit' => 1,
            'aipromptsubmission' => 'Custom grading prompt',
        ]);
        $a = $generator->create_and_enrol($course, 'student');
        $b = $generator->create_and_enrol($course, 'student');
        $suba = $plugingenerator->create_submission($instance, $a->id, 'Essay by A');
        $plugingenerator->create_submission($instance, $b->id, 'Essay by B');
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($instance->cmid)->id,
            'component' => 'mod_peerreviewstudio',
            'filearea' => 'submission_files',
            'itemid' => $suba->id,
            'filepath' => '/',
            'filename' => 'essay.txt',
        ], 'Essay file');
        $plugingenerator->create_review(
            $instance,
            $plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id),
            3,
            'Well argued'
        );

        // Something in the log for the activity, so the log restore has work to do.
        \mod_peerreviewstudio\event\course_module_viewed::create([
            'objectid' => $instance->id,
            'context' => \context_module::instance($instance->cmid),
        ])->trigger();

        $newcourseid = $this->backup_and_restore($course);

        $restored = $DB->get_record('peerreviewstudio', ['course' => $newcourseid], '*', MUST_EXIST);
        $this->assertNotEquals($instance->id, $restored->id);
        $this->assertSame('Peer essay', $restored->name);
        $this->assertEqualsWithDelta(80.0, (float)$restored->grademaxsubmission, 0.0001);
        $this->assertSame(1, (int)$restored->completionsubmit);
        $this->assertSame('Custom grading prompt', $restored->aipromptsubmission);

        // Rubric: same shape, new ids.
        $oldrubric = peerreviewstudio_get_activity_rubric((int)$instance->id);
        $newrubric = peerreviewstudio_get_activity_rubric((int)$restored->id);
        $this->assertSame(array_column($oldrubric, 'label'), array_column($newrubric, 'label'));
        $this->assertSame([], array_intersect(array_column($oldrubric, 'id'), array_column($newrubric, 'id')));

        // Learner data.
        $submissions = $DB->get_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $restored->id], 'timecreated, id');
        $this->assertCount(2, $submissions);
        $newsuba = $DB->get_record('peerreviewstudio_submissions', [
            'peerreviewstudioid' => $restored->id,
            'userid' => $a->id,
        ], '*', MUST_EXIST);
        $this->assertSame('Essay by A', $newsuba->onlinetext);
        $this->assertSame('closed', $newsuba->status);
        $this->assertEqualsWithDelta(80.0, (float)$newsuba->finalgrade, 0.0001);

        $assignments = $DB->get_records('peerreviewstudio_assignments', ['peerreviewstudioid' => $restored->id]);
        $this->assertCount(2, $assignments);
        $review = $DB->get_record('peerreviewstudio_reviews', ['submissionid' => $newsuba->id], '*', MUST_EXIST);
        $this->assertSame((int)$b->id, (int)$review->reviewerid);
        $this->assertSame('Well argued', $review->generalcomment);

        // Rubric answers point at the restored criteria and levels.
        $answers = $DB->get_records('peerreviewstudio_r_answers', ['reviewid' => $review->id]);
        $this->assertCount(3, $answers);
        $newlevelids = [];
        foreach ($newrubric as $criterion) {
            $newlevelids = array_merge($newlevelids, array_column($criterion['levels'], 'id'));
        }
        foreach ($answers as $answer) {
            $this->assertContains((int)$answer->criterionid, array_column($newrubric, 'id'));
            $this->assertContains((int)$answer->levelid, $newlevelids);
        }
        $feedback = peerreviewstudio_get_rubric_answers_by_review([(int)$review->id]);
        $this->assertCount(3, $feedback[(int)$review->id]);

        // The submission file came along.
        $newcm = get_coursemodule_from_instance('peerreviewstudio', $restored->id, $newcourseid, false, MUST_EXIST);
        $files = get_file_storage()->get_area_files(
            \context_module::instance($newcm->id)->id,
            'mod_peerreviewstudio',
            'submission_files',
            $newsuba->id,
            'filename',
            false
        );
        $this->assertCount(1, $files);
        $this->assertSame('essay.txt', reset($files)->get_filename());
    }

    public function test_duplicating_the_activity_copies_the_design_without_learner_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $this->redirectMessages();

        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_peerreviewstudio');
        $course = $generator->create_course();
        $instance = $plugingenerator->create_instance([
            'course' => $course->id,
            'workflowmode' => 'scheduled',
            'timeclose' => time() + DAYSECS,
            'timereviewdue' => time() + 2 * DAYSECS,
        ]);
        $a = $generator->create_and_enrol($course, 'student');
        $plugingenerator->create_submission($instance, $a->id);

        if (method_exists('\core_courseformat\local\cmactions', 'duplicate')) {
            $newcm = \core_courseformat\formatactions::cm($course)->duplicate((int)$instance->cmid);
        } else {
            $newcm = duplicate_module($course, get_fast_modinfo($course)->get_cm($instance->cmid));
        }

        $copy = $DB->get_record('peerreviewstudio', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertSame('scheduled', $copy->workflowmode);
        $this->assertCount(3, peerreviewstudio_get_activity_rubric((int)$copy->id));
        $this->assertSame(0, $DB->count_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $copy->id]));
        $this->assertSame(1, $DB->count_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $instance->id]));
        // The copy gets its own deadlines in the calendar.
        $this->assertSame(2, $DB->count_records('event', ['modulename' => 'peerreviewstudio', 'instance' => $copy->id]));
    }
}
