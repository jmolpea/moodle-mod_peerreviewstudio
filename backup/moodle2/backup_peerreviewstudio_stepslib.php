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
 * Backup structure step for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete peerreviewstudio structure for backup.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_peerreviewstudio_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup structure.
     *
     * @return backup_nested_element
     */
    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $peerreviewstudio = new backup_nested_element('peerreviewstudio', ['id'], [
            'name', 'intro', 'introformat', 'submissiontype', 'reviewcountrequired', 'reviewsreceivedtarget',
            'autoassignenabled', 'reviewpoolmode', 'avoidreciprocalreviews', 'reviewreminderdays', 'reviewreassigndays',
            'grademaxsubmission', 'grademaxreview',
            'timeopen', 'timeclose', 'timereviewdue', 'workflowmode', 'manualphase',
            'aireviewenabled', 'aifallbackenabled', 'aigradeweight', 'aipromptsubmission', 'aipromptreviewer',
            'completionsubmit', 'completionreviews', 'timecreated', 'timemodified',
        ]);
        $rubrics = new backup_nested_element('rubrics');
        $rubric = new backup_nested_element('rubric', ['id'], ['name', 'timecreated', 'timemodified']);
        $criteria = new backup_nested_element('criteria');
        $criterion = new backup_nested_element('criterion', ['id'], ['label', 'description', 'weight', 'sortorder']);
        $levels = new backup_nested_element('levels');
        $level = new backup_nested_element('level', ['id'], ['label', 'score', 'sortorder']);
        $submissions = new backup_nested_element('submissions');
        $submission = new backup_nested_element('submission', ['id'], [
            'userid', 'status', 'onlinetext', 'onlinetextformat', 'timesubmitted', 'timecreated', 'timemodified',
            'submissiongrade', 'reviewgrade', 'finalgrade', 'teachergradeoverride',
            'aigrade', 'aifeedback', 'aitimegraded', 'timeescalated', 'timegradenotified',
        ]);
        $assignments = new backup_nested_element('assignments');
        $assignment = new backup_nested_element('assignment', ['id'], [
            'reviewerid', 'status', 'timeassigned', 'timenotified', 'timereminded', 'timeopened', 'timecompleted',
            'timemodified',
        ]);
        $reviews = new backup_nested_element('reviews');
        $review = new backup_nested_element('review', ['id'], [
            'reviewerid', 'status', 'generalcomment', 'grade',
            'aireviewergrade', 'aireviewerfeedback', 'aitimegraded',
            'timesubmitted', 'timecreated', 'timemodified',
        ]);
        $answers = new backup_nested_element('answers');
        $answer = new backup_nested_element('answer', ['id'], ['criterionid', 'levelid', 'commenttext', 'score']);
        $queueitems = new backup_nested_element('queueitems');
        $queueitem = new backup_nested_element('queueitem', ['id'], [
            'queuetype', 'status', 'attempts', 'lastattemptat', 'timecreated', 'timemodified',
        ]);

        $peerreviewstudio->add_child($rubrics);
        $rubrics->add_child($rubric);
        $rubric->add_child($criteria);
        $criteria->add_child($criterion);
        $criterion->add_child($levels);
        $levels->add_child($level);
        $peerreviewstudio->add_child($submissions);
        $submissions->add_child($submission);
        $submission->add_child($assignments);
        $assignments->add_child($assignment);
        $assignment->add_child($reviews);
        $reviews->add_child($review);
        $review->add_child($answers);
        $answers->add_child($answer);
        $submission->add_child($queueitems);
        $queueitems->add_child($queueitem);

        $peerreviewstudio->set_source_table('peerreviewstudio', ['id' => backup::VAR_ACTIVITYID]);
        $rubric->set_source_table('peerreviewstudio_rubrics', ['peerreviewstudioid' => backup::VAR_PARENTID]);
        $criterion->set_source_table('peerreviewstudio_r_criteria', ['rubricid' => backup::VAR_PARENTID]);
        $level->set_source_table('peerreviewstudio_r_levels', ['criterionid' => backup::VAR_PARENTID]);

        // Learner-generated data is only included when user information is requested.
        if ($userinfo) {
            $submission->set_source_table('peerreviewstudio_submissions', ['peerreviewstudioid' => backup::VAR_ACTIVITYID]);
            $assignment->set_source_table('peerreviewstudio_assignments', ['submissionid' => backup::VAR_PARENTID]);
            $review->set_source_table('peerreviewstudio_reviews', ['assignmentid' => backup::VAR_PARENTID]);
            $answer->set_source_table('peerreviewstudio_r_answers', ['reviewid' => backup::VAR_PARENTID]);
            $queueitem->set_source_table('peerreviewstudio_queue', ['submissionid' => backup::VAR_PARENTID]);
        }

        $submission->annotate_ids('user', 'userid');
        $assignment->annotate_ids('user', 'reviewerid');
        $review->annotate_ids('user', 'reviewerid');

        $peerreviewstudio->annotate_files('mod_peerreviewstudio', 'intro', null);
        if ($userinfo) {
            $submission->annotate_files('mod_peerreviewstudio', 'submission_files', 'id');
        }

        return $this->prepare_activity_structure($peerreviewstudio);
    }
}
