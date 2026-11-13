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
 * Restore task for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/peerreviewstudio/backup/moodle2/restore_peerreviewstudio_stepslib.php');

/**
 * Provides the steps to perform one complete restore of the activity.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_peerreviewstudio_activity_task extends restore_activity_task {
    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Define the restore steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new restore_peerreviewstudio_activity_structure_step('peerreviewstudio_structure', 'peerreviewstudio.xml'));
    }

    /**
     * Define the contents in the activity that must be processed by the link decoder.
     *
     * @return restore_decode_content[]
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('peerreviewstudio', ['intro'], 'peerreviewstudio'),
        ];
    }

    /**
     * Define the decoding rules for links belonging to the activity.
     *
     * @return restore_decode_rule[]
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('PEERREVIEWSTUDIOVIEWBYID', '/mod/peerreviewstudio/view.php?id=$1', 'course_module'),
            new restore_decode_rule('PEERREVIEWSTUDIOINDEX', '/mod/peerreviewstudio/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Define the restore log rules for the activity.
     *
     * The plugin only writes to the standard log store, which core restores
     * without module-specific rules, so there is nothing to map here. The
     * method must still exist: the base class throws when it is missing and
     * a course is restored with its logs.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules() {
        return [];
    }

    /**
     * Define the restore log rules for course-level logs of the activity.
     *
     * @return restore_log_rule[]
     */
    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
