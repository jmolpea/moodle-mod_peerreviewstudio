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
 * Tutor view of one submission: content, reviews received, grades, and actions.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);
$submissionid = required_param('sid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'peerreviewstudio');
$activity = $DB->get_record('peerreviewstudio', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/peerreviewstudio:viewreport', $context);

$submission = $DB->get_record('peerreviewstudio_submissions', [
    'id' => $submissionid,
    'peerreviewstudioid' => $activity->id,
], '*', MUST_EXIST);
$author = core_user::get_user((int)$submission->userid);
if (!$author || !peerreviewstudio_can_access_submission_owner($cm, $context, (int)$submission->userid)) {
    // Covers submissions anonymised by a data deletion request and learners
    // in groups this tutor cannot see.
    throw new moodle_exception('submissionnotavailable', 'mod_peerreviewstudio');
}

$pageurl = new moodle_url('/mod/peerreviewstudio/submission.php', ['id' => $cm->id, 'sid' => $submission->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($activity->name) . ': ' . fullname($author));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(fullname($author));

if ($action !== '') {
    require_sesskey();

    switch ($action) {
        case 'requeue':
            require_capability('mod/peerreviewstudio:manageassignments', $context);
            peerreviewstudio_requeue_submission($activity, (int)$submission->id);
            redirect($pageurl, get_string('submissionrequeued', 'mod_peerreviewstudio'));
            break;

        case 'assign':
            require_capability('mod/peerreviewstudio:manageassignments', $context);
            $assigned = peerreviewstudio_assign_submission_to_reviewer(
                $activity,
                (int)$submission->id,
                required_param('reviewerid', PARAM_INT)
            );
            redirect(
                $pageurl,
                get_string($assigned ? 'submissionmanualassigned' : 'submissionmanualassignfailed', 'mod_peerreviewstudio'),
                null,
                $assigned ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
            );
            break;

        case 'override':
            require_capability('mod/peerreviewstudio:grade', $context);
            try {
                $override = peerreviewstudio_normalize_teacher_grade_override(
                    $activity,
                    optional_param('teachergradeoverride', '', PARAM_RAW_TRIMMED)
                );
            } catch (moodle_exception $e) {
                redirect($pageurl, $e->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
            }
            peerreviewstudio_set_teacher_grade_override($activity, (int)$submission->id, $override);
            redirect(
                $pageurl,
                get_string('teachergradeupdated', 'mod_peerreviewstudio'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
            break;

        default:
            throw new moodle_exception('invalidparameter', 'debug');
    }
}

$event = \mod_peerreviewstudio\event\submission_viewed::create([
    'objectid' => (int)$submission->id,
    'context' => $context,
    'relateduserid' => (int)$submission->userid,
]);
$event->trigger();

$assignments = peerreviewstudio_get_submission_assignments((int)$submission->id);
$reviewids = array_filter(array_map(static fn($assignment) => (int)$assignment->reviewid, $assignments));

/** @var \mod_peerreviewstudio\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_peerreviewstudio');

echo $OUTPUT->header();
echo $renderer->submission_detail(
    $cm,
    $context,
    $activity,
    $submission,
    $author,
    peerreviewstudio_get_submission_files($context, (int)$submission->id),
    $assignments,
    peerreviewstudio_get_rubric_answers_by_review($reviewids),
    peerreviewstudio_get_reviewer_candidates($activity, $submission)
);
echo $OUTPUT->footer();
