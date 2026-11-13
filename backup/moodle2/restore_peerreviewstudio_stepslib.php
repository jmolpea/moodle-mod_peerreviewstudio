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

/**
 * Restore structure step for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores the peerreviewstudio structure from a backup.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_peerreviewstudio_activity_structure_step extends restore_activity_structure_step {
    /**
     * Define the restore paths.
     *
     * @return restore_path_element[]
     */
    protected function define_structure() {
        $rubricpath = '/activity/peerreviewstudio/rubrics/rubric';
        $submissionpath = '/activity/peerreviewstudio/submissions/submission';
        $assignmentpath = $submissionpath . '/assignments/assignment';
        $reviewpath = $assignmentpath . '/reviews/review';

        $paths = [
            new restore_path_element('peerreviewstudio', '/activity/peerreviewstudio'),
            new restore_path_element('peerreviewstudio_rubric', $rubricpath),
            new restore_path_element('peerreviewstudio_criterion', $rubricpath . '/criteria/criterion'),
            new restore_path_element('peerreviewstudio_level', $rubricpath . '/criteria/criterion/levels/level'),
        ];

        // Learner data is only restored when user information is included.
        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element('peerreviewstudio_submission', $submissionpath);
            $paths[] = new restore_path_element('peerreviewstudio_assignment', $assignmentpath);
            $paths[] = new restore_path_element('peerreviewstudio_review', $reviewpath);
            $paths[] = new restore_path_element('peerreviewstudio_answer', $reviewpath . '/answers/answer');
            $paths[] = new restore_path_element('peerreviewstudio_queueitem', $submissionpath . '/queueitems/queueitem');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the activity record.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        // The same dates are rolled forward by a course reset.
        foreach (['timeopen', 'timeclose', 'timereviewdue'] as $datefield) {
            $data->{$datefield} = $this->apply_date_offset($data->{$datefield} ?? 0);
        }
        // The restored activity releases its feedback again when due.
        $data->timegradesreleased = 0;
        $newitemid = $DB->insert_record('peerreviewstudio', $data);
        $this->apply_activity_instance($newitemid);
        $this->set_mapping('peerreviewstudio', $oldid, $newitemid);
    }

    /**
     * Restore one rubric.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_rubric($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->peerreviewstudioid = $this->get_new_parentid('peerreviewstudio');
        $newitemid = $DB->insert_record('peerreviewstudio_rubrics', $data);
        $this->set_mapping('peerreviewstudio_rubric', $oldid, $newitemid);
    }

    /**
     * Restore one rubric criterion.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_criterion($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->rubricid = $this->get_new_parentid('peerreviewstudio_rubric');
        $newitemid = $DB->insert_record('peerreviewstudio_r_criteria', $data);
        $this->set_mapping('peerreviewstudio_criterion', $oldid, $newitemid);
    }

    /**
     * Restore one rubric level.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_level($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->criterionid = $this->get_new_parentid('peerreviewstudio_criterion');
        $newitemid = $DB->insert_record('peerreviewstudio_r_levels', $data);
        $this->set_mapping('peerreviewstudio_level', $oldid, $newitemid);
    }

    /**
     * Restore one submission.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_submission($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->peerreviewstudioid = $this->get_new_parentid('peerreviewstudio');
        $data->userid = $this->get_mappingid('user', $data->userid, 0);
        $newitemid = $DB->insert_record('peerreviewstudio_submissions', $data);
        // The mapping keeps the old context files link so attached submission files can be restored.
        $this->set_mapping('peerreviewstudio_submission', $oldid, $newitemid, true);
    }

    /**
     * Restore one reviewer assignment.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_assignment($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->peerreviewstudioid = $this->task->get_activityid();
        $data->submissionid = $this->get_new_parentid('peerreviewstudio_submission');
        $data->reviewerid = $this->get_mappingid('user', $data->reviewerid, 0);
        $newitemid = $DB->insert_record('peerreviewstudio_assignments', $data);
        $this->set_mapping('peerreviewstudio_assignment', $oldid, $newitemid);
    }

    /**
     * Restore one review.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_review($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->assignmentid = $this->get_new_parentid('peerreviewstudio_assignment');
        $data->submissionid = $this->get_new_parentid('peerreviewstudio_submission');
        $data->reviewerid = $this->get_mappingid('user', $data->reviewerid, 0);
        $newitemid = $DB->insert_record('peerreviewstudio_reviews', $data);
        $this->set_mapping('peerreviewstudio_review', $oldid, $newitemid);
    }

    /**
     * Restore one rubric answer.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_answer($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->reviewid = $this->get_new_parentid('peerreviewstudio_review');
        $data->criterionid = $this->get_mappingid('peerreviewstudio_criterion', $data->criterionid, 0);
        $data->levelid = $this->get_mappingid('peerreviewstudio_level', $data->levelid, 0);
        $newitemid = $DB->insert_record('peerreviewstudio_r_answers', $data);
        $this->set_mapping('peerreviewstudio_answer', $oldid, $newitemid);
    }

    /**
     * Restore one queue item.
     *
     * @param array|object $data Backup data.
     * @return void
     */
    protected function process_peerreviewstudio_queueitem($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        $data->peerreviewstudioid = $this->task->get_activityid();
        $data->submissionid = $this->get_new_parentid('peerreviewstudio_submission');
        $newitemid = $DB->insert_record('peerreviewstudio_queue', $data);
        $this->set_mapping('peerreviewstudio_queueitem', $oldid, $newitemid);
    }

    /**
     * Restore related files once all records are mapped.
     *
     * @return void
     */
    protected function after_execute() {
        $this->add_related_files('mod_peerreviewstudio', 'intro', null);
        $this->add_related_files('mod_peerreviewstudio', 'submission_files', 'peerreviewstudio_submission');
    }
}
