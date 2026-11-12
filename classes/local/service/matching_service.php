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

namespace mod_peerreviewstudio\local\service;

/**
 * Matching orchestration service.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class matching_service {
    /**
     * Process pending queue for one activity.
     *
     * @param \stdClass $activity Activity record.
     * @return void
     */
    public static function process_queue(\stdClass $activity): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        \peerreviewstudio_process_assignment_queue($activity);
    }

    /**
     * Return eligible reviewers.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass $submission Submission record.
     * @return array
     */
    public static function eligible_reviewers(\stdClass $activity, \stdClass $submission): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        return \peerreviewstudio_get_eligible_reviewers_for_submission($activity, $submission);
    }
}
