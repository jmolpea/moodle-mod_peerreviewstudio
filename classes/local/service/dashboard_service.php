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
 * Builds the data shown on the tutor dashboard.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_service {
    /**
     * Rows shown per tutor dashboard page.
     */
    const PERPAGE = 25;

    /**
     * Build tutor dashboard payload.
     *
     * @param \stdClass $activity Activity record.
     * @param string $statusfilter Optional status filter.
     * @param string $search Optional search query.
     * @param int $page Zero-based page number.
     * @param int|null $groupid Group to restrict to; 0 for everybody, null for nobody.
     * @param bool $searchemail Whether the search may also match email addresses.
     * @return array
     */
    public static function tutor_dashboard(
        \stdClass $activity,
        string $statusfilter = '',
        string $search = '',
        int $page = 0,
        ?int $groupid = 0,
        bool $searchemail = false
    ): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $activityid = (int)$activity->id;
        $total = peerreviewstudio_count_tutor_submission_rows($activityid, $statusfilter, $search, $groupid, $searchemail);
        $lastpage = max(0, (int)ceil($total / self::PERPAGE) - 1);
        $page = min(max(0, $page), $lastpage);

        return [
            'rows' => peerreviewstudio_get_tutor_submission_rows(
                $activityid,
                $statusfilter,
                $search,
                $page,
                self::PERPAGE,
                [],
                $groupid,
                $searchemail
            ),
            'total' => $total,
            'page' => $page,
            'perpage' => self::PERPAGE,
            'incidents' => peerreviewstudio_get_tutor_incident_rows($activity, $groupid),
            'counts' => peerreviewstudio_get_dashboard_counts($activityid, $groupid),
        ];
    }
}
