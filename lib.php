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
 * Library functions for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/locallib.php');

/**
 * Calendar event type: submissions close.
 */
const PEERREVIEWSTUDIO_EVENT_TYPE_SUBMISSION = 'submissiondue';

/**
 * Calendar event type: reviews are due.
 */
const PEERREVIEWSTUDIO_EVENT_TYPE_REVIEW = 'reviewdue';

/**
 * Return the list of Moodle features supported by the module.
 *
 * @param string $feature Feature constant.
 * @return string|bool|null
 */
function peerreviewstudio_supports(string $feature): string|bool|null {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ASSESSMENT;
        default:
            return null;
    }
}

/**
 * Serve submission files.
 *
 * Only the author, the reviewers matched to the submission, and tutors who
 * may see the author can download a submission file.
 *
 * @param stdClass $course Course.
 * @param stdClass|cm_info $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args File args.
 * @param bool $forcedownload Force download.
 * @param array $options Additional options.
 * @return bool False when access is denied; otherwise the file is sent and execution ends.
 */
function peerreviewstudio_pluginfile(
    $course,
    $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = [],
): bool {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== 'submission_files') {
        return false;
    }

    require_login($course, false, $cm);
    if (!has_capability('mod/peerreviewstudio:view', $context) || count($args) < 2) {
        return false;
    }

    $submissionid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $cm->instance,
    ]);
    if (!$submission) {
        return false;
    }

    $isowner = (int)$submission->userid === (int)$USER->id;
    $istutor = has_capability('mod/peerreviewstudio:viewreport', $context)
        && peerreviewstudio_can_access_submission_owner($cm, $context, (int)$submission->userid);
    $isreviewer = !$isowner && !$istutor
        && has_capability('mod/peerreviewstudio:review', $context)
        && $DB->record_exists_select(
            'peerreviewstudio_assignments',
            "submissionid = :submissionid AND reviewerid = :reviewerid AND status IN ('assigned', 'completed')",
            ['submissionid' => $submissionid, 'reviewerid' => (int)$USER->id]
        );
    if (!$isowner && !$istutor && !$isreviewer) {
        return false;
    }

    $file = get_file_storage()->get_file($context->id, 'mod_peerreviewstudio', $filearea, $submissionid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Learner uploads are always sent as downloads, never rendered inline.
    send_stored_file($file, 0, 0, true, $options);
    return true;
}

/**
 * Normalise the activity settings coming from the activity form.
 *
 * @param stdClass $data Form data, modified in place.
 * @return void
 */
function peerreviewstudio_normalise_instance_data(stdClass $data): void {
    $data->submissiontype = array_key_exists((string)($data->submissiontype ?? ''), peerreviewstudio_submission_type_options())
        ? $data->submissiontype : 'file';
    $data->reviewcountrequired = max(1, (int)($data->reviewcountrequired ?? 1));
    $data->reviewsreceivedtarget = max(1, (int)($data->reviewsreceivedtarget ?? 1));
    $data->autoassignenabled = empty($data->autoassignenabled) ? 0 : 1;
    $data->reviewpoolmode = array_key_exists((string)($data->reviewpoolmode ?? ''), peerreviewstudio_review_pool_options())
        ? $data->reviewpoolmode : 'any';
    $data->avoidreciprocalreviews = empty($data->avoidreciprocalreviews) ? 0 : 1;
    $data->reviewreminderdays = max(0, (int)($data->reviewreminderdays ?? 2));
    $data->reviewreassigndays = max(0, (int)($data->reviewreassigndays ?? 4));
    $data->workflowmode = array_key_exists((string)($data->workflowmode ?? ''), peerreviewstudio_workflow_mode_options())
        ? $data->workflowmode : 'scheduled';
    $data->manualphase = array_key_exists((string)($data->manualphase ?? ''), peerreviewstudio_workflow_phase_options())
        ? $data->manualphase : 'submission';
    $data->grademaxsubmission = max(0, (float)($data->grademaxsubmission ?? 100));
    $data->grademaxreview = max(0, (float)($data->grademaxreview ?? 0));
    $data->aireviewenabled = empty($data->aireviewenabled) ? 0 : 1;
    $data->aifallbackenabled = empty($data->aifallbackenabled) ? 0 : 1;
    $data->aigradeweight = min(100, max(0, (float)($data->aigradeweight ?? 30)));

    // A prompt equal to the plugin default is stored empty, so activities
    // keep following the default when it improves in a later release.
    foreach (['aipromptsubmission', 'aipromptreviewer'] as $field) {
        $prompt = trim((string)($data->{$field} ?? ''));
        if ($prompt === trim(get_string($field . '_default', 'mod_peerreviewstudio'))) {
            $prompt = '';
        }
        $data->{$field} = $prompt;
    }
}

/**
 * Create a new Peer Review Studio instance.
 *
 * @param stdClass $data Form data.
 * @param mod_peerreviewstudio_mod_form|null $mform Activity form.
 * @return int
 */
function peerreviewstudio_add_instance(stdClass $data, ?mod_peerreviewstudio_mod_form $mform = null): int {
    global $DB;

    peerreviewstudio_normalise_instance_data($data);
    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->timegradesreleased = 0;

    $data->id = $DB->insert_record('peerreviewstudio', $data);
    $rubricdefinition = peerreviewstudio_extract_rubric_definition_from_form((array)$data);
    peerreviewstudio_sync_activity_rubric((int)$data->id, $rubricdefinition ?: peerreviewstudio_default_rubric_definition());
    peerreviewstudio_grade_item_update($data);

    if (!empty($data->coursemodule)) {
        peerreviewstudio_set_calendar_events($data);
        \core_completion\api::update_completion_date_event(
            $data->coursemodule,
            'peerreviewstudio',
            $data->id,
            $data->completionexpected ?? null
        );
    }

    return (int)$data->id;
}

/**
 * Update an existing Peer Review Studio instance.
 *
 * @param stdClass $data Form data.
 * @param mod_peerreviewstudio_mod_form|null $mform Activity form.
 * @return bool
 */
function peerreviewstudio_update_instance(stdClass $data, ?mod_peerreviewstudio_mod_form $mform = null): bool {
    global $DB, $USER;

    $previous = $DB->get_record('peerreviewstudio', ['id' => $data->instance], '*', MUST_EXIST);
    $context = peerreviewstudio_get_activity_context((int)$data->instance);

    $data->id = $data->instance;
    peerreviewstudio_normalise_instance_data($data);
    $data->timemodified = time();
    $data->timegradesreleased = 0;

    if (!has_capability('mod/peerreviewstudio:manageworkflow', $context)) {
        $data->workflowmode = $previous->workflowmode;
        $data->manualphase = $previous->manualphase;
    }

    $rubricbefore = json_encode(peerreviewstudio_get_activity_rubric((int)$data->id));
    $DB->update_record('peerreviewstudio', $data);
    $rubricdefinition = peerreviewstudio_extract_rubric_definition_from_form((array)$data);
    peerreviewstudio_sync_activity_rubric((int)$data->id, $rubricdefinition ?: peerreviewstudio_default_rubric_definition());
    $rubricchanged = $rubricbefore !== json_encode(peerreviewstudio_get_activity_rubric((int)$data->id));

    $activity = $DB->get_record('peerreviewstudio', ['id' => $data->id], '*', MUST_EXIST);
    $activity->cmidnumber = $data->cmidnumber ?? null;
    peerreviewstudio_grade_item_update($activity);

    if (!empty($data->coursemodule)) {
        $activity->coursemodule = $data->coursemodule;
        peerreviewstudio_set_calendar_events($activity);
        \core_completion\api::update_completion_date_event(
            $data->coursemodule,
            'peerreviewstudio',
            $activity->id,
            $data->completionexpected ?? null
        );
    }

    // Grades, statuses, and the reviewer queue follow the new settings.
    $resyncfields = [
        'grademaxsubmission', 'grademaxreview', 'reviewcountrequired', 'reviewsreceivedtarget',
        'autoassignenabled', 'reviewpoolmode', 'avoidreciprocalreviews',
        'aireviewenabled', 'aifallbackenabled', 'aigradeweight',
    ];
    $needsresync = $rubricchanged;
    foreach ($resyncfields as $field) {
        // Both records come straight from the database, so values compare as stored.
        if ((string)$previous->{$field} !== (string)$activity->{$field}) {
            $needsresync = true;
        }
    }
    if ($needsresync) {
        peerreviewstudio_resync_activity($activity);
    } else if (!empty($activity->autoassignenabled)) {
        // A phase switch may have just opened the matching.
        peerreviewstudio_process_assignment_queue($activity);
    }

    $newphase = peerreviewstudio_get_current_phase($activity);
    $oldphase = peerreviewstudio_get_current_phase($previous);
    $workflowchanged = $newphase !== $oldphase
        || (string)$previous->workflowmode !== (string)$activity->workflowmode
        || (int)$previous->timeclose !== (int)$activity->timeclose
        || (int)$previous->timereviewdue !== (int)$activity->timereviewdue;
    if ($workflowchanged) {
        // Reviewers learn about work that just became actionable, and
        // feedback is published (or hidden again) to match the new phase.
        peerreviewstudio_notify_pending_reviewers($activity);
        peerreviewstudio_release_feedback($activity);
        if (peerreviewstudio_review_window_over($activity)) {
            $DB->set_field('peerreviewstudio', 'timegradesreleased', time(), ['id' => $activity->id]);
        }

        $event = \mod_peerreviewstudio\event\workflow_phase_changed::create([
            'objectid' => (int)$activity->id,
            'context' => $context,
            'userid' => (int)($USER->id ?? 0),
            'other' => [
                'oldphase' => $oldphase,
                'newphase' => $newphase,
                'oldworkflowmode' => (string)$previous->workflowmode,
                'newworkflowmode' => (string)$activity->workflowmode,
            ],
        ]);
        $event->trigger();
    }

    return true;
}

/**
 * Delete a Peer Review Studio instance and related data.
 *
 * @param int $id Instance id.
 * @return bool
 */
function peerreviewstudio_delete_instance(int $id): bool {
    global $DB;

    if (!$activity = $DB->get_record('peerreviewstudio', ['id' => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance('peerreviewstudio', $id, 0, false, IGNORE_MISSING);
    $context = $cm ? context_module::instance((int)$cm->id, IGNORE_MISSING) : null;
    peerreviewstudio_delete_activity_user_data($id, $context ?: null);

    $params = ['activityid' => $id];
    $criteriasql = "SELECT c.id
                      FROM {peerreviewstudio_r_criteria} c
                      JOIN {peerreviewstudio_rubrics} r ON r.id = c.rubricid
                     WHERE r.peerreviewstudioid = :activityid";
    $DB->delete_records_select('peerreviewstudio_r_levels', "criterionid IN ($criteriasql)", $params);
    $DB->delete_records_select(
        'peerreviewstudio_r_criteria',
        "rubricid IN (SELECT r.id FROM {peerreviewstudio_rubrics} r WHERE r.peerreviewstudioid = :activityid)",
        $params
    );
    $DB->delete_records('peerreviewstudio_rubrics', ['peerreviewstudioid' => $id]);

    $DB->delete_records('event', ['modulename' => 'peerreviewstudio', 'instance' => $id]);
    peerreviewstudio_grade_item_delete($activity);
    $DB->delete_records('peerreviewstudio', ['id' => $id]);

    return true;
}

/**
 * Add the activity dates and completion rules to the course module cache.
 *
 * @param stdClass $coursemodule Course module record.
 * @return cached_cm_info|false
 */
function peerreviewstudio_get_coursemodule_info(stdClass $coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, completionsubmit, completionreviews, reviewcountrequired,
               workflowmode, timeopen, timeclose, timereviewdue';
    if (!$activity = $DB->get_record('peerreviewstudio', ['id' => $coursemodule->instance], $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $activity->name;
    if ($coursemodule->showdescription) {
        $result->content = format_module_intro('peerreviewstudio', $activity, $coursemodule->id, false);
    }

    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $result->customdata['customcompletionrules']['completionsubmit'] = (int)$activity->completionsubmit;
        $result->customdata['customcompletionrules']['completionreviews'] = empty($activity->completionreviews)
            ? 0 : (int)$activity->reviewcountrequired;
    }

    // Only scheduled mode is driven by dates.
    if ((string)$activity->workflowmode === 'scheduled') {
        foreach (['timeopen', 'timeclose', 'timereviewdue'] as $datefield) {
            if (!empty($activity->{$datefield})) {
                $result->customdata[$datefield] = (int)$activity->{$datefield};
            }
        }
    }

    return $result;
}

// Gradebook.

/**
 * Create or update the grade item for the activity.
 *
 * @param stdClass $activity Activity instance.
 * @param mixed $grades Grade payload, or the string 'reset' to reset all grades.
 * @return int
 */
function peerreviewstudio_grade_item_update(stdClass $activity, mixed $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grademax = (float)($activity->grademaxsubmission ?? 0) + (float)($activity->grademaxreview ?? 0);
    $params = ['itemname' => $activity->name];
    if (property_exists($activity, 'cmidnumber')) {
        $params['idnumber'] = $activity->cmidnumber;
    }
    if ($grademax > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $grademax;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/peerreviewstudio', $activity->course, 'mod', 'peerreviewstudio', $activity->id, 0, $grades, $params);
}

/**
 * Return the grade records for one or all users of the activity.
 *
 * A grade is only reported once it has been released to its author (see
 * peerreviewstudio_grade_is_released()); until then it is reported empty.
 *
 * @param stdClass $activity Activity instance.
 * @param int $userid One user id or 0 for all users.
 * @return array Grade objects indexed by user id.
 */
function peerreviewstudio_get_user_grades(stdClass $activity, int $userid = 0): array {
    global $DB;

    $select = 'peerreviewstudioid = :activityid AND userid > 0';
    $params = ['activityid' => $activity->id];
    if ($userid) {
        $select .= ' AND userid = :userid';
        $params['userid'] = $userid;
    }

    $grades = [];
    $submissions = $DB->get_recordset_select(
        'peerreviewstudio_submissions',
        $select,
        $params,
        'id ASC',
        'id, userid, status, finalgrade, teachergradeoverride, timesubmitted, timemodified'
    );
    foreach ($submissions as $submission) {
        $released = peerreviewstudio_grade_is_released($activity, $submission);
        $grades[(int)$submission->userid] = (object) [
            'userid' => (int)$submission->userid,
            'rawgrade' => ($released && $submission->finalgrade !== null) ? (float)$submission->finalgrade : null,
            'datesubmitted' => (int)$submission->timesubmitted,
            'dategraded' => (int)$submission->timemodified,
        ];
    }
    $submissions->close();

    return $grades;
}

/**
 * Update activity grades in the gradebook (standard module API callback).
 *
 * @param stdClass $activity Activity instance.
 * @param int $userid One user id or 0 for all users.
 * @param bool $nullifnone Insert a null grade when the user has none.
 * @return void
 */
function peerreviewstudio_update_grades(stdClass $activity, int $userid = 0, bool $nullifnone = true): void {
    $grades = peerreviewstudio_get_user_grades($activity, $userid);
    if ($grades) {
        peerreviewstudio_grade_item_update($activity, $grades);
    } else if ($userid && $nullifnone) {
        peerreviewstudio_grade_user_update($activity, $userid, null);
    } else {
        peerreviewstudio_grade_item_update($activity);
    }
}

/**
 * Update a single user grade in the gradebook.
 *
 * @param stdClass $activity Activity instance.
 * @param int $userid User id.
 * @param float|null $finalgrade Final grade.
 * @return int
 */
function peerreviewstudio_grade_user_update(stdClass $activity, int $userid, ?float $finalgrade): int {
    // The grades array passed to grade_update() must be indexed by user id.
    $grades = [
        $userid => (object) [
            'userid' => $userid,
            'rawgrade' => $finalgrade,
        ],
    ];

    return peerreviewstudio_grade_item_update($activity, $grades);
}

/**
 * Delete the grade item for the activity.
 *
 * @param stdClass $activity Activity instance.
 * @return int
 */
function peerreviewstudio_grade_item_delete(stdClass $activity): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        'mod/peerreviewstudio',
        $activity->course,
        'mod',
        'peerreviewstudio',
        $activity->id,
        0,
        null,
        ['deleted' => 1]
    );
}

// Course reset.

/**
 * Add the plugin options to the course reset form.
 *
 * @param MoodleQuickForm $mform Reset form.
 * @return void
 */
function peerreviewstudio_reset_course_form_definition(&$mform): void {
    $mform->addElement('header', 'peerreviewstudioheader', get_string('modulenameplural', 'mod_peerreviewstudio'));
    $mform->addElement('advcheckbox', 'reset_peerreviewstudio', get_string('resetuserdata', 'mod_peerreviewstudio'));
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course Course record.
 * @return array
 */
function peerreviewstudio_reset_course_form_defaults($course): array {
    return ['reset_peerreviewstudio' => 1];
}

/**
 * Remove all grades from the gradebook for the plugin activities of a course.
 *
 * @param int $courseid Course id.
 * @param string $type Optional type (unused).
 * @return void
 */
function peerreviewstudio_reset_gradebook($courseid, $type = ''): void {
    global $DB;

    foreach ($DB->get_records('peerreviewstudio', ['course' => $courseid]) as $activity) {
        peerreviewstudio_grade_item_update($activity, 'reset');
    }
}

/**
 * Perform the course reset: remove learner data and shift the dates.
 *
 * @param stdClass $data Reset form data.
 * @return array Status rows.
 */
function peerreviewstudio_reset_userdata($data): array {
    global $DB;

    $componentstr = get_string('modulenameplural', 'mod_peerreviewstudio');
    $status = [];

    if (!empty($data->reset_peerreviewstudio)) {
        foreach ($DB->get_records('peerreviewstudio', ['course' => $data->courseid], '', 'id') as $activity) {
            $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$data->courseid);
            $context = $cm ? context_module::instance((int)$cm->id) : null;
            peerreviewstudio_delete_activity_user_data((int)$activity->id, $context);
        }
        $DB->set_field('peerreviewstudio', 'timegradesreleased', 0, ['course' => $data->courseid]);
        if (empty($data->reset_gradebook_grades)) {
            peerreviewstudio_reset_gradebook($data->courseid);
        }
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('resetuserdata', 'mod_peerreviewstudio'),
            'error' => false,
        ];
    }

    if (!empty($data->timeshift)) {
        // The same dates are rolled forward when a course is restored.
        shift_course_mod_dates(
            'peerreviewstudio',
            ['timeopen', 'timeclose', 'timereviewdue'],
            $data->timeshift,
            $data->courseid
        );
        peerreviewstudio_refresh_events($data->courseid);
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('date'),
            'error' => false,
        ];
    }

    return $status;
}

// Calendar.

/**
 * Create, update, or remove the calendar events of one activity.
 *
 * Only scheduled mode has deadlines: the submission close date and the
 * review due date become action events on the learners' timeline.
 *
 * @param stdClass $activity Activity record; 'coursemodule' is resolved when missing.
 * @return void
 */
function peerreviewstudio_set_calendar_events(stdClass $activity): void {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/calendar/lib.php');

    if (empty($activity->coursemodule)) {
        $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$activity->course);
        if (!$cm) {
            return;
        }
        $activity->coursemodule = $cm->id;
    }

    $scheduled = (string)($activity->workflowmode ?? 'scheduled') === 'scheduled';
    $wanted = [
        PEERREVIEWSTUDIO_EVENT_TYPE_SUBMISSION => [
            'time' => $scheduled ? (int)($activity->timeclose ?? 0) : 0,
            'name' => 'calendarsubmissiondue',
        ],
        PEERREVIEWSTUDIO_EVENT_TYPE_REVIEW => [
            'time' => $scheduled ? (int)($activity->timereviewdue ?? 0) : 0,
            'name' => 'calendarreviewdue',
        ],
    ];

    foreach ($wanted as $eventtype => $definition) {
        $eventid = $DB->get_field('event', 'id', [
            'modulename' => 'peerreviewstudio',
            'instance' => $activity->id,
            'eventtype' => $eventtype,
        ]);

        if ($definition['time'] <= 0) {
            if ($eventid) {
                calendar_event::load($eventid)->delete();
            }
            continue;
        }

        $event = new stdClass();
        $event->eventtype = $eventtype;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->name = get_string($definition['name'], 'mod_peerreviewstudio', $activity->name);
        $event->description = format_module_intro('peerreviewstudio', $activity, $activity->coursemodule, false);
        $event->format = FORMAT_HTML;
        $event->timestart = $definition['time'];
        $event->timesort = $definition['time'];
        $event->timeduration = 0;
        $event->visible = instance_is_visible('peerreviewstudio', $activity);

        if ($eventid) {
            calendar_event::load($eventid)->update($event, false);
        } else {
            $event->courseid = $activity->course;
            $event->groupid = 0;
            $event->userid = 0;
            $event->modulename = 'peerreviewstudio';
            $event->instance = $activity->id;
            calendar_event::create($event, false);
        }
    }
}

/**
 * Rebuild the calendar events of the plugin (standard module API callback).
 *
 * @param int $courseid Course id, or 0 for every course.
 * @param int|stdClass|null $instance One activity (id or record) to refresh.
 * @param int|stdClass|null $cm Course module (unused).
 * @return bool
 */
function peerreviewstudio_refresh_events($courseid = 0, $instance = null, $cm = null): bool {
    global $DB;

    if (isset($instance)) {
        if (!is_object($instance)) {
            $instance = $DB->get_record('peerreviewstudio', ['id' => $instance], '*', MUST_EXIST);
        }
        peerreviewstudio_set_calendar_events($instance);
        return true;
    }

    $conditions = $courseid ? ['course' => $courseid] : [];
    foreach ($DB->get_records('peerreviewstudio', $conditions) as $activity) {
        peerreviewstudio_set_calendar_events($activity);
    }

    return true;
}

/**
 * Tell the calendar what a learner still has to do for one deadline event.
 *
 * @param calendar_event $event Calendar event.
 * @param \core_calendar\action_factory $factory Action factory.
 * @param int $userid User to check; defaults to the current user.
 * @return \core_calendar\local\event\entities\action_interface|null Null when nothing is pending.
 */
function mod_peerreviewstudio_core_calendar_provide_event_action(
    calendar_event $event,
    \core_calendar\action_factory $factory,
    int $userid = 0
) {
    global $DB, $USER;

    if (!$userid) {
        $userid = (int)$USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['peerreviewstudio'][$event->instance] ?? null;
    if (!$cm || !$cm->uservisible) {
        return null;
    }
    $activity = $DB->get_record('peerreviewstudio', ['id' => $event->instance]);
    if (!$activity) {
        return null;
    }
    $context = context_module::instance($cm->id);
    $url = new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]);

    if ($event->eventtype === PEERREVIEWSTUDIO_EVENT_TYPE_SUBMISSION) {
        if (
            !has_capability('mod/peerreviewstudio:submit', $context, $userid, false)
                || peerreviewstudio_get_user_submission((int)$activity->id, $userid)
                || peerreviewstudio_get_current_phase($activity) !== 'submission'
        ) {
            return null;
        }

        return $factory->create_instance(
            get_string('submitmywork', 'mod_peerreviewstudio'),
            $url,
            1,
            peerreviewstudio_phase_allows_submissions($activity)
        );
    }

    if ($event->eventtype === PEERREVIEWSTUDIO_EVENT_TYPE_REVIEW) {
        if (
            !has_capability('mod/peerreviewstudio:review', $context, $userid, false)
                || peerreviewstudio_review_window_over($activity)
        ) {
            return null;
        }
        $open = $DB->count_records('peerreviewstudio_assignments', [
            'peerreviewstudioid' => $activity->id,
            'reviewerid' => $userid,
            'status' => 'assigned',
        ]);
        if (!$open) {
            return null;
        }

        return $factory->create_instance(
            get_string('calendarreviewaction', 'mod_peerreviewstudio'),
            $url,
            $open,
            peerreviewstudio_phase_allows_reviews($activity)
        );
    }

    return null;
}
