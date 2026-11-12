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
 * Backup task for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/peerreviewstudio/backup/moodle2/backup_peerreviewstudio_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the activity.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_peerreviewstudio_activity_task extends backup_activity_task {
    /**
     * No specific settings for this activity.
     *
     * @return void
     */
    protected function define_my_settings() {
    }

    /**
     * Define the backup steps.
     *
     * @return void
     */
    protected function define_my_steps() {
        $this->add_step(new backup_peerreviewstudio_activity_structure_step('peerreviewstudio_structure', 'peerreviewstudio.xml'));
    }

    /**
     * Encode links to module view pages so they survive restore.
     *
     * @param string $content Content to encode.
     * @return string
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search = '/(' . $base . '\/mod\/peerreviewstudio\/index\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@PEERREVIEWSTUDIOINDEX*$2@$', $content);

        $search = '/(' . $base . '\/mod\/peerreviewstudio\/view\.php\?id\=)([0-9]+)/';
        $content = preg_replace($search, '$@PEERREVIEWSTUDIOVIEWBYID*$2@$', $content);

        return $content;
    }
}
