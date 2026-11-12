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
 * Small dashboard read service for peerreviewstudio.
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
     * @param int $activityid Activity id.
     * @param string $statusfilter Optional status filter.
     * @param string $search Optional search query.
     * @param int $page Zero-based page number.
     * @return array
     */
    public static function tutor_dashboard(int $activityid, string $statusfilter = '', string $search = '', int $page = 0): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $activity = $DB->get_record('peerreviewstudio', ['id' => $activityid], '*', MUST_EXIST);
        $total = peerreviewstudio_count_tutor_submission_rows($activityid, $statusfilter, $search);
        $lastpage = max(0, (int)ceil($total / self::PERPAGE) - 1);
        $page = min(max(0, $page), $lastpage);
        $filteredrows = peerreviewstudio_get_tutor_submission_rows(
            $activityid, $statusfilter, $search, $page, self::PERPAGE
        );
        return [
            'activity' => $activity,
            'filteredrows' => $filteredrows,
            'total' => $total,
            'page' => $page,
            'perpage' => self::PERPAGE,
            'incidents' => peerreviewstudio_get_tutor_incident_rows($activityid),
            'counts' => [
                'submitted' => $DB->count_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $activityid]),
                'queued' => $DB->count_records('peerreviewstudio_submissions', [
                    'peerreviewstudioid' => $activityid,
                    'status' => 'queued',
                ]),
                'assignments' => $DB->count_records('peerreviewstudio_assignments', ['peerreviewstudioid' => $activityid]),
                'completed' => $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {peerreviewstudio_reviews} r
                       JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
                      WHERE a.peerreviewstudioid = :activityid
                        AND r.status = 'submitted'",
                    ['activityid' => $activityid]
                ),
                'pendingqueue' => $DB->count_records('peerreviewstudio_queue', [
                    'peerreviewstudioid' => $activityid,
                    'status' => 'pending',
                ]),
            ],
        ];
    }

    /**
     * Filter submission rows for dashboard display.
     *
     * @param array $rows Raw rows.
     * @param string $statusfilter Status filter.
     * @param string $search Search query.
     * @return array
     */
    public static function filter_rows(array $rows, string $statusfilter = '', string $search = ''): array {
        $filteredrows = [];
        foreach ($rows as $row) {
            if ($statusfilter !== '' && (string)$row->status !== $statusfilter) {
                continue;
            }
            if ($search !== '') {
                $needle = \core_text::strtolower($search);
                $fullname = \core_text::strtolower(trim($row->firstname . ' ' . $row->lastname));
                $email = \core_text::strtolower((string)$row->email);
                if (!str_contains($fullname, $needle) && !str_contains($email, $needle)) {
                    continue;
                }
            }
            $filteredrows[] = $row;
        }

        return $filteredrows;
    }
}
