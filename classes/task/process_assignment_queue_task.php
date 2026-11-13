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
     * Run the reviewer matching, the review follow-ups, and the scheduled
     * release of feedback for every activity that has open work.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        // Activities with pending queue entries need matching; activities
        // with open assignments need the follow-up cycle (notifications,
        // reminders, expiry and reassignment, escalation); scheduled
        // activities whose review deadline passed need their feedback
        // released once.
        $activityids = $DB->get_fieldset_sql(
            "SELECT q.peerreviewstudioid
               FROM {peerreviewstudio_queue} q
              WHERE q.status = 'pending'
              UNION
             SELECT a.peerreviewstudioid
               FROM {peerreviewstudio_assignments} a
              WHERE a.status = 'assigned'
              UNION
             SELECT p.id
               FROM {peerreviewstudio} p
              WHERE p.workflowmode = 'scheduled'
                AND p.timereviewdue > 0
                AND p.timereviewdue < :now
                AND p.timegradesreleased = 0",
            ['now' => time()]
        );

        foreach ($activityids as $activityid) {
            $activity = $DB->get_record('peerreviewstudio', ['id' => $activityid]);
            // An activity being deleted may have lost its course module already.
            if (!$activity || !get_coursemodule_from_instance('peerreviewstudio', $activity->id, $activity->course)) {
                continue;
            }

            try {
                if (!empty($activity->autoassignenabled)) {
                    \peerreviewstudio_process_assignment_queue($activity);
                }
                \peerreviewstudio_process_review_followups($activity);

                if (\peerreviewstudio_review_window_over($activity) && (int)$activity->timegradesreleased === 0) {
                    \peerreviewstudio_release_feedback($activity);
                    $DB->set_field('peerreviewstudio', 'timegradesreleased', time(), ['id' => $activity->id]);
                }
            } catch (\Throwable $e) {
                // One broken activity must not stop the others.
                mtrace('Peer Review Studio activity ' . $activity->id . ' failed: ' . $e->getMessage());
            }
        }
    }
}
