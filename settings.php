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
 * Admin settings for mod_peerreviewstudio.
 *
 * AI review uses the site's AI providers, so there is nothing to configure
 * here: the page says whether AI review is available and links to the
 * places where it is set up and tested.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $available = \mod_peerreviewstudio\local\ai\client::instance()->is_available();
    $links = (object) [
        'providers' => (new moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(),
        'test' => (new moodle_url('/mod/peerreviewstudio/aitest.php'))->out(),
    ];

    $settings->add(new admin_setting_heading(
        'mod_peerreviewstudio/aiheading',
        get_string('setting_aiheading', 'mod_peerreviewstudio'),
        get_string('setting_aiheading_desc', 'mod_peerreviewstudio', $links)
            . html_writer::empty_tag('br') . html_writer::empty_tag('br')
            . get_string($available ? 'setting_aistatus_available' : 'setting_aistatus_unavailable', 'mod_peerreviewstudio', $links)
    ));
}
