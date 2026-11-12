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
 * Return the list of Moodle features supported by the module.
 *
 * @param string $feature Feature constant.
 * @return string|bool|null
 */
function peerreviewstudio_supports(string $feature): string|bool|null {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_GROUPS:
            return true;
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
 * @param stdClass $course Course.
 * @param stdClass|cm_info $cm Course module.
 * @param context $context Context.
 * @param string $filearea File area.
 * @param array $args File args.
 * @param bool $forcedownload Force download.
 * @param array $options Additional options.
 * @return false|void False when access is denied, otherwise the file is sent and execution ends.
 */
function peerreviewstudio_pluginfile(
    $course,
    $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = [],
) {
    global $DB, $USER;

    if ($context->contextlevel !== CONTEXT_MODULE) {
        return false;
    }

    if ($filearea === 'intro') {
        // Activity description files are visible to anyone who can see the activity.
        require_course_login($course, true, $cm);
        $fs = get_file_storage();
        $filename = array_pop($args);
        $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
        $file = $fs->get_file($context->id, 'mod_peerreviewstudio', 'intro', 0, $filepath, $filename);
        if (!$file || $file->is_directory()) {
            return false;
        }
        send_stored_file($file, null, 0, $forcedownload, $options);
        return;
    }

    if ($filearea !== 'submission_files') {
        return false;
    }

    require_login($course, true, $cm);
    if (!has_capability('mod/peerreviewstudio:view', $context)) {
        return false;
    }

    if (count($args) < 2) {
        return false;
    }

    $submissionid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepathparts = $args ? array_values($args) : [];
    $filepath = '/' . ($filepathparts ? implode('/', $filepathparts) . '/' : '');

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $cm->instance,
    ], '*', IGNORE_MISSING);
    if (!$submission) {
        return false;
    }

    $canviewreport = has_capability('mod/peerreviewstudio:viewreport', $context);
    $canreview = has_capability('mod/peerreviewstudio:review', $context);
    $canown = (int)$submission->userid === (int)$USER->id;
    $isassignedreviewer = $canreview && $DB->record_exists_select(
        'peerreviewstudio_assignments',
        "peerreviewstudioid = :activityid AND submissionid = :submissionid AND reviewerid = :reviewerid
             AND status IN ('assigned', 'completed')",
        [
            'activityid' => $cm->instance,
            'submissionid' => $submissionid,
            'reviewerid' => (int)$USER->id,
        ]
    );

    if (!$canviewreport && !$canown && !$isassignedreviewer) {
        return false;
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'mod_peerreviewstudio', $filearea, $submissionid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
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

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    $data->submissiontype = $data->submissiontype ?? 'file';
    $data->reviewcountrequired = max(1, (int)($data->reviewcountrequired ?? 1));
    $data->reviewsreceivedtarget = max(1, (int)($data->reviewsreceivedtarget ?? 1));
    $data->autoassignenabled = empty($data->autoassignenabled) ? 0 : 1;
    $data->reviewpoolmode = $data->reviewpoolmode ?? 'any';
    $data->avoidreciprocalreviews = empty($data->avoidreciprocalreviews) ? 0 : 1;
    $data->reviewreminderdays = max(0, (int)($data->reviewreminderdays ?? 2));
    $data->reviewreassigndays = max(0, (int)($data->reviewreassigndays ?? 4));
    $data->workflowmode = $data->workflowmode ?? 'scheduled';
    $data->manualphase = $data->manualphase ?? 'submission';
    $data->grademaxsubmission = max(0, (float)($data->grademaxsubmission ?? 100));
    $data->grademaxreview = max(0, (float)($data->grademaxreview ?? 0));
    $data->aireviewenabled = empty($data->aireviewenabled) ? 0 : 1;
    $data->aifallbackenabled = empty($data->aifallbackenabled) ? 0 : 1;
    $data->aigradeweight = min(100, max(0, (float)($data->aigradeweight ?? 30)));
    $data->aipromptsubmission = trim((string)($data->aipromptsubmission ?? ''));
    $data->aipromptreviewer = trim((string)($data->aipromptreviewer ?? ''));

    $id = $DB->insert_record('peerreviewstudio', $data);
    $data->id = $id;
    $rubricdefinition = peerreviewstudio_extract_rubric_definition_from_form((array)$data);
    peerreviewstudio_sync_activity_rubric($id, $rubricdefinition ?: peerreviewstudio_default_rubric_definition());
    peerreviewstudio_grade_item_update($data);

    return $id;
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
    $data->timemodified = time();
    $data->submissiontype = $data->submissiontype ?? 'file';
    $data->reviewcountrequired = max(1, (int)($data->reviewcountrequired ?? 1));
    $data->reviewsreceivedtarget = max(1, (int)($data->reviewsreceivedtarget ?? 1));
    $data->autoassignenabled = empty($data->autoassignenabled) ? 0 : 1;
    $data->reviewpoolmode = $data->reviewpoolmode ?? 'any';
    $data->avoidreciprocalreviews = empty($data->avoidreciprocalreviews) ? 0 : 1;
    $data->reviewreminderdays = max(0, (int)($data->reviewreminderdays ?? 2));
    $data->reviewreassigndays = max(0, (int)($data->reviewreassigndays ?? 4));
    $data->workflowmode = $data->workflowmode ?? 'scheduled';
    $data->manualphase = $data->manualphase ?? 'submission';
    $data->grademaxsubmission = max(0, (float)($data->grademaxsubmission ?? 100));
    $data->grademaxreview = max(0, (float)($data->grademaxreview ?? 0));
    $data->aireviewenabled = empty($data->aireviewenabled) ? 0 : 1;
    $data->aifallbackenabled = empty($data->aifallbackenabled) ? 0 : 1;
    $data->aigradeweight = min(100, max(0, (float)($data->aigradeweight ?? 30)));
    $data->aipromptsubmission = trim((string)($data->aipromptsubmission ?? ''));
    $data->aipromptreviewer = trim((string)($data->aipromptreviewer ?? ''));

    if (!has_capability('mod/peerreviewstudio:manageworkflow', $context)) {
        $data->workflowmode = $previous->workflowmode ?? 'scheduled';
        $data->manualphase = $previous->manualphase ?? 'submission';
    }

    $result = $DB->update_record('peerreviewstudio', $data);
    $rubricdefinition = peerreviewstudio_extract_rubric_definition_from_form((array)$data);
    peerreviewstudio_sync_activity_rubric((int)$data->id, $rubricdefinition ?: peerreviewstudio_default_rubric_definition());
    peerreviewstudio_grade_item_update($data);

    $newphase = peerreviewstudio_get_current_phase($data);
    $oldphase = peerreviewstudio_get_current_phase($previous);
    if ($newphase !== $oldphase || (string)$previous->workflowmode !== (string)$data->workflowmode) {
        $event = \mod_peerreviewstudio\event\workflow_phase_changed::create([
            'objectid' => (int)$data->id,
            'context' => $context,
            'userid' => (int)($USER->id ?? 0),
            'other' => [
                'oldphase' => $oldphase,
                'newphase' => $newphase,
                'oldworkflowmode' => (string)($previous->workflowmode ?? 'scheduled'),
                'newworkflowmode' => (string)($data->workflowmode ?? 'scheduled'),
            ],
        ]);
        $event->trigger();
    }

    return $result;
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

    $submissions = $DB->get_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $id], '', 'id');
    foreach ($submissions as $submission) {
        $assignments = $DB->get_records('peerreviewstudio_assignments', ['submissionid' => $submission->id], '', 'id');
        foreach ($assignments as $assignment) {
            $reviews = $DB->get_records('peerreviewstudio_reviews', ['assignmentid' => $assignment->id], '', 'id');
            foreach ($reviews as $review) {
                $DB->delete_records('peerreviewstudio_r_answers', ['reviewid' => $review->id]);
            }
            $DB->delete_records('peerreviewstudio_reviews', ['assignmentid' => $assignment->id]);
        }
        $DB->delete_records('peerreviewstudio_assignments', ['submissionid' => $submission->id]);
        $DB->delete_records('peerreviewstudio_queue', ['submissionid' => $submission->id]);
    }

    $rubrics = $DB->get_records('peerreviewstudio_rubrics', ['peerreviewstudioid' => $id], '', 'id');
    foreach ($rubrics as $rubric) {
        $criteria = $DB->get_records('peerreviewstudio_r_criteria', ['rubricid' => $rubric->id], '', 'id');
        foreach ($criteria as $criterion) {
            $DB->delete_records('peerreviewstudio_r_levels', ['criterionid' => $criterion->id]);
        }
        $DB->delete_records('peerreviewstudio_r_criteria', ['rubricid' => $rubric->id]);
    }

    $DB->delete_records('peerreviewstudio_rubrics', ['peerreviewstudioid' => $id]);
    $DB->delete_records('peerreviewstudio_assignments', ['peerreviewstudioid' => $id]);
    $DB->delete_records('peerreviewstudio_queue', ['peerreviewstudioid' => $id]);
    $DB->delete_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $id]);
    peerreviewstudio_grade_item_delete($activity);
    $DB->delete_records('peerreviewstudio', ['id' => $id]);

    return true;
}

/**
 * Create or update the grade item for the activity.
 *
 * @param stdClass $activity Activity instance.
 * @param mixed $grades Grade payload.
 * @return int
 */
function peerreviewstudio_grade_item_update(stdClass $activity, mixed $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $grademax = (float)($activity->grademaxsubmission ?? 0) + (float)($activity->grademaxreview ?? 0);
    $params = ['itemname' => $activity->name];
    if ($grademax > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $grademax;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    return grade_update(
        'mod/peerreviewstudio',
        $activity->course,
        'mod',
        'peerreviewstudio',
        $activity->id,
        0,
        $grades,
        $params,
    );
}

/**
 * Return the grade records for one or all users of the activity.
 *
 * @param stdClass $activity Activity instance.
 * @param int $userid One user id or 0 for all users.
 * @return array
 */
function peerreviewstudio_get_user_grades(stdClass $activity, int $userid = 0): array {
    global $DB;

    $params = ['activityid' => $activity->id];
    $usersql = '';
    if ($userid) {
        $usersql = ' AND s.userid = :userid';
        $params['userid'] = $userid;
    }

    $sql = "SELECT s.userid,
                   s.finalgrade AS rawgrade,
                   s.timesubmitted AS datesubmitted,
                   s.timemodified AS dategraded
              FROM {peerreviewstudio_submissions} s
             WHERE s.peerreviewstudioid = :activityid
               AND s.userid > 0" . $usersql;

    return $DB->get_records_sql($sql, $params);
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
        $grade = (object) [
            'userid' => $userid,
            'rawgrade' => null,
        ];
        // The grades array passed to grade_update() must be indexed by user id.
        peerreviewstudio_grade_item_update($activity, [$userid => $grade]);
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
        ['deleted' => 1],
    );
}
