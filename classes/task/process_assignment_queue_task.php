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

/**
 * Scheduled task that retries pending reviewer-assignment queue entries.
 *
 * Without this task, a submission stuck in the queue (for example the
 * last learner to submit) would only be retried when another learner
 * submits or a tutor requeues it manually.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_assignment_queue_task extends \core\task\scheduled_task {
    /**
     * Return the task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskprocessassignmentqueue', 'mod_peerreviewstudio');
    }

    /**
     * Process pending queue entries and review follow-ups for every
     * activity that has open work.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        // Activities with pending queue entries need assignment retries;
        // activities with open assignments need the follow-up cycle
        // (reminders, expiry/reassignment, escalation) as well.
        $activityids = $DB->get_fieldset_sql(
            "SELECT DISTINCT peerreviewstudioid
               FROM {peerreviewstudio_queue}
              WHERE status = 'pending'
              UNION
             SELECT DISTINCT peerreviewstudioid
               FROM {peerreviewstudio_assignments}
              WHERE status = 'assigned'"
        );

        foreach ($activityids as $activityid) {
            $activity = $DB->get_record('peerreviewstudio', ['id' => $activityid]);
            if (!$activity) {
                continue;
            }
            if (!empty($activity->autoassignenabled)) {
                \peerreviewstudio_process_assignment_queue($activity);
            }
            \peerreviewstudio_process_review_followups($activity);
        }
    }
}
