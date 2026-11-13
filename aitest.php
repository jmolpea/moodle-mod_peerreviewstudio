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
 * Lets a site administrator check that AI review works with the site's AI provider.
 *
 * One tiny request is sent through the Moodle AI subsystem, and the outcome
 * (or the provider's error) is shown.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_peerreviewstudio\local\ai\client;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$run = optional_param('run', 0, PARAM_BOOL);
$settingsurl = new moodle_url('/admin/settings.php', ['section' => 'modsettingpeerreviewstudio']);

$PAGE->set_context($context);
$PAGE->set_url('/mod/peerreviewstudio/aitest.php');
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('aitest', 'mod_peerreviewstudio'));
$PAGE->set_heading(get_string('aitest', 'mod_peerreviewstudio'));

$client = client::instance();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('aitest', 'mod_peerreviewstudio'));

if (!$client->is_available()) {
    echo $OUTPUT->notification(get_string('ainotconfigured', 'mod_peerreviewstudio'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->continue_button($settingsurl);
    echo $OUTPUT->footer();
    die();
}

echo html_writer::tag('p', get_string('aitest_desc', 'mod_peerreviewstudio'));

if ($run && confirm_sesskey()) {
    // The request can take a while with reasoning models.
    core_php_time_limit::raise(300);
    \core\session\manager::write_close();

    $result = $client->evaluate(
        'This is a connection test. Give the grade 100 and the feedback "Connection OK".',
        'Connection test.',
        (int)$context->id,
        (int)$USER->id
    );
    if ($result !== null) {
        echo $OUTPUT->notification(
            get_string('aitest_success', 'mod_peerreviewstudio', (object) [
                'grade' => format_float($result['grade'], 0),
                'feedback' => s($result['feedback']),
            ]),
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else {
        echo $OUTPUT->notification(
            get_string('aitest_failure', 'mod_peerreviewstudio', s($client->last_error())),
            \core\output\notification::NOTIFY_ERROR
        );
    }
}

echo $OUTPUT->single_button(
    new moodle_url('/mod/peerreviewstudio/aitest.php', ['run' => 1, 'sesskey' => sesskey()]),
    get_string('aitest_run', 'mod_peerreviewstudio'),
    'post'
);
echo $OUTPUT->single_button($settingsurl, get_string('back'), 'get');

echo $OUTPUT->footer();
