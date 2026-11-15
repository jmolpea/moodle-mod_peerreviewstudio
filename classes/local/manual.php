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

namespace mod_peerreviewstudio\local;

use html_writer;

/**
 * Link to the online configuration manual of the plugin.
 *
 * The address of the manual lives here and nowhere else, so it can be
 * changed in one place.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manual {
    /**
     * Address of the configuration manual.
     */
    const URL = 'https://claude.ai/artifact/RzMxCe9u5hXWWZy9kPUcXq';

    /**
     * Link to the manual (it opens in a new tab) followed by a short description.
     *
     * @return string HTML.
     */
    public static function link(): string {
        $link = html_writer::link(
            self::URL,
            get_string('manuallink', 'mod_peerreviewstudio'),
            ['target' => '_blank', 'rel' => 'noopener']
        );

        return $link . html_writer::empty_tag('br') . get_string('manuallink_desc', 'mod_peerreviewstudio');
    }
}
