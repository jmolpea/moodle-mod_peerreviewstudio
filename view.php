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
 * Main activity page for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once(__DIR__ . '/classes/local/form/submission_form.php');
require_once(__DIR__ . '/classes/local/form/review_form.php');

use mod_peerreviewstudio\local\form\review_form;
use mod_peerreviewstudio\local\form\submission_form;
use mod_peerreviewstudio\local\service\dashboard_service;

$id = optional_param('id', 0, PARAM_INT);
$n = optional_param('n', 0, PARAM_INT);

if ($id) {
    $cm = get_coursemodule_from_id('peerreviewstudio', $id, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $peerreviewstudio = $DB->get_record('peerreviewstudio', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($n) {
    $peerreviewstudio = $DB->get_record('peerreviewstudio', ['id' => $n], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $peerreviewstudio->course], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('peerreviewstudio', $peerreviewstudio->id, $course->id, false, MUST_EXIST);
} else {
    throw new moodle_exception('missingparameter');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/peerreviewstudio:view', $context);

$PAGE->set_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($peerreviewstudio->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$viewevent = \mod_peerreviewstudio\event\course_module_viewed::create([
    'objectid' => (int)$peerreviewstudio->id,
    'context' => $context,
]);
$viewevent->add_record_snapshot('course', $course);
$viewevent->add_record_snapshot('peerreviewstudio', $peerreviewstudio);
$viewevent->trigger();

$canmanage = has_capability('mod/peerreviewstudio:viewreport', $context);
$cansubmit = has_capability('mod/peerreviewstudio:submit', $context);
$canviewownstatus = has_capability('mod/peerreviewstudio:viewownstatus', $context);
$canmanageassignments = has_capability('mod/peerreviewstudio:manageassignments', $context);
$statusfilter = optional_param('statusfilter', '', PARAM_ALPHANUMEXT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$dashpage = optional_param('dashpage', 0, PARAM_INT);
$requeuesubmissionid = optional_param('requeuesubmission', 0, PARAM_INT);
$manualassignsubmissionid = optional_param('manualassignsubmission', 0, PARAM_INT);
$manualreviewerid = optional_param('manualreviewerid', 0, PARAM_INT);
$gradeoverridesubmissionid = optional_param('gradeoverridesubmission', 0, PARAM_INT);
$teachergradeoverride = optional_param('teachergradeoverride', '', PARAM_RAW_TRIMMED);
$currentphase = peerreviewstudio_get_current_phase($peerreviewstudio);

$submission = $canviewownstatus ? peerreviewstudio_get_user_submission((int)$peerreviewstudio->id, (int)$USER->id) : false;
$userassignments = [];
$receivedreviews = [];
if ($submission) {
    $userassignments = peerreviewstudio_get_user_assignments((int)$peerreviewstudio->id, (int)$USER->id);
    $receivedreviews = peerreviewstudio_get_received_reviews((int)$submission->id);
}
$openuserassignments = array_filter($userassignments, static fn($assignment) => (string)$assignment->status === 'assigned');
$receivedreviewanswers = peerreviewstudio_get_rubric_answers_by_review(
    array_map(static fn($review) => (int)$review->id, $receivedreviews)
);

$submissionform = null;
$reviewform = null;
$activeassignment = null;
$submissionfiles = $submission ? peerreviewstudio_get_submission_files($context, (int)$submission->id) : [];
if ($cansubmit && (!$submission || peerreviewstudio_phase_allows_submissions($peerreviewstudio))) {
    if (peerreviewstudio_phase_allows_submissions($peerreviewstudio) && peerreviewstudio_submissions_open($peerreviewstudio)) {
        $submissionform = new submission_form(null, [
            'cmid' => $cm->id,
            'context' => $context,
            'submissiontype' => $peerreviewstudio->submissiontype,
            'maxbytes' => $course->maxbytes,
            'editing' => (bool)$submission,
        ]);
        $submissionform->set_data(peerreviewstudio_prepare_submission_form_data($context, $submission));
        if ($submissionform->is_cancelled()) {
            redirect(new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]));
        }
        if ($data = $submissionform->get_data()) {
            $submission = peerreviewstudio_save_submission(
                $peerreviewstudio,
                (int)$USER->id,
                $data->submissiontext_editor ?? ['text' => '', 'format' => FORMAT_HTML],
                (int)($data->submissionfiles_filemanager ?? 0),
                $context,
            );
            $submissionfiles = peerreviewstudio_get_submission_files($context, (int)$submission->id);
            redirect(
                new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
                get_string('submissionreceived', 'mod_peerreviewstudio'),
            );
        }
    }
}

$reviewtargetsubmission = null;
$reviewtargetfiles = [];
if ($submission && has_capability('mod/peerreviewstudio:review', $context) && !empty($userassignments)
        && peerreviewstudio_phase_allows_reviews($peerreviewstudio)) {
    // Prefer the assignment the submitted review form refers to, so a
    // review is never stored against a different assignment than the one
    // the reviewer was actually looking at.
    $requestedassignmentid = optional_param('assignmentid', 0, PARAM_INT);
    foreach ($userassignments as $assignmentcandidate) {
        if ((string)$assignmentcandidate->status !== 'assigned') {
            continue;
        }
        if ($requestedassignmentid && (int)$assignmentcandidate->id === $requestedassignmentid) {
            $activeassignment = $assignmentcandidate;
            break;
        }
        if (!$activeassignment) {
            $activeassignment = $assignmentcandidate;
            if (!$requestedassignmentid) {
                break;
            }
        }
    }
    if ($requestedassignmentid && $activeassignment && (int)$activeassignment->id !== $requestedassignmentid) {
        // The referenced assignment is no longer open; do not process the stale form.
        $activeassignment = null;
    }
    if ($activeassignment) {
        $reviewtargetsubmission = $DB->get_record('peerreviewstudio_submissions', [
            'id' => $activeassignment->submissionid,
        ], '*', MUST_EXIST);
        $reviewtargetfiles = peerreviewstudio_get_submission_files($context, (int)$reviewtargetsubmission->id);
        $rubric = peerreviewstudio_get_activity_rubric((int)$peerreviewstudio->id);
        $reviewform = new review_form(null, [
            'cmid' => $cm->id,
            'assignmentid' => $activeassignment->id,
            'rubric' => $rubric,
        ]);
        if ($reviewform->is_cancelled()) {
            redirect(new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]));
        }
        if ($reviewdata = $reviewform->get_data()) {
            $rubricresult = peerreviewstudio_calculate_rubric_result(
                $rubric,
                (array)$reviewdata,
                (float)($peerreviewstudio->grademaxsubmission ?? 0),
            );
            peerreviewstudio_save_review(
                $peerreviewstudio,
                $activeassignment,
                (int)$USER->id,
                (string)($reviewdata->generalcomment ?? ''),
                (float)$rubricresult['grade'],
                $rubricresult['answers'],
            );
            redirect(
                new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
                get_string('reviewsubmitted', 'mod_peerreviewstudio'),
            );
        }
    }
}

$processqueue = optional_param('processqueue', 0, PARAM_INT);
if ($processqueue && confirm_sesskey() && $canmanageassignments) {
    // Manual lever for stuck queue entries: re-run the assignment queue
    // right now instead of waiting for the scheduled task.
    peerreviewstudio_process_assignment_queue($peerreviewstudio);
    redirect(
        new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
        get_string('queueprocessed', 'mod_peerreviewstudio'),
    );
}

if ($requeuesubmissionid && confirm_sesskey() && $canmanageassignments) {
    peerreviewstudio_requeue_submission($peerreviewstudio, $requeuesubmissionid);
    redirect(
        new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
        get_string('submissionrequeued', 'mod_peerreviewstudio'),
    );
}

if ($manualassignsubmissionid && $manualreviewerid && confirm_sesskey() && $canmanageassignments) {
    $assigned = peerreviewstudio_assign_submission_to_reviewer($peerreviewstudio, $manualassignsubmissionid, $manualreviewerid);
    redirect(
        new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
        get_string($assigned ? 'submissionmanualassigned' : 'submissionmanualassignfailed', 'mod_peerreviewstudio'),
    );
}

if ($gradeoverridesubmissionid && confirm_sesskey() && has_capability('mod/peerreviewstudio:grade', $context)) {
    $override = peerreviewstudio_normalize_teacher_grade_override($peerreviewstudio, (string)$teachergradeoverride);
    peerreviewstudio_set_teacher_grade_override($peerreviewstudio, $gradeoverridesubmissionid, $override);
    redirect(
        new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
        get_string('teachergradeupdated', 'mod_peerreviewstudio'),
    );
}

$status = get_string('status_notsubmitted', 'mod_peerreviewstudio');
$nextstep = get_string('nextstep_submit', 'mod_peerreviewstudio');
if (!peerreviewstudio_phase_allows_submissions($peerreviewstudio) && $currentphase === 'review' && !$submission) {
    $nextstep = get_string('nextstep_waitforreviewphase', 'mod_peerreviewstudio');
} else if ($currentphase === 'feedback') {
    $nextstep = get_string('nextstep_viewfeedback', 'mod_peerreviewstudio');
} else if ($currentphase === 'closed') {
    $nextstep = get_string('nextstep_activityclosed', 'mod_peerreviewstudio');
}
if ($submission) {
    $statuskey = 'status_' . clean_param((string)$submission->status, PARAM_ALPHANUMEXT);
    if (get_string_manager()->string_exists($statuskey, 'mod_peerreviewstudio')) {
        $status = get_string($statuskey, 'mod_peerreviewstudio');
    } else {
        $status = s((string)$submission->status);
    }
    $nextstep = match ((string)$submission->status) {
        'queued' => get_string('nextstep_waitforpeer', 'mod_peerreviewstudio'),
        'assigned', 'partiallyreviewed' => get_string('nextstep_reviewassigned', 'mod_peerreviewstudio'),
        'reviewed', 'closed' => get_string('nextstep_viewfeedback', 'mod_peerreviewstudio'),
        default => match ($currentphase) {
            'submission' => get_string('nextstep_submit', 'mod_peerreviewstudio'),
            'review', 'allocation' => get_string('nextstep_waitforpeer', 'mod_peerreviewstudio'),
            'feedback' => get_string('nextstep_viewfeedback', 'mod_peerreviewstudio'),
            'closed' => get_string('nextstep_activityclosed', 'mod_peerreviewstudio'),
            default => get_string('nextstep_waitforpeer', 'mod_peerreviewstudio'),
        },
    };
}

echo $OUTPUT->header();
// The activity header (Moodle 4+) already prints the name and the description:
// printing them again here showed the whole brief twice to students.
echo html_writer::start_div('peerreviewstudio-shell');

if (!empty($peerreviewstudio->aireviewenabled) && \mod_peerreviewstudio\local\ai\client::is_configured()
        && ($cansubmit || has_capability('mod/peerreviewstudio:review', $context))) {
    // Transparency notice required for the optional AI second review:
    // participants must know their work is evaluated by an external AI
    // service before they submit anything.
    echo $OUTPUT->notification(
        get_string('aiprivacynotice', 'mod_peerreviewstudio'),
        \core\output\notification::NOTIFY_INFO,
        false
    );
}

echo html_writer::start_div('peerreviewstudio-hero');
echo html_writer::start_div('peerreviewstudio-hero-main');
echo html_writer::tag(
    'div',
    get_string('currentphaselabel', 'mod_peerreviewstudio', peerreviewstudio_phase_label($currentphase)),
    ['class' => 'peerreviewstudio-chip peerreviewstudio-chip-phase']
);
echo html_writer::tag('h2', format_string($peerreviewstudio->name), ['class' => 'peerreviewstudio-title']);
echo html_writer::tag(
    'p',
    get_string('nextsteplabel', 'mod_peerreviewstudio', $nextstep),
    ['class' => 'peerreviewstudio-subtitle']
);
echo html_writer::end_div();
echo html_writer::start_div('peerreviewstudio-hero-stats');
$herostats = [
    get_string('currentstatuslabel', 'mod_peerreviewstudio', $status),
    get_string('assignedreviewscount', 'mod_peerreviewstudio', count($openuserassignments)),
    get_string('dashboardreceivedprogressvalue', 'mod_peerreviewstudio', (object) [
        'done' => count($receivedreviews),
        'target' => (int)$peerreviewstudio->reviewsreceivedtarget,
    ]),
];
foreach ($herostats as $herostat) {
    echo html_writer::tag(
        'div',
        html_writer::tag('span', $herostat, ['class' => 'peerreviewstudio-stat-label']),
        ['class' => 'peerreviewstudio-stat']
    );
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('peerreviewstudio-phasebar', [
    'role' => 'list',
    'aria-label' => get_string('phasebarlabel', 'mod_peerreviewstudio'),
]);
foreach (peerreviewstudio_visible_workflow_phases($peerreviewstudio) as $phasekey => $phaselabel) {
    $classes = ['peerreviewstudio-phasebar-item'];
    $attributes = ['role' => 'listitem'];
    if ($phasekey === $currentphase) {
        $classes[] = 'is-active';
        $attributes['aria-current'] = 'step';
    }
    $attributes['class'] = implode(' ', $classes);
    echo html_writer::tag('span', s($phaselabel), $attributes);
}
echo html_writer::end_div();

echo html_writer::start_div('peerreviewstudio-grid');
echo html_writer::start_div('peerreviewstudio-main');
if ($canviewownstatus || $cansubmit || has_capability('mod/peerreviewstudio:review', $context)) {
    echo $PAGE->get_renderer('mod_peerreviewstudio')->learner_workspace(
        $peerreviewstudio,
        $context,
        $cansubmit,
        has_capability('mod/peerreviewstudio:review', $context),
        $submission,
        $submissionfiles,
        $userassignments,
        $submissionform,
        $reviewform,
        $activeassignment,
        $reviewtargetsubmission,
        $reviewtargetfiles,
    );

    echo $PAGE->get_renderer('mod_peerreviewstudio')->received_feedback(
        $receivedreviews,
        $receivedreviewanswers,
        peerreviewstudio_phase_allows_feedback($peerreviewstudio, $submission ?: null),
    );

    echo $PAGE->get_renderer('mod_peerreviewstudio')->ai_feedback(
        $peerreviewstudio,
        $submission,
        peerreviewstudio_phase_allows_feedback($peerreviewstudio, $submission ?: null),
    );
}
echo html_writer::end_div();

if ($canmanage) {
    $dashboard = dashboard_service::tutor_dashboard((int)$peerreviewstudio->id, $statusfilter, $search, $dashpage);
    echo $PAGE->get_renderer('mod_peerreviewstudio')->tutor_dashboard(
        $cm,
        $context,
        $peerreviewstudio,
        $dashboard,
        $statusfilter,
        $search,
        $canmanageassignments,
    );
}

echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
