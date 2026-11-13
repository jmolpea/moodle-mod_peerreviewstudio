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

declare(strict_types=1);

namespace mod_peerreviewstudio;

use core\activity_dates;

/**
 * Activity dates shown with the activity on the course and activity pages.
 *
 * Only scheduled mode is driven by dates; the other workflow modes store
 * none in the course module cache, so nothing is shown for them.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dates extends activity_dates {
    /**
     * Returns the dates of this activity.
     *
     * @return array
     */
    protected function get_dates(): array {
        $now = time();
        $dates = [];

        $timeopen = (int)($this->cm->customdata['timeopen'] ?? 0);
        if ($timeopen) {
            $dates[] = [
                'dataid' => 'timeopen',
                'label' => get_string($timeopen > $now ? 'activitydate:opens' : 'activitydate:opened', 'course'),
                'timestamp' => $timeopen,
            ];
        }

        $timeclose = (int)($this->cm->customdata['timeclose'] ?? 0);
        if ($timeclose) {
            $dates[] = [
                'dataid' => 'timeclose',
                'label' => get_string(
                    $timeclose > $now ? 'activitydate:submissionscloses' : 'activitydate:submissionsclosed',
                    'mod_peerreviewstudio'
                ),
                'timestamp' => $timeclose,
            ];
        }

        $timereviewdue = (int)($this->cm->customdata['timereviewdue'] ?? 0);
        if ($timereviewdue) {
            $dates[] = [
                'dataid' => 'timereviewdue',
                'label' => get_string('activitydate:reviewsdue', 'mod_peerreviewstudio'),
                'timestamp' => $timereviewdue,
            ];
        }

        return $dates;
    }
}
