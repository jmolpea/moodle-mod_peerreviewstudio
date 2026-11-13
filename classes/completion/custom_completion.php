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

namespace mod_peerreviewstudio\completion;

use core_completion\activity_custom_completion;

/**
 * Custom completion rules for mod_peerreviewstudio.
 *
 * Two rules are available: the learner must submit their work, and the
 * learner must complete the number of peer reviews the activity requires.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {
    /**
     * Fetch the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $this->validate_rule($rule);

        if ($rule === 'completionsubmit') {
            $done = $DB->record_exists('peerreviewstudio_submissions', [
                'peerreviewstudioid' => $this->cm->instance,
                'userid' => $this->userid,
            ]);
        } else {
            $required = (int)($this->cm->customdata['customcompletionrules']['completionreviews'] ?? 0);
            $done = \peerreviewstudio_count_completed_reviews((int)$this->cm->instance, (int)$this->userid) >= max(1, $required);
        }

        return $done ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionsubmit', 'completionreviews'];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return string[]
     */
    public function get_custom_rule_descriptions(): array {
        $required = (int)($this->cm->customdata['customcompletionrules']['completionreviews'] ?? 0);

        return [
            'completionsubmit' => get_string('completiondetail:submit', 'mod_peerreviewstudio'),
            'completionreviews' => get_string('completiondetail:reviews', 'mod_peerreviewstudio', max(1, $required)),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionsubmit',
            'completionreviews',
            'completionusegrade',
            'completionpassgrade',
        ];
    }
}
