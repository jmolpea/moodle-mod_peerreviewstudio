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
 * Learners submit their work, review the peers assigned to them, and read
 * the feedback they received. Tutors get the operational dashboard.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use mod_peerreviewstudio\local\ai\client;
use mod_peerreviewstudio\local\form\review_form;
use mod_peerreviewstudio\local\form\submission_form;
use mod_peerreviewstudio\local\service\dashboard_service;

$id = optional_param('id', 0, PARAM_INT);
$n = optional_param('n', 0, PARAM_INT);

if ($id) {
    [$course, $cm] = get_course_and_cm_from_cmid($id, 'peerreviewstudio');
    $activity = $DB->get_record('peerreviewstudio', ['id' => $cm->instance], '*', MUST_EXIST);
} else {
    $activity = $DB->get_record('peerreviewstudio', ['id' => $n], '*', MUST_EXIST);
    [$course, $cm] = get_course_and_cm_from_instance($activity->id, 'peerreviewstudio');
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/peerreviewstudio:view', $context);

$pageurl = new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]);
$PAGE->set_url($pageurl);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));

$canviewreport = has_capability('mod/peerreviewstudio:viewreport', $context);
$canmanageassignments = has_capability('mod/peerreviewstudio:manageassignments', $context);
// Site administrators hold every capability, but the learner area is only
// for people who were actually given the learner capabilities.
$cansubmit = has_capability('mod/peerreviewstudio:submit', $context, null, false);
$canreview = has_capability('mod/peerreviewstudio:review', $context, null, false);
$islearner = $cansubmit || $canreview || has_capability('mod/peerreviewstudio:viewownstatus', $context, null, false);

// Tutor action: run the reviewer matching now instead of waiting for cron.
if (optional_param('processqueue', 0, PARAM_BOOL) && $canmanageassignments) {
    require_sesskey();
    $created = peerreviewstudio_process_assignment_queue($activity);
    redirect($pageurl, get_string('queueprocessed', 'mod_peerreviewstudio', $created));
}

// AI review: content written by a learner is only sent to the AI once they
// have accepted the site AI policy.
$aiclient = client::instance();
$aiactive = ($cansubmit || $canreview) && $aiclient->is_enabled_for($activity);
$aipolicyaccepted = $aiactive && $aiclient->has_accepted_policy((int)$USER->id);
if ($aiactive && !$aipolicyaccepted && optional_param('acceptaipolicy', 0, PARAM_BOOL)) {
    require_sesskey();
    $aiclient->accept_policy((int)$USER->id, (int)$context->id);
    peerreviewstudio_queue_pending_ai_reviews($activity, (int)$USER->id);
    redirect(
        $pageurl,
        get_string('aipolicyaccepted', 'mod_peerreviewstudio'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
if ($aipolicyaccepted) {
    // Pick up evaluations that were waiting for the policy to be accepted.
    peerreviewstudio_queue_pending_ai_reviews($activity, (int)$USER->id);
}

// Learner state.
$submission = $islearner ? peerreviewstudio_get_user_submission((int)$activity->id, (int)$USER->id) : false;
$assignments = ($submission && $canreview) ? peerreviewstudio_get_user_assignments((int)$activity->id, (int)$USER->id) : [];
$openassignments = array_filter($assignments, static fn($assignment) => (string)$assignment->status === 'assigned');
$completedreviews = $submission ? peerreviewstudio_count_completed_reviews((int)$activity->id, (int)$USER->id) : 0;

// Submission form.
$submissionform = null;
if ($cansubmit && peerreviewstudio_submission_is_editable($activity, $submission)) {
    $submissionform = new submission_form($pageurl, [
        'context' => $context,
        'submissiontype' => $activity->submissiontype,
        'maxbytes' => $course->maxbytes,
        'editing' => (bool)$submission,
    ]);
    $submissionform->set_data(peerreviewstudio_prepare_submission_form_data($context, $submission));
    if ($data = $submissionform->get_data()) {
        peerreviewstudio_save_submission(
            $activity,
            (int)$USER->id,
            $data->submissiontext_editor ?? ['text' => '', 'format' => FORMAT_HTML],
            (int)($data->submissionfiles_filemanager ?? 0),
            $context
        );
        redirect(
            $pageurl,
            get_string('submissionreceived', 'mod_peerreviewstudio'),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

// Review form for the assignment the reviewer is working on.
$reviewform = null;
$activeassignment = null;
$reviewtarget = null;
if ($openassignments && peerreviewstudio_phase_allows_reviews($activity)) {
    // Prefer the assignment the request refers to, so a review is never
    // stored against a different assignment than the one being looked at.
    $requestedid = optional_param('assignmentid', 0, PARAM_INT);
    if ($requestedid) {
        $activeassignment = $openassignments[$requestedid] ?? null;
    } else {
        $activeassignment = reset($openassignments);
    }

    if ($activeassignment) {
        $reviewtarget = $DB->get_record(
            'peerreviewstudio_submissions',
            ['id' => $activeassignment->submissionid, 'peerreviewstudioid' => $activity->id],
            '*',
            MUST_EXIST
        );
        $rubric = peerreviewstudio_get_activity_rubric((int)$activity->id);
        $reviewform = new review_form($pageurl, [
            'assignmentid' => $activeassignment->id,
            'rubric' => $rubric,
        ]);
        if ($reviewdata = $reviewform->get_data()) {
            $rubricresult = peerreviewstudio_calculate_rubric_result(
                $rubric,
                (array)$reviewdata,
                (float)$activity->grademaxsubmission
            );
            peerreviewstudio_save_review(
                $activity,
                $activeassignment,
                (int)$USER->id,
                (string)$reviewdata->generalcomment,
                (float)$rubricresult['grade'],
                $rubricresult['answers']
            );
            redirect(
                $pageurl,
                get_string('reviewsubmitted', 'mod_peerreviewstudio'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
        peerreviewstudio_mark_assignment_opened($activeassignment);
    }
}

// The page is being shown: record the view.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);
$viewevent = \mod_peerreviewstudio\event\course_module_viewed::create([
    'objectid' => (int)$activity->id,
    'context' => $context,
]);
$viewevent->add_record_snapshot('course', $course);
$viewevent->add_record_snapshot('peerreviewstudio', $activity);
$viewevent->trigger();

// Feedback is only loaded once it has been released to the author.
$feedbackvisible = $submission && peerreviewstudio_phase_allows_feedback($activity, $submission);
$receivedreviews = $feedbackvisible ? peerreviewstudio_get_received_reviews((int)$submission->id) : [];
$receivedanswers = peerreviewstudio_get_rubric_answers_by_review(array_map('intval', array_keys($receivedreviews)));

/** @var \mod_peerreviewstudio\output\renderer $renderer */
$renderer = $PAGE->get_renderer('mod_peerreviewstudio');

echo $OUTPUT->header();
echo html_writer::start_div('peerreviewstudio-shell');

if ($aiactive) {
    // Transparency notice for the optional AI second review: participants
    // must know an AI service evaluates their work before they submit
    // anything, and decide whether they accept the site AI policy.
    echo $renderer->ai_notice($aipolicyaccepted, $pageurl);
}

$nextstep = '';
if ($islearner) {
    $nextstep = peerreviewstudio_learner_next_step(
        $activity,
        $submission,
        count($openassignments),
        $completedreviews,
        $cansubmit
    );
}
echo $renderer->activity_summary(
    $activity,
    $nextstep,
    $islearner ? [
        'submission' => $submission,
        'completedreviews' => $completedreviews,
        'openreviews' => count($openassignments),
    ] : null
);

if ($islearner) {
    echo $renderer->learner_submission(
        $activity,
        $submission,
        $submission ? peerreviewstudio_get_submission_files($context, (int)$submission->id) : [],
        $submissionform,
        $cansubmit,
        $context
    );

    if ($submission && $canreview) {
        echo $renderer->learner_reviews(
            $activity,
            $assignments,
            $activeassignment,
            $reviewtarget,
            $reviewtarget ? peerreviewstudio_get_submission_files($context, (int)$reviewtarget->id) : [],
            $reviewform,
            $pageurl,
            $context
        );
    }

    if ($submission) {
        echo $renderer->learner_feedback($activity, $submission, $feedbackvisible, $receivedreviews, $receivedanswers);
    }
}

if ($canviewreport) {
    // In separate groups mode a tutor only sees the groups they belong to.
    $groupid = 0;
    $groupmode = groups_get_activity_groupmode($cm);
    if ($groupmode != NOGROUPS) {
        $groupid = (int)groups_get_activity_group($cm, true);
        if (!$groupid && $groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
            $groupid = null;
        }
    }
    $showemail = in_array('email', \core_user\fields::get_identity_fields($context, false), true);
    $statusfilter = optional_param('statusfilter', '', PARAM_ALPHA);
    if (!in_array($statusfilter, peerreviewstudio_submission_statuses(), true)) {
        $statusfilter = '';
    }
    $search = trim(optional_param('search', '', PARAM_TEXT));

    $dashboard = dashboard_service::tutor_dashboard(
        $activity,
        $statusfilter,
        $search,
        optional_param('dashpage', 0, PARAM_INT),
        $groupid,
        $showemail
    );
    echo $renderer->tutor_dashboard(
        $cm,
        $activity,
        $dashboard,
        $statusfilter,
        $search,
        $canmanageassignments,
        $showemail,
        $groupmode != NOGROUPS ? groups_print_activity_menu($cm, $pageurl, true) : ''
    );
}

echo html_writer::end_div();
echo $OUTPUT->footer();
