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
 * List all Peer Review Studio instances in one course.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$id = required_param('id', PARAM_INT);
$course = $DB->get_record('course', ['id' => $id], '*', MUST_EXIST);
require_course_login($course);

$event = \core\event\course_module_instance_list_viewed::create([
    'context' => context_course::instance($course->id),
]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/peerreviewstudio/index.php', ['id' => $id]);
$PAGE->set_title(format_string($course->fullname) . ': ' . get_string('modulenameplural', 'mod_peerreviewstudio'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_peerreviewstudio'));

$instances = get_all_instances_in_course('peerreviewstudio', $course);
if (!$instances) {
    notice(get_string('noinstances', 'mod_peerreviewstudio'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$usesections = course_format_uses_sections($course->format);
$table = new html_table();
$table->attributes['class'] = 'generaltable mod_index';
if ($usesections) {
    $table->head = [get_string('sectionname', 'format_' . $course->format), get_string('name')];
    $table->align = ['left', 'left'];
} else {
    $table->head = [get_string('name')];
    $table->align = ['left'];
}

foreach ($instances as $instance) {
    $attributes = [];
    if (!$instance->visible) {
        $attributes['class'] = 'dimmed';
    }
    $link = html_writer::link(
        new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $instance->coursemodule]),
        format_string($instance->name, true),
        $attributes
    );
    if ($usesections) {
        $table->data[] = [get_section_name($course, $instance->section), $link];
    } else {
        $table->data[] = [$link];
    }
}

echo html_writer::table($table);
echo $OUTPUT->footer();
