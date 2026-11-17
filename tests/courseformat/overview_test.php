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

namespace mod_peerreviewstudio\courseformat;

/**
 * Tests for the course activity overview (Moodle 5.0 and later).
 *
 * @package     mod_peerreviewstudio
 * @category    test
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \mod_peerreviewstudio\courseformat\overview
 */
final class overview_test extends \advanced_testcase {
    /**
     * Skip on Moodle versions without the activity overview API.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!class_exists('\core_courseformat\activityoverviewbase')) {
            $this->markTestSkipped('The course activity overview needs Moodle 5.0 or later.');
        }
        $this->resetAfterTest();
        $this->redirectMessages();
    }

    /**
     * Build the overview of one activity for the current user.
     *
     * @param \stdClass $course Course.
     * @param int $cmid Course module id.
     * @return overview
     */
    private function get_overview(\stdClass $course, int $cmid): overview {
        $cm = get_fast_modinfo($course)->get_cm($cmid);
        return \core_courseformat\local\overview\overviewfactory::create($cm);
    }

    public function test_teacher_and_learner_items(): void {
        $generator = $this->getDataGenerator();
        $plugingenerator = $generator->get_plugin_generator('mod_peerreviewstudio');
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $a = $generator->create_and_enrol($course, 'student');
        $b = $generator->create_and_enrol($course, 'student');
        $generator->create_and_enrol($course, 'student');
        $instance = $plugingenerator->create_instance(['course' => $course->id]);
        $suba = $plugingenerator->create_submission($instance, $a->id);
        $plugingenerator->create_submission($instance, $b->id);
        $plugingenerator->create_review(
            $instance,
            $plugingenerator->get_open_assignment((int)$suba->id, (int)$b->id)
        );

        // Tutor.
        $this->setUser($teacher);
        $overview = $this->get_overview($course, (int)$instance->cmid);
        $items = $overview->get_extra_overview_items();
        $this->assertSame('ongoing', $items['phase']->get_value());
        $this->assertSame(2, $items['submissions']->get_value());
        $this->assertSame(1, $items['reviews']->get_value());
        $this->assertNull($items['mysubmission']);
        $this->assertNull($items['myreviews']);
        $this->assertNull($overview->get_due_date_overview()->get_value());
        $this->assertNotNull($overview->get_actions_overview());

        // Learner who submitted and reviewed.
        $this->setUser($b);
        $overview = $this->get_overview($course, (int)$instance->cmid);
        $items = $overview->get_extra_overview_items();
        $this->assertNull($items['submissions']);
        $this->assertNull($items['reviews']);
        $this->assertNull($overview->get_actions_overview());
        $this->assertNotSame('notsubmitted', $items['mysubmission']->get_value());
        $this->assertSame(1, $items['myreviews']->get_value());
    }

    public function test_learner_without_submission(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $student = $generator->create_and_enrol($course, 'student');
        $instance = $generator->get_plugin_generator('mod_peerreviewstudio')->create_instance(['course' => $course->id]);

        $this->setUser($student);
        $overview = $this->get_overview($course, (int)$instance->cmid);
        $items = $overview->get_extra_overview_items();
        $this->assertSame('ongoing', $items['phase']->get_value());
        $this->assertSame('notsubmitted', $items['mysubmission']->get_value());
        $this->assertSame(0, $items['myreviews']->get_value());
        $this->assertNull($overview->get_due_date_overview()->get_value());

        // Scheduled mode shows the deadline of the current phase.
        $timeclose = time() + DAYSECS;
        $scheduled = $generator->get_plugin_generator('mod_peerreviewstudio')->create_instance([
            'course' => $course->id,
            'workflowmode' => 'scheduled',
            'timeclose' => $timeclose,
            'timereviewdue' => $timeclose + DAYSECS,
        ]);
        $overview = $this->get_overview($course, (int)$scheduled->cmid);
        $this->assertSame('submission', $overview->get_extra_overview_items()['phase']->get_value());
        $this->assertSame($timeclose, $overview->get_due_date_overview()->get_value());
    }
}
