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
 * Local helpers for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Maximum number of files accepted per submission.
 */
const PEERREVIEWSTUDIO_MAXFILES = 20;

/**
 * Maximum automatic reassignments per submission before escalating.
 */
const PEERREVIEWSTUDIO_MAX_AUTO_REASSIGNMENTS = 3;

/**
 * Maximum incident rows shown on the tutor dashboard per load.
 */
const PEERREVIEWSTUDIO_MAX_INCIDENT_ROWS = 20;

/**
 * Maximum number of learner names listed in one tutor escalation digest.
 */
const PEERREVIEWSTUDIO_MAX_ESCALATION_NAMES = 10;

// Context, options and labels.

/**
 * Resolve the module context for one activity.
 *
 * @param int $activityid Activity id.
 * @return context_module
 */
function peerreviewstudio_get_activity_context(int $activityid): context_module {
    $cm = get_coursemodule_from_instance('peerreviewstudio', $activityid, 0, false, MUST_EXIST);
    return context_module::instance((int)$cm->id);
}

/**
 * Return supported submission type options.
 *
 * @return array
 */
function peerreviewstudio_submission_type_options(): array {
    return [
        'file' => get_string('submissiontype_file', 'mod_peerreviewstudio'),
        'onlinetext' => get_string('submissiontype_onlinetext', 'mod_peerreviewstudio'),
        'both' => get_string('submissiontype_both', 'mod_peerreviewstudio'),
    ];
}

/**
 * Return the reviewer pool mode options.
 *
 * @return array
 */
function peerreviewstudio_review_pool_options(): array {
    return [
        'any' => get_string('reviewpoolmode_any', 'mod_peerreviewstudio'),
        'samegroup' => get_string('reviewpoolmode_samegroup', 'mod_peerreviewstudio'),
        'differentgroup' => get_string('reviewpoolmode_differentgroup', 'mod_peerreviewstudio'),
    ];
}

/**
 * Workflow mode options.
 *
 * @return array
 */
function peerreviewstudio_workflow_mode_options(): array {
    return [
        'scheduled' => get_string('workflowmode_scheduled', 'mod_peerreviewstudio'),
        'manual' => get_string('workflowmode_manual', 'mod_peerreviewstudio'),
        'open' => get_string('workflowmode_open', 'mod_peerreviewstudio'),
    ];
}

/**
 * Workflow phase options.
 *
 * @return array
 */
function peerreviewstudio_workflow_phase_options(): array {
    return [
        'submission' => get_string('phase_submission', 'mod_peerreviewstudio'),
        'allocation' => get_string('phase_allocation', 'mod_peerreviewstudio'),
        'review' => get_string('phase_review', 'mod_peerreviewstudio'),
        'feedback' => get_string('phase_feedback', 'mod_peerreviewstudio'),
        'closed' => get_string('phase_closed', 'mod_peerreviewstudio'),
    ];
}

/**
 * Submission statuses, in workflow order.
 *
 * The status only describes the submission itself (how far it is from
 * having received its target number of reviews). The reviewing work its
 * author does for others is tracked through the assignments table.
 *
 * @return string[]
 */
function peerreviewstudio_submission_statuses(): array {
    return ['submitted', 'queued', 'assigned', 'partiallyreviewed', 'closed'];
}

/**
 * Return a human-readable status label.
 *
 * @param string $status Raw status.
 * @return string
 */
function peerreviewstudio_status_label(string $status): string {
    $statuskey = 'status_' . clean_param($status, PARAM_ALPHANUMEXT);
    if (get_string_manager()->string_exists($statuskey, 'mod_peerreviewstudio')) {
        return get_string($statuskey, 'mod_peerreviewstudio');
    }

    return s($status);
}

// Workflow phases.

/**
 * Return current workflow phase for one activity.
 *
 * In 'open' mode there is no single global phase: submission, review, and
 * feedback all run continuously and concurrently, gated per learner by
 * their own submission/assignment state instead of a shared calendar or
 * tutor-controlled switch. The 'ongoing' value returned here is only used
 * for display (phase chip/bar); see peerreviewstudio_phase_allows_* for
 * the actual per-user gating logic.
 *
 * @param stdClass $activity Activity record.
 * @return string
 */
function peerreviewstudio_get_current_phase(stdClass $activity): string {
    $mode = (string)($activity->workflowmode ?? 'scheduled');
    if ($mode === 'manual') {
        return (string)($activity->manualphase ?? 'submission');
    }
    if ($mode === 'open') {
        return 'ongoing';
    }

    $now = time();
    $timeclose = (int)($activity->timeclose ?? 0);
    $timereviewdue = (int)($activity->timereviewdue ?? 0);

    if (!$timeclose || $now <= $timeclose) {
        return 'submission';
    }
    if (!$timereviewdue || $now <= $timereviewdue) {
        return 'review';
    }

    return 'feedback';
}

/**
 * Whether learners may submit or edit work right now.
 *
 * Open mode never closes submissions: a rolling, self-paced activity
 * (e.g. an OER course) must accept work at any time. Scheduled mode also
 * honours the optional opening date; manual mode ignores every date.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_phase_allows_submissions(stdClass $activity): bool {
    $mode = (string)($activity->workflowmode ?? 'scheduled');
    if ($mode === 'open') {
        return true;
    }
    if (peerreviewstudio_get_current_phase($activity) !== 'submission') {
        return false;
    }
    if ($mode === 'scheduled' && !empty($activity->timeopen) && time() < (int)$activity->timeopen) {
        return false;
    }

    return true;
}

/**
 * Whether reviewers may be matched to submissions right now.
 *
 * Open mode matches continuously. Scheduled mode waits until submissions
 * close, so every submission takes part in the matching and reviewers are
 * only told about work they can actually start. Manual mode matches during
 * the allocation and review phases.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_matching_allowed(stdClass $activity): bool {
    $mode = (string)($activity->workflowmode ?? 'scheduled');
    if ($mode === 'open') {
        return true;
    }
    $phase = peerreviewstudio_get_current_phase($activity);
    if ($mode === 'manual') {
        return in_array($phase, ['allocation', 'review'], true);
    }

    return $phase === 'review';
}

/**
 * Whether the current phase allows reviewing.
 *
 * Open mode always allows reviewing: whether a specific learner actually
 * has something to review is decided by whether they hold an 'assigned'
 * assignment, not by a shared activity-wide phase.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_phase_allows_reviews(stdClass $activity): bool {
    if ((string)($activity->workflowmode ?? 'scheduled') === 'open') {
        return true;
    }
    return peerreviewstudio_get_current_phase($activity) === 'review';
}

/**
 * Whether the current phase allows feedback visibility.
 *
 * Open mode has no shared feedback phase: a submission's own author sees
 * their received feedback as soon as that specific submission has reached
 * its reviews-received target (status 'closed'), independent of what
 * phase every other participant's submission is in.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass|null $submission The submission feedback visibility is being checked for.
 * @return bool
 */
function peerreviewstudio_phase_allows_feedback(stdClass $activity, ?stdClass $submission = null): bool {
    if ((string)($activity->workflowmode ?? 'scheduled') === 'open') {
        return $submission !== null && (string)$submission->status === 'closed';
    }
    return in_array(peerreviewstudio_get_current_phase($activity), ['feedback', 'closed'], true);
}

/**
 * Return the workflow phases that are reachable for one activity.
 *
 * Scheduled mode only ever moves through submission, review, and
 * feedback, so the learner-facing phase bar hides unreachable steps.
 * Open mode has no calendar-driven phase progression at all, so it shows
 * a single "ongoing" indicator instead of a step sequence.
 *
 * @param stdClass $activity Activity record.
 * @return array
 */
function peerreviewstudio_visible_workflow_phases(stdClass $activity): array {
    $mode = (string)($activity->workflowmode ?? 'scheduled');
    if ($mode === 'open') {
        return ['ongoing' => get_string('phase_ongoing', 'mod_peerreviewstudio')];
    }

    $options = peerreviewstudio_workflow_phase_options();
    if ($mode === 'scheduled') {
        $options = array_intersect_key($options, array_flip(['submission', 'review', 'feedback']));
    }

    return $options;
}

/**
 * Whether the review window is definitively over for one activity.
 *
 * Only scheduled mode has an irreversible review deadline. Manual mode
 * phases can be moved back by the teacher and open mode never ends, so
 * neither is treated as "over": their unresolved reviews are handled by
 * the inactivity clocks instead.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_review_window_over(stdClass $activity): bool {
    if ((string)($activity->workflowmode ?? 'scheduled') !== 'scheduled') {
        return false;
    }
    $due = (int)($activity->timereviewdue ?? 0);

    return $due > 0 && time() > $due;
}

// Rubric.

/**
 * Return the default structured rubric definition.
 *
 * @return array
 */
function peerreviewstudio_default_rubric_definition(): array {
    $levels = [
        ['label' => get_string('defaultrubric_level1', 'mod_peerreviewstudio'), 'score' => 1],
        ['label' => get_string('defaultrubric_level2', 'mod_peerreviewstudio'), 'score' => 2],
        ['label' => get_string('defaultrubric_level3', 'mod_peerreviewstudio'), 'score' => 3],
        ['label' => get_string('defaultrubric_level4', 'mod_peerreviewstudio'), 'score' => 4],
    ];

    $definition = [];
    foreach ([1, 2, 3] as $number) {
        $definition[] = [
            'label' => get_string('defaultrubric_criterion' . $number, 'mod_peerreviewstudio'),
            'description' => get_string('defaultrubric_criterion' . $number . '_desc', 'mod_peerreviewstudio'),
            'weight' => 1,
            'levels' => $levels,
        ];
    }

    return $definition;
}

/**
 * Convert rubric definition into form defaults.
 *
 * @param array $definition Rubric definition.
 * @return array
 */
function peerreviewstudio_rubric_definition_to_form_defaults(array $definition): array {
    $defaults = [];
    foreach (array_values($definition) as $criterionindex => $criterion) {
        $slot = $criterionindex + 1;
        $defaults['rubriccriterionlabel_' . $slot] = $criterion['label'] ?? '';
        $defaults['rubriccriteriondescription_' . $slot] = $criterion['description'] ?? '';
        $defaults['rubriccriterionweight_' . $slot] = $criterion['weight'] ?? 1;
        foreach (array_values($criterion['levels'] ?? []) as $levelindex => $level) {
            $levelslot = $levelindex + 1;
            $defaults['rubriclevellabel_' . $slot . '_' . $levelslot] = $level['label'] ?? '';
            $defaults['rubriclevelscore_' . $slot . '_' . $levelslot] = $level['score'] ?? 0;
        }
    }
    return $defaults;
}

/**
 * Extract rubric definition from form data.
 *
 * Each returned criterion carries the originating form slot in the
 * 'slot' key so validation errors can point at the right form field.
 *
 * @param array $data Form data.
 * @return array
 */
function peerreviewstudio_extract_rubric_definition_from_form(array $data): array {
    $definition = [];
    for ($criterionslot = 1; $criterionslot <= 5; $criterionslot++) {
        $label = trim((string)($data['rubriccriterionlabel_' . $criterionslot] ?? ''));
        if ($label === '') {
            continue;
        }
        $criterion = [
            'slot' => $criterionslot,
            'label' => $label,
            'description' => trim((string)($data['rubriccriteriondescription_' . $criterionslot] ?? '')),
            'weight' => max(0.1, (float)($data['rubriccriterionweight_' . $criterionslot] ?? 1)),
            'levels' => [],
        ];
        for ($levelslot = 1; $levelslot <= 4; $levelslot++) {
            $levellabel = trim((string)($data['rubriclevellabel_' . $criterionslot . '_' . $levelslot] ?? ''));
            if ($levellabel === '') {
                continue;
            }
            $criterion['levels'][] = [
                'label' => $levellabel,
                'score' => (float)($data['rubriclevelscore_' . $criterionslot . '_' . $levelslot] ?? 0),
            ];
        }
        if (!$criterion['levels']) {
            continue;
        }
        $definition[] = $criterion;
    }

    return $definition;
}

/**
 * Whether any rubric answers already exist for one activity.
 *
 * @param int $activityid Activity id.
 * @return bool
 */
function peerreviewstudio_activity_has_rubric_answers(int $activityid): bool {
    global $DB;

    $sql = "SELECT 1
              FROM {peerreviewstudio_r_answers} ans
              JOIN {peerreviewstudio_r_criteria} c ON c.id = ans.criterionid
              JOIN {peerreviewstudio_rubrics} r ON r.id = c.rubricid
             WHERE r.peerreviewstudioid = :activityid";

    return $DB->record_exists_sql($sql, ['activityid' => $activityid]);
}

/**
 * Synchronise the activity rubric with a supplied definition.
 *
 * The sync is performed in place by position so existing criterion and
 * level ids (and therefore stored rubric answers) are preserved when the
 * teacher edits labels, descriptions, weights, or scores.
 *
 * @param int $activityid Activity id.
 * @param array $definition Rubric definition.
 * @return void
 */
function peerreviewstudio_sync_activity_rubric(int $activityid, array $definition): void {
    global $DB;

    $now = time();
    $rubrics = array_values($DB->get_records('peerreviewstudio_rubrics', ['peerreviewstudioid' => $activityid], 'id ASC'));
    $rubric = $rubrics[0] ?? null;

    // Collapse accidental extra rubrics into the first one.
    for ($extraindex = 1; $extraindex < count($rubrics); $extraindex++) {
        $extracriteria = $DB->get_records('peerreviewstudio_r_criteria', ['rubricid' => $rubrics[$extraindex]->id], '', 'id');
        foreach ($extracriteria as $extracriterion) {
            $DB->delete_records('peerreviewstudio_r_answers', ['criterionid' => $extracriterion->id]);
            $DB->delete_records('peerreviewstudio_r_levels', ['criterionid' => $extracriterion->id]);
        }
        $DB->delete_records('peerreviewstudio_r_criteria', ['rubricid' => $rubrics[$extraindex]->id]);
        $DB->delete_records('peerreviewstudio_rubrics', ['id' => $rubrics[$extraindex]->id]);
    }

    if (!$rubric) {
        $rubric = (object) [
            'peerreviewstudioid' => $activityid,
            'name' => get_string('defaultrubricname', 'mod_peerreviewstudio'),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $rubric->id = $DB->insert_record('peerreviewstudio_rubrics', $rubric);
    } else {
        $DB->set_field('peerreviewstudio_rubrics', 'timemodified', $now, ['id' => $rubric->id]);
    }

    $existingcriteria = array_values(
        $DB->get_records('peerreviewstudio_r_criteria', ['rubricid' => $rubric->id], 'sortorder ASC, id ASC')
    );
    $definition = array_values($definition);

    foreach ($definition as $criterionindex => $criteriondef) {
        if (isset($existingcriteria[$criterionindex])) {
            $criterionrecord = $existingcriteria[$criterionindex];
            $criterionrecord->label = (string)$criteriondef['label'];
            $criterionrecord->description = (string)($criteriondef['description'] ?? '');
            $criterionrecord->weight = max(0.1, (float)($criteriondef['weight'] ?? 1));
            $criterionrecord->sortorder = $criterionindex;
            $DB->update_record('peerreviewstudio_r_criteria', $criterionrecord);
        } else {
            $criterionrecord = (object) [
                'rubricid' => $rubric->id,
                'label' => (string)$criteriondef['label'],
                'description' => (string)($criteriondef['description'] ?? ''),
                'weight' => max(0.1, (float)($criteriondef['weight'] ?? 1)),
                'sortorder' => $criterionindex,
            ];
            $criterionrecord->id = $DB->insert_record('peerreviewstudio_r_criteria', $criterionrecord);
        }

        $existinglevels = array_values(
            $DB->get_records('peerreviewstudio_r_levels', ['criterionid' => $criterionrecord->id], 'sortorder ASC, id ASC')
        );
        $leveldefs = array_values($criteriondef['levels'] ?? []);
        foreach ($leveldefs as $levelindex => $leveldef) {
            if (isset($existinglevels[$levelindex])) {
                $levelrecord = $existinglevels[$levelindex];
                $levelrecord->label = (string)$leveldef['label'];
                $levelrecord->score = (float)($leveldef['score'] ?? 0);
                $levelrecord->sortorder = $levelindex;
                $DB->update_record('peerreviewstudio_r_levels', $levelrecord);
            } else {
                $DB->insert_record('peerreviewstudio_r_levels', (object) [
                    'criterionid' => $criterionrecord->id,
                    'label' => (string)$leveldef['label'],
                    'score' => (float)($leveldef['score'] ?? 0),
                    'sortorder' => $levelindex,
                ]);
            }
        }
        // Remove trailing levels no longer present in the definition.
        for ($levelindex = count($leveldefs); $levelindex < count($existinglevels); $levelindex++) {
            $DB->delete_records('peerreviewstudio_r_answers', ['levelid' => $existinglevels[$levelindex]->id]);
            $DB->delete_records('peerreviewstudio_r_levels', ['id' => $existinglevels[$levelindex]->id]);
        }
    }

    // Remove trailing criteria no longer present in the definition.
    for ($criterionindex = count($definition); $criterionindex < count($existingcriteria); $criterionindex++) {
        $criterionrecord = $existingcriteria[$criterionindex];
        $DB->delete_records('peerreviewstudio_r_answers', ['criterionid' => $criterionrecord->id]);
        $DB->delete_records('peerreviewstudio_r_levels', ['criterionid' => $criterionrecord->id]);
        $DB->delete_records('peerreviewstudio_r_criteria', ['id' => $criterionrecord->id]);
    }
}

/**
 * Return the rubric structure for one activity.
 *
 * An activity that somehow has no rubric yet (for example one restored
 * from an incomplete backup) gets the default rubric first, so reviewing
 * never breaks.
 *
 * @param int $activityid Activity id.
 * @return array
 */
function peerreviewstudio_get_activity_rubric(int $activityid): array {
    global $DB;

    $rubric = $DB->get_record('peerreviewstudio_rubrics', ['peerreviewstudioid' => $activityid], '*', IGNORE_MULTIPLE);
    if (!$rubric) {
        peerreviewstudio_sync_activity_rubric($activityid, peerreviewstudio_default_rubric_definition());
        $rubric = $DB->get_record('peerreviewstudio_rubrics', ['peerreviewstudioid' => $activityid], '*', MUST_EXIST);
    }

    $criteria = $DB->get_records('peerreviewstudio_r_criteria', ['rubricid' => $rubric->id], 'sortorder ASC, id ASC');
    if (!$criteria) {
        return [];
    }
    [$insql, $params] = $DB->get_in_or_equal(array_keys($criteria), SQL_PARAMS_NAMED);
    $levels = $DB->get_records_select('peerreviewstudio_r_levels', "criterionid $insql", $params, 'sortorder ASC, id ASC');

    $result = [];
    foreach ($criteria as $criterion) {
        $result[(int)$criterion->id] = [
            'id' => (int)$criterion->id,
            'label' => (string)$criterion->label,
            'description' => (string)$criterion->description,
            'weight' => (float)$criterion->weight,
            'levels' => [],
        ];
    }
    foreach ($levels as $level) {
        $result[(int)$level->criterionid]['levels'][] = [
            'id' => (int)$level->id,
            'label' => (string)$level->label,
            'score' => (float)$level->score,
        ];
    }

    return array_values($result);
}

/**
 * Calculate rubric grade and answer payload.
 *
 * The returned grade is the weighted rubric ratio scaled into the given
 * grade space (the submission grade proposed by this review).
 *
 * @param array $rubric Rubric structure.
 * @param array $formdata Submitted review data.
 * @param float $grademax Maximum grade the review can propose.
 * @return array{grade:float, answers:array}
 */
function peerreviewstudio_calculate_rubric_result(array $rubric, array $formdata, float $grademax): array {
    $answers = [];
    $totalscore = 0.0;
    $totalmax = 0.0;

    foreach ($rubric as $criterion) {
        $field = 'criterion_' . (int)$criterion['id'];
        $selectedlevelid = (int)($formdata[$field] ?? 0);
        foreach ($criterion['levels'] as $level) {
            if ((int)$level['id'] !== $selectedlevelid) {
                continue;
            }
            $weight = (float)$criterion['weight'];
            $criterionmax = 0.0;
            foreach ($criterion['levels'] as $candidatelevel) {
                $criterionmax = max($criterionmax, (float)$candidatelevel['score']);
            }
            $totalscore += ((float)$level['score']) * $weight;
            $totalmax += $criterionmax * $weight;
            $answers[] = [
                'criterionid' => (int)$criterion['id'],
                'levelid' => (int)$level['id'],
                'score' => (float)$level['score'],
                'commenttext' => trim((string)($formdata['criterioncomment_' . (int)$criterion['id']] ?? '')),
            ];
            break;
        }
    }

    $ratio = $totalmax > 0 ? ($totalscore / $totalmax) : 0.0;
    $grade = $grademax > 0 ? round($ratio * $grademax, 2) : 0.0;

    return ['grade' => $grade, 'answers' => $answers];
}

/**
 * Recalculate the grade every stored review proposes, from its rubric answers.
 *
 * The rubric answers are the source of truth: when a teacher changes the
 * maximum submission grade, the criterion weights, or the level scores,
 * the grade each review proposes must follow.
 *
 * @param stdClass $activity Activity record.
 * @return void
 */
function peerreviewstudio_recalculate_review_grades(stdClass $activity): void {
    global $DB;

    $rubric = peerreviewstudio_get_activity_rubric((int)$activity->id);
    $grademax = (float)($activity->grademaxsubmission ?? 0);

    $sql = "SELECT ans.id, ans.reviewid, ans.criterionid, ans.levelid, ans.commenttext, r.grade
              FROM {peerreviewstudio_r_answers} ans
              JOIN {peerreviewstudio_reviews} r ON r.id = ans.reviewid
              JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
             WHERE a.peerreviewstudioid = :activityid
          ORDER BY ans.reviewid ASC, ans.id ASC";
    $formdatabyreview = [];
    $storedgrades = [];
    $recordset = $DB->get_recordset_sql($sql, ['activityid' => $activity->id]);
    foreach ($recordset as $answer) {
        $formdatabyreview[(int)$answer->reviewid]['criterion_' . (int)$answer->criterionid] = (int)$answer->levelid;
        $formdatabyreview[(int)$answer->reviewid]['criterioncomment_' . (int)$answer->criterionid] = (string)$answer->commenttext;
        $storedgrades[(int)$answer->reviewid] = $answer->grade;
    }
    $recordset->close();

    foreach ($formdatabyreview as $reviewid => $formdata) {
        $result = peerreviewstudio_calculate_rubric_result($rubric, $formdata, $grademax);
        foreach ($result['answers'] as $answer) {
            $DB->set_field('peerreviewstudio_r_answers', 'score', $answer['score'], [
                'reviewid' => $reviewid,
                'criterionid' => $answer['criterionid'],
            ]);
        }
        if ($storedgrades[$reviewid] === null || abs((float)$storedgrades[$reviewid] - $result['grade']) > 0.00001) {
            $DB->set_field('peerreviewstudio_reviews', 'grade', $result['grade'], ['id' => $reviewid]);
        }
    }
}

// Locks.

/**
 * Take a short-lived lock for a plugin resource.
 *
 * @param string $resource Resource name.
 * @param int $timeout Seconds to wait for the lock.
 * @return \core\lock\lock|false
 */
function peerreviewstudio_get_lock(string $resource, int $timeout = 10) {
    $factory = \core\lock\lock_config::get_lock_factory('mod_peerreviewstudio');
    return $factory->get_lock($resource, $timeout);
}

// Submissions.

/**
 * Return the current user submission for an activity.
 *
 * @param int $activityid Activity id.
 * @param int $userid User id.
 * @return stdClass|false
 */
function peerreviewstudio_get_user_submission(int $activityid, int $userid): stdClass|false {
    global $DB;

    if ($userid <= 0) {
        return false;
    }

    return $DB->get_record('peerreviewstudio_submissions', [
        'peerreviewstudioid' => $activityid,
        'userid' => $userid,
    ], '*', IGNORE_MULTIPLE);
}

/**
 * Whether a submission can still be edited by its author.
 *
 * Once a reviewer has been matched to the submission its content is
 * frozen: reviewers must assess the same work the author will be graded
 * on, so later edits are not allowed (a tutor requeue reopens it).
 *
 * @param stdClass $activity Activity record.
 * @param stdClass|false $submission Existing submission, or false when none.
 * @return bool
 */
function peerreviewstudio_submission_is_editable(stdClass $activity, stdClass|false $submission): bool {
    global $DB;

    if (!peerreviewstudio_phase_allows_submissions($activity)) {
        return false;
    }
    if (!$submission) {
        return true;
    }
    if ((string)$submission->status === 'closed') {
        return false;
    }

    return !$DB->record_exists_select(
        'peerreviewstudio_assignments',
        "submissionid = :submissionid AND status <> 'cancelled'",
        ['submissionid' => $submission->id]
    );
}

/**
 * Prepare submission form editor and draft files for one submission.
 *
 * @param context_module $context Module context.
 * @param stdClass|false $submission Existing submission or false.
 * @return array
 */
function peerreviewstudio_prepare_submission_form_data(context_module $context, stdClass|false $submission): array {
    $data = [];
    $data['submissiontext_editor'] = [
        'text' => (string)($submission->onlinetext ?? ''),
        'format' => (int)($submission->onlinetextformat ?? FORMAT_HTML),
    ];

    $draftitemid = file_get_submitted_draft_itemid('submissionfiles_filemanager');
    file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'mod_peerreviewstudio',
        'submission_files',
        $submission ? (int)$submission->id : null,
        [
            'subdirs' => 0,
            'maxfiles' => PEERREVIEWSTUDIO_MAXFILES,
        ]
    );
    $data['submissionfiles_filemanager'] = $draftitemid;

    return $data;
}

/**
 * Return stored submission files.
 *
 * @param context_module $context Module context.
 * @param int $submissionid Submission id.
 * @return stored_file[]
 */
function peerreviewstudio_get_submission_files(context_module $context, int $submissionid): array {
    $fs = get_file_storage();
    return array_values(
        $fs->get_area_files($context->id, 'mod_peerreviewstudio', 'submission_files', $submissionid, 'filename', false)
    );
}

/**
 * Create or update a learner submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $userid User id.
 * @param array $editordata Editor payload.
 * @param int $draftitemid Draft file area item id (0 to skip file handling).
 * @param context_module|null $context Module context, required to save draft files.
 * @return stdClass
 */
function peerreviewstudio_save_submission(
    stdClass $activity,
    int $userid,
    array $editordata,
    int $draftitemid = 0,
    ?context_module $context = null,
): stdClass {
    global $DB;

    // Serialise saves per learner so a double click can never create two
    // submission rows for the same person.
    $lock = peerreviewstudio_get_lock('submission_' . (int)$activity->id . '_' . $userid);
    if (!$lock) {
        throw new moodle_exception('submissionbusy', 'mod_peerreviewstudio');
    }

    try {
        $now = time();
        $submission = peerreviewstudio_get_user_submission((int)$activity->id, $userid);
        $record = new stdClass();
        $record->onlinetext = trim((string)($editordata['text'] ?? ''));
        $record->onlinetextformat = (int)($editordata['format'] ?? FORMAT_HTML);
        $record->timesubmitted = $now;
        $record->timemodified = $now;

        $isnew = !$submission;
        if ($isnew) {
            $record->peerreviewstudioid = $activity->id;
            $record->userid = $userid;
            $record->status = 'submitted';
            $record->timecreated = $now;
            $record->id = $DB->insert_record('peerreviewstudio_submissions', $record);
        } else {
            // Editing keeps the workflow status: only the content changes.
            $record->id = $submission->id;
            $DB->update_record('peerreviewstudio_submissions', $record);
        }

        if ($context && $draftitemid > 0) {
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                'mod_peerreviewstudio',
                'submission_files',
                (int)$record->id,
                [
                    'subdirs' => 0,
                    'maxfiles' => PEERREVIEWSTUDIO_MAXFILES,
                ]
            );
        }
    } finally {
        $lock->release();
    }

    if ($isnew) {
        $event = \mod_peerreviewstudio\event\submission_created::create([
            'objectid' => $record->id,
            'context' => $context ?? peerreviewstudio_get_activity_context((int)$activity->id),
            'userid' => $userid,
        ]);
        $event->trigger();

        peerreviewstudio_send_notification($activity, 'submissionreceived', $userid);
    }

    if (!empty($activity->autoassignenabled)) {
        if (peerreviewstudio_needed_assignments($activity, (int)$record->id) > 0) {
            peerreviewstudio_queue_submission($activity, (int)$record->id);
        }
        peerreviewstudio_process_assignment_queue($activity);
    }

    peerreviewstudio_update_submission_status($activity, (int)$record->id);
    peerreviewstudio_refresh_submission_grade($activity, (int)$record->id);
    peerreviewstudio_update_completion_state($activity, $userid);

    return $DB->get_record('peerreviewstudio_submissions', ['id' => $record->id], '*', MUST_EXIST);
}

/**
 * Work out the workflow status of one submission from the stored facts.
 *
 * The status is derived, never hand-edited: it only reflects how far the
 * submission is from having received its target number of reviews.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return string One of peerreviewstudio_submission_statuses().
 */
function peerreviewstudio_derive_submission_status(stdClass $activity, stdClass $submission): string {
    global $DB;

    $completed = peerreviewstudio_count_received_reviews((int)$submission->id);
    $target = max(1, (int)($activity->reviewsreceivedtarget ?? 1));
    if ($completed >= $target) {
        return 'closed';
    }
    if ($completed > 0) {
        return 'partiallyreviewed';
    }

    // A submission resolved by the AI fallback review stays closed: the AI
    // assessment stood in for the peer reviews that never arrived.
    if (
        (int)($submission->timeescalated ?? 0) > 0 && ($submission->aigrade ?? null) !== null
            && !empty($activity->aireviewenabled) && !empty($activity->aifallbackenabled)
    ) {
        return 'closed';
    }

    $open = $DB->count_records('peerreviewstudio_assignments', [
        'submissionid' => $submission->id,
        'status' => 'assigned',
    ]);
    if ($open > 0) {
        return 'assigned';
    }

    $queued = $DB->record_exists('peerreviewstudio_queue', [
        'submissionid' => $submission->id,
        'status' => 'pending',
    ]);

    return $queued ? 'queued' : 'submitted';
}

/**
 * Store the derived workflow status of one submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return string The current status.
 */
function peerreviewstudio_update_submission_status(stdClass $activity, int $submissionid): string {
    global $DB;

    $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $submissionid], '*', MUST_EXIST);
    $status = peerreviewstudio_derive_submission_status($activity, $submission);
    if ($status !== (string)$submission->status) {
        $DB->update_record('peerreviewstudio_submissions', (object) [
            'id' => $submissionid,
            'status' => $status,
            'timemodified' => time(),
        ]);
    }

    return $status;
}

/**
 * Count the submitted reviews one submission has received.
 *
 * @param int $submissionid Submission id.
 * @return int
 */
function peerreviewstudio_count_received_reviews(int $submissionid): int {
    global $DB;

    return (int)$DB->count_records_sql(
        "SELECT COUNT(r.id)
           FROM {peerreviewstudio_reviews} r
           JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
          WHERE a.submissionid = :submissionid
            AND r.status = 'submitted'",
        ['submissionid' => $submissionid]
    );
}

/**
 * Count the reviews one learner has completed in an activity.
 *
 * @param int $activityid Activity id.
 * @param int $userid Reviewer user id.
 * @return int
 */
function peerreviewstudio_count_completed_reviews(int $activityid, int $userid): int {
    global $DB;

    if ($userid <= 0) {
        return 0;
    }

    return (int)$DB->count_records_sql(
        "SELECT COUNT(r.id)
           FROM {peerreviewstudio_reviews} r
           JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
          WHERE a.peerreviewstudioid = :activityid
            AND r.reviewerid = :reviewerid
            AND r.status = 'submitted'",
        ['activityid' => $activityid, 'reviewerid' => $userid]
    );
}

// Grades.

/**
 * Calculate the automatic submission grade from the reviews received.
 *
 * Each submitted review stores the grade it proposes for the submission
 * (already in submission grade space), so the automatic submission grade
 * is the average of the received proposals, capped at the maximum.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return float|null Null while the submission has nothing to be graded on yet.
 */
function peerreviewstudio_calculate_submission_grade(stdClass $activity, int $submissionid): ?float {
    global $DB;

    $submissionmax = (float)($activity->grademaxsubmission ?? 0);
    if ($submissionmax <= 0) {
        return 0.0;
    }

    $sql = "SELECT AVG(r.grade)
              FROM {peerreviewstudio_reviews} r
              JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
             WHERE a.submissionid = :submissionid
               AND r.status = 'submitted'
               AND r.grade IS NOT NULL";
    $average = $DB->get_field_sql($sql, ['submissionid' => $submissionid]);
    $aigrade = null;
    if (!empty($activity->aireviewenabled)) {
        $aigrade = $DB->get_field('peerreviewstudio_submissions', 'aigrade', ['id' => $submissionid]);
        $aigrade = ($aigrade === false || $aigrade === null) ? null : ((float)$aigrade / 100.0) * $submissionmax;
    }

    if ($average === false || $average === null) {
        // AI fallback: when a submission never received any peer review and
        // the fallback is enabled, the AI grade stands in fully so the
        // learner is not left ungraded because of peers' inaction. As soon
        // as a real peer review arrives, the peer-average path takes over.
        if ($aigrade !== null && !empty($activity->aifallbackenabled)) {
            return round(min($submissionmax, max(0.0, $aigrade)), 2);
        }
        return null;
    }
    $peergrade = min($submissionmax, max(0.0, (float)$average));

    // Optional AI second review: blend the peer average with the AI grade
    // using the configured weight. The blend only applies once the AI
    // result exists, so grades never wait on the AI call.
    if ($aigrade !== null) {
        $weight = min(100.0, max(0.0, (float)($activity->aigradeweight ?? 0))) / 100.0;
        $peergrade = (1 - $weight) * $peergrade + $weight * $aigrade;
    }

    return round(min($submissionmax, max(0.0, $peergrade)), 2);
}

/**
 * Calculate review participation grade for one user.
 *
 * @param stdClass $activity Activity record.
 * @param int $userid User id.
 * @return float
 */
function peerreviewstudio_calculate_review_grade(stdClass $activity, int $userid): float {
    global $DB;

    $reviewmax = (float)($activity->grademaxreview ?? 0);
    if ($reviewmax <= 0 || $userid <= 0) {
        return 0.0;
    }

    $completed = peerreviewstudio_count_completed_reviews((int)$activity->id, $userid);
    $required = max(1, (int)($activity->reviewcountrequired ?? 1));
    $grade = peerreviewstudio_calculate_review_completion_grade($completed, $required, $reviewmax);

    // Optional AI second review: the reviewer participation grade is
    // scaled by the average AI quality assessment (0-100) of their
    // submitted reviews, once assessments exist.
    if (!empty($activity->aireviewenabled) && $grade > 0) {
        $quality = $DB->get_field_sql(
            "SELECT AVG(r.aireviewergrade)
               FROM {peerreviewstudio_reviews} r
               JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
              WHERE a.peerreviewstudioid = :activityid
                AND r.reviewerid = :reviewerid
                AND r.status = 'submitted'
                AND r.aireviewergrade IS NOT NULL",
            ['activityid' => $activity->id, 'reviewerid' => $userid]
        );
        if ($quality !== false && $quality !== null) {
            $grade = round($grade * (min(100.0, max(0.0, (float)$quality)) / 100.0), 2);
        }
    }

    return $grade;
}

/**
 * Calculate review participation grade from completed/required counts.
 *
 * @param int $completed Completed reviews.
 * @param int $required Required reviews.
 * @param float $reviewmax Review grade max.
 * @return float
 */
function peerreviewstudio_calculate_review_completion_grade(int $completed, int $required, float $reviewmax): float {
    if ($reviewmax <= 0) {
        return 0.0;
    }

    $required = max(1, $required);
    return round(min(1, ($completed / $required)) * $reviewmax, 2);
}

/**
 * Normalise one teacher override grade from raw request input.
 *
 * The value is parsed with the current user's locale, so "7,5" is
 * accepted wherever the comma is the decimal separator.
 *
 * @param stdClass $activity Activity record.
 * @param string $rawvalue Raw request value.
 * @return float|null Null clears the override.
 */
function peerreviewstudio_normalize_teacher_grade_override(stdClass $activity, string $rawvalue): ?float {
    $rawvalue = trim($rawvalue);
    if ($rawvalue === '') {
        return null;
    }

    $value = unformat_float($rawvalue, true);
    if ($value === false || $value === null || !is_finite((float)$value)) {
        throw new moodle_exception('invalidgradeoverride', 'mod_peerreviewstudio');
    }

    $value = max(0.0, (float)$value);
    $maxgrade = max(0.0, (float)($activity->grademaxsubmission ?? 0) + (float)($activity->grademaxreview ?? 0));
    if ($maxgrade > 0) {
        $value = min($maxgrade, $value);
    }

    return round($value, 2);
}

/**
 * Whether the grade of one submission may be shown to its author.
 *
 * Grades follow the feedback: they reach the gradebook when feedback is
 * released (feedback phase, or a closed submission in open mode), so the
 * gradebook never reveals a peer grade before the feedback itself. A
 * teacher override is always released.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return bool
 */
function peerreviewstudio_grade_is_released(stdClass $activity, stdClass $submission): bool {
    if ($submission->teachergradeoverride !== null) {
        return true;
    }

    return peerreviewstudio_phase_allows_feedback($activity, $submission);
}

/**
 * Recalculate the stored grade values for one submission and sync gradebook.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return void
 */
function peerreviewstudio_refresh_submission_grade(stdClass $activity, int $submissionid): void {
    global $DB;

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $activity->id,
    ], '*', MUST_EXIST);

    $submissiongrade = peerreviewstudio_calculate_submission_grade($activity, $submissionid);
    $reviewgrade = peerreviewstudio_calculate_review_grade($activity, (int)$submission->userid);

    if ($submission->teachergradeoverride !== null) {
        $finalgrade = (float)$submission->teachergradeoverride;
    } else if ($submissiongrade === null) {
        // Nothing to grade the submission on yet: leave it ungraded rather
        // than publishing a misleading partial grade.
        $finalgrade = null;
    } else {
        $finalgrade = round($submissiongrade + $reviewgrade, 2);
    }

    $changed = !peerreviewstudio_grades_equal($submission->submissiongrade, $submissiongrade)
        || !peerreviewstudio_grades_equal($submission->reviewgrade, $reviewgrade)
        || !peerreviewstudio_grades_equal($submission->finalgrade, $finalgrade);
    if ($changed) {
        $DB->update_record('peerreviewstudio_submissions', (object) [
            'id' => $submissionid,
            'submissiongrade' => $submissiongrade,
            'reviewgrade' => $reviewgrade,
            'finalgrade' => $finalgrade,
            'timemodified' => time(),
        ]);
    }

    if ((int)$submission->userid > 0) {
        $released = peerreviewstudio_grade_is_released($activity, $submission);
        peerreviewstudio_grade_user_update($activity, (int)$submission->userid, $released ? $finalgrade : null);
    }
}

/**
 * Compare two nullable stored grades.
 *
 * @param mixed $stored Value read from the database.
 * @param float|null $calculated Freshly calculated value.
 * @return bool
 */
function peerreviewstudio_grades_equal($stored, ?float $calculated): bool {
    if ($stored === null || $calculated === null) {
        return $stored === null && $calculated === null;
    }

    return abs((float)$stored - $calculated) < 0.00001;
}

/**
 * Set teacher override grade for one submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @param float|null $override Override grade or null to clear.
 * @return void
 */
function peerreviewstudio_set_teacher_grade_override(stdClass $activity, int $submissionid, ?float $override): void {
    global $DB, $USER;

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $activity->id,
    ], '*', MUST_EXIST);
    $DB->update_record('peerreviewstudio_submissions', (object) [
        'id' => $submissionid,
        'teachergradeoverride' => $override,
        'timemodified' => time(),
    ]);

    $event = \mod_peerreviewstudio\event\grade_override_updated::create([
        'objectid' => $submission->id,
        'context' => peerreviewstudio_get_activity_context((int)$activity->id),
        'userid' => (int)($USER->id ?? 0),
        'relateduserid' => (int)$submission->userid,
        'other' => [
            'peerreviewstudioid' => (int)$activity->id,
            'override' => $override,
        ],
    ]);
    $event->trigger();

    peerreviewstudio_refresh_submission_grade($activity, $submissionid);

    if ($override !== null && (int)$submission->userid > 0) {
        peerreviewstudio_send_notification($activity, 'gradereceived', (int)$submission->userid);
        $DB->set_field('peerreviewstudio_submissions', 'timegradenotified', time(), ['id' => $submissionid]);
    }
}

/**
 * Tell an author their feedback and grade are available, once.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return bool Whether a notification was sent.
 */
function peerreviewstudio_notify_grade_released(stdClass $activity, int $submissionid): bool {
    global $DB;

    $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $submissionid]);
    if (!$submission || (int)$submission->userid <= 0 || (int)$submission->timegradenotified > 0) {
        return false;
    }
    if (!peerreviewstudio_grade_is_released($activity, $submission)) {
        return false;
    }
    // Nothing to announce while the submission has neither feedback nor grade.
    if ($submission->finalgrade === null && !peerreviewstudio_count_received_reviews($submissionid)) {
        return false;
    }

    peerreviewstudio_send_notification($activity, 'gradereceived', (int)$submission->userid);
    $DB->set_field('peerreviewstudio_submissions', 'timegradenotified', time(), ['id' => $submissionid]);

    return true;
}

/**
 * Publish every released grade of one activity and notify the authors.
 *
 * Called whenever feedback may have just become visible: the scheduled
 * feedback phase starting, or a teacher switching phase or settings.
 *
 * @param stdClass $activity Activity record.
 * @return void
 */
function peerreviewstudio_release_feedback(stdClass $activity): void {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

    peerreviewstudio_update_grades($activity);

    $pending = $DB->get_fieldset_select(
        'peerreviewstudio_submissions',
        'id',
        'peerreviewstudioid = :activityid AND userid > 0 AND timegradenotified = 0',
        ['activityid' => $activity->id]
    );
    foreach ($pending as $submissionid) {
        peerreviewstudio_notify_grade_released($activity, (int)$submissionid);
    }
}

/**
 * Recalculate everything that depends on the activity settings.
 *
 * Run after a teacher changes the grading or matching settings: the grade
 * each review proposes, the status of every submission, the stored grades,
 * and the reviewer queue all follow the new configuration.
 *
 * @param stdClass $activity Activity record, as stored.
 * @return void
 */
function peerreviewstudio_resync_activity(stdClass $activity): void {
    global $DB;

    peerreviewstudio_recalculate_review_grades($activity);

    $submissionids = $DB->get_fieldset_select(
        'peerreviewstudio_submissions',
        'id',
        'peerreviewstudioid = :activityid AND userid > 0',
        ['activityid' => $activity->id]
    );
    if (!empty($activity->autoassignenabled)) {
        // Submissions the automatic cycle already gave up on stay with the tutor.
        $escalatedids = $DB->get_fieldset_select(
            'peerreviewstudio_submissions',
            'id',
            'peerreviewstudioid = :activityid AND timeescalated > 0',
            ['activityid' => $activity->id]
        );
        foreach (array_diff($submissionids, $escalatedids) as $submissionid) {
            if (peerreviewstudio_needed_assignments($activity, (int)$submissionid) > 0) {
                peerreviewstudio_queue_submission($activity, (int)$submissionid);
            }
        }
        peerreviewstudio_process_assignment_queue($activity);
    }

    foreach ($submissionids as $submissionid) {
        peerreviewstudio_update_submission_status($activity, (int)$submissionid);
        peerreviewstudio_refresh_submission_grade($activity, (int)$submissionid);
    }
}

// Completion.

/**
 * Re-evaluate the activity completion state of one learner.
 *
 * @param stdClass $activity Activity record.
 * @param int $userid User id.
 * @return void
 */
function peerreviewstudio_update_completion_state(stdClass $activity, int $userid): void {
    global $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    if ($userid <= 0 || (empty($activity->completionsubmit) && empty($activity->completionreviews))) {
        return;
    }

    $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$activity->course, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }
    $completion = new completion_info(get_course((int)$activity->course));
    if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $userid);
    }
}

// Notifications.

/**
 * Return the activity name as plain text, for messages and prompts.
 *
 * @param stdClass $activity Activity record.
 * @param context_module $context Module context.
 * @return string
 */
function peerreviewstudio_plain_activity_name(stdClass $activity, context_module $context): string {
    $name = format_string($activity->name, true, ['context' => $context]);
    return html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Send one workflow notification to a user.
 *
 * The subject and body come from the language strings
 * 'message_<provider>_subject' and 'message_<provider>_body', resolved in
 * the recipient's own language, with the activity name as placeholder.
 *
 * @param stdClass $activity Activity record.
 * @param string $providername Message provider name (see db/messages.php).
 * @param int $userid Recipient user id.
 * @param mixed $a Optional string placeholder payload; defaults to the activity name.
 * @return void
 */
function peerreviewstudio_send_notification(stdClass $activity, string $providername, int $userid, $a = null): void {
    $user = \core_user::get_user($userid);
    if (!$user || !empty($user->deleted) || !empty($user->suspended) || !\core_user::is_real_user($userid)) {
        return;
    }

    $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$activity->course, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }
    // Never notify about an activity the recipient cannot open (hidden,
    // restricted, or not enrolled any more).
    try {
        $visible = get_fast_modinfo((int)$activity->course, $userid)->get_cm((int)$cm->id)->uservisible;
    } catch (moodle_exception $e) {
        $visible = false;
    }
    if (!$visible) {
        return;
    }

    $context = context_module::instance((int)$cm->id);
    // The message is built as plain text and escaped once for its HTML version.
    $activityname = peerreviewstudio_plain_activity_name($activity, $context);
    if ($a === null) {
        $a = $activityname;
    }
    $stringmanager = get_string_manager();
    $lang = !empty($user->lang) ? $user->lang : null;
    $subject = $stringmanager->get_string('message_' . $providername . '_subject', 'mod_peerreviewstudio', $a, $lang);
    $body = $stringmanager->get_string('message_' . $providername . '_body', 'mod_peerreviewstudio', $a, $lang);
    $url = new moodle_url('/mod/peerreviewstudio/view.php', ['id' => (int)$cm->id]);

    $message = new \core\message\message();
    $message->component = 'mod_peerreviewstudio';
    $message->name = $providername;
    $message->userfrom = \core_user::get_noreply_user();
    $message->userto = $user;
    $message->subject = $subject;
    $message->fullmessage = $body . "\n\n" . $url->out(false);
    $message->fullmessageformat = FORMAT_PLAIN;
    $message->fullmessagehtml = html_writer::tag('p', s($body))
        . html_writer::tag('p', html_writer::link($url, s($activityname)));
    $message->smallmessage = $subject;
    $message->notification = 1;
    $message->courseid = (int)$activity->course;
    $message->contexturl = $url->out(false);
    $message->contexturlname = $activityname;

    message_send($message);
}

// Reviewer matching.

/**
 * Return the group memberships of a course, optionally limited to a grouping.
 *
 * @param int $courseid Course id.
 * @param int $groupingid Grouping id, or 0 for every course group.
 * @return array User id => list of group ids.
 */
function peerreviewstudio_get_group_memberships(int $courseid, int $groupingid = 0): array {
    global $DB;

    $params = ['courseid' => $courseid];
    $groupingjoin = '';
    if ($groupingid > 0) {
        $groupingjoin = 'JOIN {groupings_groups} gg ON gg.groupid = g.id AND gg.groupingid = :groupingid';
        $params['groupingid'] = $groupingid;
    }
    $sql = "SELECT gm.id, gm.userid, gm.groupid
              FROM {groups_members} gm
              JOIN {groups} g ON g.id = gm.groupid
                   {$groupingjoin}
             WHERE g.courseid = :courseid";

    $memberships = [];
    $recordset = $DB->get_recordset_sql($sql, $params);
    foreach ($recordset as $membership) {
        $memberships[(int)$membership->userid][] = (int)$membership->groupid;
    }
    $recordset->close();

    return $memberships;
}

/**
 * Classify every other submitter as an eligible or blocked reviewer.
 *
 * A candidate must be an active participant who may review, must not have
 * been matched to this submission before, and must respect the reciprocal
 * and group pool rules of the activity. Eligible candidates come back
 * ordered by reviewing load, so work is spread evenly.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return array{eligible: stdClass[], ineligible: stdClass[]} Blocked candidates carry a 'reasons' list.
 */
function peerreviewstudio_get_reviewer_candidates(stdClass $activity, stdClass $submission): array {
    global $DB;

    $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$activity->course, false, MUST_EXIST);
    $context = context_module::instance((int)$cm->id);
    [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, 'mod/peerreviewstudio:review', 0, true);
    $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

    $sql = "SELECT s.id, s.userid, s.status, s.timesubmitted, {$namefields},
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} al
                     WHERE al.peerreviewstudioid = s.peerreviewstudioid
                       AND al.reviewerid = s.userid
                       AND al.status <> 'cancelled') AS reviewload,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} ac
                     WHERE ac.peerreviewstudioid = s.peerreviewstudioid
                       AND ac.reviewerid = s.userid
                       AND ac.status = 'cancelled') AS cancelledload,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} ax
                     WHERE ax.submissionid = :submissionid
                       AND ax.reviewerid = s.userid) AS alreadyassigned,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} ar
                     WHERE ar.submissionid = s.id
                       AND ar.reviewerid = :authorid
                       AND ar.status <> 'cancelled') AS reciprocal,
                   CASE WHEN je.id IS NULL THEN 0 ELSE 1 END AS canreview
              FROM {peerreviewstudio_submissions} s
              JOIN {user} u ON u.id = s.userid
         LEFT JOIN ({$enrolledsql}) je ON je.id = s.userid
             WHERE s.peerreviewstudioid = :activityid
               AND s.userid <> :userid
               AND u.deleted = 0
          ORDER BY reviewload ASC, cancelledload ASC, s.timesubmitted ASC, s.id ASC";
    $candidates = $DB->get_records_sql($sql, $enrolledparams + [
        'submissionid' => $submission->id,
        'authorid' => $submission->userid,
        'activityid' => $activity->id,
        'userid' => $submission->userid,
    ]);

    $poolmode = (string)($activity->reviewpoolmode ?? 'any');
    $memberships = [];
    if ($poolmode !== 'any') {
        $memberships = peerreviewstudio_get_group_memberships((int)$activity->course, (int)$cm->groupingid);
    }
    $authorgroups = $memberships[(int)$submission->userid] ?? [];

    $result = ['eligible' => [], 'ineligible' => []];
    foreach ($candidates as $candidate) {
        $reasons = [];
        if (empty($candidate->canreview)) {
            $reasons[] = 'candidateinactive';
        }
        if (!empty($candidate->alreadyassigned)) {
            $reasons[] = 'alreadyassigned';
        }
        if (!empty($activity->avoidreciprocalreviews) && !empty($candidate->reciprocal)) {
            $reasons[] = 'reciprocal';
        }
        if ($poolmode !== 'any') {
            $shared = array_intersect($authorgroups, $memberships[(int)$candidate->userid] ?? []);
            if ($poolmode === 'samegroup' && !$shared) {
                $reasons[] = 'groupmismatch';
            }
            if ($poolmode === 'differentgroup' && $shared) {
                $reasons[] = 'samegroupblocked';
            }
        }

        if ($reasons) {
            $candidate->reasons = $reasons;
            $result['ineligible'][] = $candidate;
        } else {
            $result['eligible'][] = $candidate;
        }
    }

    return $result;
}

/**
 * Return eligible reviewers for one submission ordered by current load.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return stdClass[]
 */
function peerreviewstudio_get_eligible_reviewers_for_submission(stdClass $activity, stdClass $submission): array {
    return peerreviewstudio_get_reviewer_candidates($activity, $submission)['eligible'];
}

/**
 * Summarise why candidates are blocked, most frequent reason first.
 *
 * @param stdClass[] $candidates Blocked candidates carrying a 'reasons' list.
 * @return string
 */
function peerreviewstudio_ineligible_candidates_summary(array $candidates): string {
    $reasoncounts = [];
    foreach ($candidates as $candidate) {
        foreach (($candidate->reasons ?? []) as $reason) {
            $reasoncounts[$reason] = ($reasoncounts[$reason] ?? 0) + 1;
        }
    }
    if (!$reasoncounts) {
        return '';
    }

    arsort($reasoncounts);
    $parts = [];
    foreach (array_slice($reasoncounts, 0, 3, true) as $reason => $count) {
        $parts[] = get_string('eligibilityreason_' . $reason, 'mod_peerreviewstudio') . ' (' . $count . ')';
    }

    return implode(' ', $parts);
}

/**
 * Count open assignments plus submitted reviews for one submission.
 *
 * @param int $submissionid Submission id.
 * @return int
 */
function peerreviewstudio_count_review_capacity(int $submissionid): int {
    global $DB;

    $open = $DB->count_records('peerreviewstudio_assignments', [
        'submissionid' => $submissionid,
        'status' => 'assigned',
    ]);

    return (int)$open + peerreviewstudio_count_received_reviews($submissionid);
}

/**
 * How many additional reviewer assignments a submission still needs.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return int
 */
function peerreviewstudio_needed_assignments(stdClass $activity, int $submissionid): int {
    $target = max(1, (int)($activity->reviewsreceivedtarget ?? 1));

    return max(0, $target - peerreviewstudio_count_review_capacity($submissionid));
}

/**
 * Queue a submission for reviewer matching.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return void
 */
function peerreviewstudio_queue_submission(stdClass $activity, int $submissionid): void {
    global $DB;

    $pending = $DB->record_exists('peerreviewstudio_queue', [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submissionid,
        'status' => 'pending',
    ]);
    if ($pending) {
        return;
    }

    $now = time();
    $DB->insert_record('peerreviewstudio_queue', (object) [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submissionid,
        'queuetype' => 'awaiting_reviewer',
        'status' => 'pending',
        'attempts' => 0,
        'lastattemptat' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
    ]);
}

/**
 * Mark the pending queue entries of one submission with a final state.
 *
 * @param int $submissionid Submission id.
 * @param string $status New queue status ('resolved' or 'failed').
 * @return void
 */
function peerreviewstudio_close_queue_entries(int $submissionid, string $status = 'resolved'): void {
    global $DB;

    $DB->execute(
        "UPDATE {peerreviewstudio_queue}
            SET status = :newstatus, timemodified = :now
          WHERE submissionid = :submissionid
            AND status = 'pending'",
        ['newstatus' => $status, 'now' => time(), 'submissionid' => $submissionid]
    );
}

/**
 * Create one reviewer assignment and tell the reviewer when they can start.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @param int $reviewerid Reviewer user id.
 * @return stdClass The new assignment record.
 */
function peerreviewstudio_create_assignment(stdClass $activity, stdClass $submission, int $reviewerid): stdClass {
    global $DB;

    $now = time();
    $assignment = (object) [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submission->id,
        'reviewerid' => $reviewerid,
        'status' => 'assigned',
        'timeassigned' => $now,
        'timenotified' => 0,
        'timereminded' => 0,
        'timeopened' => 0,
        'timecompleted' => 0,
        'timemodified' => $now,
    ];
    $assignment->id = $DB->insert_record('peerreviewstudio_assignments', $assignment);

    // Reviewers are only told about work they can start right away; the
    // rest are notified when the review phase opens.
    if (peerreviewstudio_phase_allows_reviews($activity)) {
        peerreviewstudio_send_notification($activity, 'reviewassigned', $reviewerid);
        $assignment->timenotified = $now;
        $DB->set_field('peerreviewstudio_assignments', 'timenotified', $now, ['id' => $assignment->id]);
    }

    return $assignment;
}

/**
 * Notify reviewers of open assignments they have not been told about yet.
 *
 * @param stdClass $activity Activity record.
 * @return void
 */
function peerreviewstudio_notify_pending_reviewers(stdClass $activity): void {
    global $DB;

    if (!peerreviewstudio_phase_allows_reviews($activity)) {
        return;
    }

    $assignments = $DB->get_records('peerreviewstudio_assignments', [
        'peerreviewstudioid' => $activity->id,
        'status' => 'assigned',
        'timenotified' => 0,
    ], 'id ASC', 'id, reviewerid');
    $notified = [];
    $now = time();
    foreach ($assignments as $assignment) {
        // One message per reviewer, however many reviews they were given.
        if ((int)$assignment->reviewerid > 0 && empty($notified[(int)$assignment->reviewerid])) {
            peerreviewstudio_send_notification($activity, 'reviewassigned', (int)$assignment->reviewerid);
            $notified[(int)$assignment->reviewerid] = true;
        }
        $DB->set_field('peerreviewstudio_assignments', 'timenotified', $now, ['id' => $assignment->id]);
    }
}

/**
 * Process pending queue entries and create assignments up to the
 * configured reviews-received target for each queued submission.
 *
 * @param stdClass $activity Activity record.
 * @return int Number of assignments created.
 */
function peerreviewstudio_process_assignment_queue(stdClass $activity): int {
    global $DB;

    if (!peerreviewstudio_matching_allowed($activity)) {
        return 0;
    }

    // One matching run per activity at a time: the web request of a learner
    // submitting and the scheduled task must never assign the same slot twice.
    $lock = peerreviewstudio_get_lock('queue_' . (int)$activity->id);
    if (!$lock) {
        return 0;
    }

    $created = 0;
    try {
        $queueentries = $DB->get_records('peerreviewstudio_queue', [
            'peerreviewstudioid' => $activity->id,
            'status' => 'pending',
        ], 'timecreated ASC, id ASC');

        foreach ($queueentries as $queueentry) {
            $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $queueentry->submissionid]);
            if (!$submission || (int)$submission->userid <= 0) {
                peerreviewstudio_close_queue_entries((int)$queueentry->submissionid, 'failed');
                continue;
            }

            $needed = peerreviewstudio_needed_assignments($activity, (int)$submission->id);
            if ($needed > 0) {
                $eligible = peerreviewstudio_get_eligible_reviewers_for_submission($activity, $submission);
                foreach (array_slice($eligible, 0, $needed) as $reviewer) {
                    peerreviewstudio_create_assignment($activity, $submission, (int)$reviewer->userid);
                    $created++;
                    $needed--;
                }
            }

            $now = time();
            $DB->update_record('peerreviewstudio_queue', (object) [
                'id' => $queueentry->id,
                'attempts' => (int)$queueentry->attempts + 1,
                'lastattemptat' => $now,
                'timemodified' => $now,
                'status' => $needed <= 0 ? 'resolved' : 'pending',
            ]);
            peerreviewstudio_update_submission_status($activity, (int)$submission->id);
        }
    } finally {
        $lock->release();
    }

    return $created;
}

/**
 * Manually assign a concrete reviewer to one submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @param int $reviewerid Reviewer user id.
 * @return bool False when the reviewer is not eligible for the submission.
 */
function peerreviewstudio_assign_submission_to_reviewer(stdClass $activity, int $submissionid, int $reviewerid): bool {
    global $DB, $USER;

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $activity->id,
    ], '*', MUST_EXIST);

    $lock = peerreviewstudio_get_lock('queue_' . (int)$activity->id);
    if (!$lock) {
        return false;
    }
    try {
        $selected = null;
        foreach (peerreviewstudio_get_eligible_reviewers_for_submission($activity, $submission) as $candidate) {
            if ((int)$candidate->userid === $reviewerid) {
                $selected = $candidate;
                break;
            }
        }
        if (!$selected) {
            return false;
        }

        $assignment = peerreviewstudio_create_assignment($activity, $submission, $reviewerid);
        // A tutor stepping in restarts the automatic follow-up cycle.
        $DB->set_field('peerreviewstudio_submissions', 'timeescalated', 0, ['id' => $submissionid]);
        if (peerreviewstudio_needed_assignments($activity, $submissionid) <= 0) {
            peerreviewstudio_close_queue_entries($submissionid);
        }
        peerreviewstudio_update_submission_status($activity, $submissionid);
    } finally {
        $lock->release();
    }

    $event = \mod_peerreviewstudio\event\submission_manually_assigned::create([
        'objectid' => $submission->id,
        'context' => peerreviewstudio_get_activity_context((int)$activity->id),
        'userid' => (int)($USER->id ?? 0),
        'relateduserid' => $reviewerid,
        'other' => [
            'peerreviewstudioid' => (int)$activity->id,
            'assignmentid' => (int)$assignment->id,
            'submissionauthorid' => (int)$submission->userid,
        ],
    ]);
    $event->trigger();

    return true;
}

/**
 * Cancel one open review assignment.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $assignment Open assignment record.
 * @param bool $notify Whether to tell the reviewer the task was withdrawn.
 * @return void
 */
function peerreviewstudio_cancel_assignment(stdClass $activity, stdClass $assignment, bool $notify = true): void {
    global $DB;

    $DB->update_record('peerreviewstudio_assignments', (object) [
        'id' => $assignment->id,
        'status' => 'cancelled',
        'timemodified' => time(),
    ]);

    // Only reviewers who were told about the task need to hear it is gone.
    if ($notify && (int)$assignment->timenotified > 0 && (int)$assignment->reviewerid > 0) {
        peerreviewstudio_send_notification($activity, 'reviewwithdrawn', (int)$assignment->reviewerid);
    }
}

/**
 * Requeue a submission for another assignment attempt.
 *
 * Open reviewer assignments are withdrawn and the matching starts again;
 * reviews already received are kept.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return void
 */
function peerreviewstudio_requeue_submission(stdClass $activity, int $submissionid): void {
    global $DB, $USER;

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $activity->id,
    ], '*', MUST_EXIST);

    $openassignments = $DB->get_records('peerreviewstudio_assignments', [
        'submissionid' => $submissionid,
        'status' => 'assigned',
    ]);
    foreach ($openassignments as $assignment) {
        peerreviewstudio_cancel_assignment($activity, $assignment);
    }

    // A manual requeue is a fresh start: allow the automatic follow-up
    // cycle (reassignment, fallback, escalation) to run again.
    $DB->update_record('peerreviewstudio_submissions', (object) [
        'id' => $submissionid,
        'timeescalated' => 0,
        'timemodified' => time(),
    ]);

    $event = \mod_peerreviewstudio\event\submission_requeued::create([
        'objectid' => $submission->id,
        'context' => peerreviewstudio_get_activity_context((int)$activity->id),
        'userid' => (int)($USER->id ?? 0),
        'relateduserid' => (int)$submission->userid,
        'other' => [
            'peerreviewstudioid' => (int)$activity->id,
            'cancelledassignments' => count($openassignments),
        ],
    ]);
    $event->trigger();

    if (peerreviewstudio_needed_assignments($activity, $submissionid) > 0) {
        peerreviewstudio_queue_submission($activity, $submissionid);
    }
    peerreviewstudio_process_assignment_queue($activity);
    peerreviewstudio_update_submission_status($activity, $submissionid);
    peerreviewstudio_refresh_submission_grade($activity, $submissionid);
}

// Follow-up of unresolved reviews.

/**
 * Withdraw one open review assignment that will not be completed.
 *
 * When reassignment is requested the submission is queued again, unless
 * the automatic reassignment cap has been reached.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $assignment Open assignment record.
 * @param bool $tryreassign Whether to queue the submission for reassignment.
 * @return void
 */
function peerreviewstudio_expire_assignment(stdClass $activity, stdClass $assignment, bool $tryreassign): void {
    global $DB;

    // When the review deadline has simply passed there is nothing to hand
    // over to somebody else, so the reviewer is not told about a withdrawal.
    peerreviewstudio_cancel_assignment($activity, $assignment, $tryreassign);

    if ($tryreassign && !empty($activity->autoassignenabled)) {
        $cancelled = $DB->count_records('peerreviewstudio_assignments', [
            'submissionid' => $assignment->submissionid,
            'status' => 'cancelled',
        ]);
        if (
            $cancelled <= PEERREVIEWSTUDIO_MAX_AUTO_REASSIGNMENTS
                && peerreviewstudio_needed_assignments($activity, (int)$assignment->submissionid) > 0
        ) {
            peerreviewstudio_queue_submission($activity, (int)$assignment->submissionid);
        }
    }

    peerreviewstudio_update_submission_status($activity, (int)$assignment->submissionid);
}

/**
 * Return the tutors who should hear about one learner's unresolved submission.
 *
 * In separate groups mode only tutors who share a group with the author
 * (or can access every group) are included.
 *
 * @param stdClass $cm Course module record.
 * @param context_module $context Module context.
 * @param int $authorid Submission author user id.
 * @return int[] Tutor user ids.
 */
function peerreviewstudio_get_escalation_tutors(stdClass $cm, context_module $context, int $authorid): array {
    $tutors = get_enrolled_users($context, 'mod/peerreviewstudio:manageassignments', 0, 'u.id', null, 0, 0, true);
    $tutorids = array_map('intval', array_keys($tutors));

    if (groups_get_activity_groupmode($cm) != SEPARATEGROUPS) {
        return $tutorids;
    }

    $authorgroups = array_keys(groups_get_all_groups((int)$cm->course, $authorid, (int)$cm->groupingid, 'g.id'));
    $result = [];
    foreach ($tutorids as $tutorid) {
        if (has_capability('moodle/site:accessallgroups', $context, $tutorid)) {
            $result[] = $tutorid;
            continue;
        }
        $tutorgroups = array_keys(groups_get_all_groups((int)$cm->course, $tutorid, (int)$cm->groupingid, 'g.id'));
        if (array_intersect($authorgroups, $tutorgroups)) {
            $result[] = $tutorid;
        }
    }

    return $result;
}

/**
 * Escalate submissions with no feedback and no automatic remedy left.
 *
 * A submission qualifies when it has received zero submitted reviews, has
 * no open assignment in flight, was not escalated before, and one of the
 * dead ends applies: the scheduled review window is over, the automatic
 * reassignment cap was reached, or its queue entry has been waiting for a
 * reviewer for longer than the reassignment threshold.
 *
 * The preferred remedy is the optional AI fallback review; otherwise the
 * tutors receive one digest per run listing the learners concerned.
 *
 * @param stdClass $activity Activity record.
 * @return int Number of submissions escalated.
 */
function peerreviewstudio_escalate_unresolved_submissions(stdClass $activity): int {
    global $DB;

    $now = time();
    $reassigndays = max(0, (int)($activity->reviewreassigndays ?? 0));
    $windowover = peerreviewstudio_review_window_over($activity);
    $matchingallowed = peerreviewstudio_matching_allowed($activity);
    if (!$windowover && !$matchingallowed) {
        return 0;
    }

    $sql = "SELECT s.*
              FROM {peerreviewstudio_submissions} s
             WHERE s.peerreviewstudioid = :activityid
               AND s.userid > 0
               AND s.timeescalated = 0
               AND s.status <> 'closed'
               AND s.teachergradeoverride IS NULL
               AND NOT EXISTS (SELECT 1
                                 FROM {peerreviewstudio_assignments} ao
                                WHERE ao.submissionid = s.id
                                  AND ao.status = 'assigned')
               AND NOT EXISTS (SELECT 1
                                 FROM {peerreviewstudio_reviews} r
                                 JOIN {peerreviewstudio_assignments} ar ON ar.id = r.assignmentid
                                WHERE ar.submissionid = s.id
                                  AND r.status = 'submitted')
          ORDER BY s.timesubmitted ASC, s.id ASC";
    $candidates = $DB->get_records_sql($sql, ['activityid' => $activity->id]);
    if (!$candidates) {
        return 0;
    }

    // In scheduled mode nobody could be matched before submissions closed,
    // so the waiting clock starts there.
    $clockfloor = 0;
    if ((string)($activity->workflowmode ?? 'scheduled') === 'scheduled') {
        $clockfloor = (int)($activity->timeclose ?? 0);
    }

    $usefallback = !empty($activity->aifallbackenabled)
        && \mod_peerreviewstudio\local\ai\client::instance()->is_enabled_for($activity);
    $fortutors = [];
    $escalated = 0;

    foreach ($candidates as $submission) {
        $capreached = $DB->count_records('peerreviewstudio_assignments', [
            'submissionid' => $submission->id,
            'status' => 'cancelled',
        ]) >= PEERREVIEWSTUDIO_MAX_AUTO_REASSIGNMENTS;

        $queuestuck = false;
        if ($reassigndays > 0 && $matchingallowed) {
            $oldestpending = $DB->get_field_sql(
                "SELECT MIN(timecreated)
                   FROM {peerreviewstudio_queue}
                  WHERE submissionid = :submissionid
                    AND status = 'pending'",
                ['submissionid' => $submission->id]
            );
            if ($oldestpending) {
                $queuestuck = ($now - max((int)$oldestpending, $clockfloor)) >= $reassigndays * DAYSECS;
            }
        }

        if (!$windowover && !$capreached && !$queuestuck) {
            continue;
        }

        $DB->update_record('peerreviewstudio_submissions', (object) [
            'id' => $submission->id,
            'timeescalated' => $now,
            'timemodified' => $now,
        ]);
        $escalated++;

        if ($usefallback) {
            $task = new \mod_peerreviewstudio\task\ai_fallback_review_task();
            $task->set_custom_data(['submissionid' => (int)$submission->id]);
            \core\task\manager::queue_adhoc_task($task);
        } else {
            $fortutors[] = $submission;
        }
    }

    if ($fortutors) {
        peerreviewstudio_notify_tutors_of_unresolved($activity, $fortutors);
    }

    return $escalated;
}

/**
 * Send each tutor one digest of the submissions that need their attention.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass[] $submissions Unresolved submission records.
 * @return void
 */
function peerreviewstudio_notify_tutors_of_unresolved(stdClass $activity, array $submissions): void {
    $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$activity->course, false, IGNORE_MISSING);
    if (!$cm) {
        return;
    }
    $context = context_module::instance((int)$cm->id);

    $bytutor = [];
    foreach ($submissions as $submission) {
        $author = \core_user::get_user((int)$submission->userid);
        if (!$author) {
            continue;
        }
        foreach (peerreviewstudio_get_escalation_tutors($cm, $context, (int)$submission->userid) as $tutorid) {
            $bytutor[$tutorid][] = fullname($author);
        }
    }

    foreach ($bytutor as $tutorid => $names) {
        $listed = array_slice($names, 0, PEERREVIEWSTUDIO_MAX_ESCALATION_NAMES);
        if (count($names) > count($listed)) {
            $listed[] = '…';
        }
        peerreviewstudio_send_notification($activity, 'tutorescalation', (int)$tutorid, (object) [
            'name' => peerreviewstudio_plain_activity_name($activity, $context),
            'count' => count($names),
            'students' => implode(', ', $listed),
        ]);
    }
}

/**
 * Run the automatic follow-up cycle for unresolved reviews.
 *
 * In order: tell reviewers about work that just became actionable, remind
 * inactive reviewers, withdraw and reassign stale assignments, and
 * escalate (AI fallback review or tutor digest) the submissions nothing
 * else could resolve. The inactivity clocks only run while reviewing is
 * actually possible, and in scheduled mode they start when the review
 * phase opens (timeclose), not at assignment time.
 *
 * @param stdClass $activity Activity record.
 * @return void
 */
function peerreviewstudio_process_review_followups(stdClass $activity): void {
    global $DB;

    $now = time();
    $reminderdays = max(0, (int)($activity->reviewreminderdays ?? 0));
    $reassigndays = max(0, (int)($activity->reviewreassigndays ?? 0));
    $windowover = peerreviewstudio_review_window_over($activity);
    $reviewsallowed = peerreviewstudio_phase_allows_reviews($activity);

    peerreviewstudio_notify_pending_reviewers($activity);

    $openassignments = $DB->get_records('peerreviewstudio_assignments', [
        'peerreviewstudioid' => $activity->id,
        'status' => 'assigned',
    ], 'timeassigned ASC, id ASC');

    $requeued = false;
    foreach ($openassignments as $assignment) {
        if ($windowover) {
            // The review deadline passed: this assignment can never be
            // completed any more, so clear it out (no reassignment).
            peerreviewstudio_expire_assignment($activity, $assignment, false);
            continue;
        }
        if (!$reviewsallowed) {
            // Reviewing is not possible yet, so reviewers are not inactive.
            continue;
        }

        $clockstart = (int)$assignment->timeassigned;
        if ((string)($activity->workflowmode ?? 'scheduled') === 'scheduled') {
            $clockstart = max($clockstart, (int)($activity->timeclose ?? 0));
        }

        if ($reassigndays > 0 && ($now - $clockstart) >= $reassigndays * DAYSECS) {
            peerreviewstudio_expire_assignment($activity, $assignment, true);
            $requeued = true;
            continue;
        }

        if (
            $reminderdays > 0 && (int)$assignment->timereminded === 0
                && ($now - $clockstart) >= $reminderdays * DAYSECS
        ) {
            peerreviewstudio_send_notification($activity, 'reviewreminder', (int)$assignment->reviewerid);
            $DB->update_record('peerreviewstudio_assignments', (object) [
                'id' => $assignment->id,
                'timereminded' => $now,
                'timemodified' => $now,
            ]);
        }
    }

    if ($requeued && !empty($activity->autoassignenabled)) {
        peerreviewstudio_process_assignment_queue($activity);
    }

    peerreviewstudio_escalate_unresolved_submissions($activity);
}

// Reviews.

/**
 * Return review assignments for a reviewer in one activity.
 *
 * @param int $activityid Activity id.
 * @param int $reviewerid Reviewer user id.
 * @return array
 */
function peerreviewstudio_get_user_assignments(int $activityid, int $reviewerid): array {
    global $DB;

    return $DB->get_records('peerreviewstudio_assignments', [
        'peerreviewstudioid' => $activityid,
        'reviewerid' => $reviewerid,
    ], 'timeassigned ASC, id ASC');
}

/**
 * Return every reviewer assignment of one submission for the tutor view,
 * with the reviewer's name and the review (when one was submitted).
 *
 * @param int $submissionid Submission id.
 * @return stdClass[] Assignment rows; 'reviewid' is null while no review exists.
 */
function peerreviewstudio_get_submission_assignments(int $submissionid): array {
    global $DB;

    $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $sql = "SELECT a.id, a.reviewerid, a.status, a.timeassigned, a.timeopened, a.timecompleted,
                   r.id AS reviewid, r.grade, r.generalcomment, r.aireviewergrade, r.aireviewerfeedback,
                   {$namefields}
              FROM {peerreviewstudio_assignments} a
         LEFT JOIN {user} u ON u.id = a.reviewerid
         LEFT JOIN {peerreviewstudio_reviews} r ON r.assignmentid = a.id AND r.status = 'submitted'
             WHERE a.submissionid = :submissionid
          ORDER BY a.timeassigned ASC, a.id ASC";

    return $DB->get_records_sql($sql, ['submissionid' => $submissionid]);
}

/**
 * Record the first time a reviewer opened the work assigned to them.
 *
 * @param stdClass $assignment Assignment record.
 * @return void
 */
function peerreviewstudio_mark_assignment_opened(stdClass $assignment): void {
    global $DB;

    if ((int)$assignment->timeopened === 0 && (string)$assignment->status === 'assigned') {
        $assignment->timeopened = time();
        $DB->set_field('peerreviewstudio_assignments', 'timeopened', $assignment->timeopened, ['id' => $assignment->id]);
    }
}

/**
 * Return submitted reviews received for one submission.
 *
 * @param int $submissionid Submission id.
 * @return array
 */
function peerreviewstudio_get_received_reviews(int $submissionid): array {
    global $DB;

    $sql = "SELECT r.*
              FROM {peerreviewstudio_reviews} r
              JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
             WHERE a.submissionid = :submissionid
               AND r.status = 'submitted'
          ORDER BY r.timesubmitted ASC, r.id ASC";

    return $DB->get_records_sql($sql, ['submissionid' => $submissionid]);
}

/**
 * Return rubric answers grouped by review id.
 *
 * @param int[] $reviewids Review ids.
 * @return array
 */
function peerreviewstudio_get_rubric_answers_by_review(array $reviewids): array {
    global $DB;

    if (!$reviewids) {
        return [];
    }

    [$insql, $params] = $DB->get_in_or_equal($reviewids, SQL_PARAMS_NAMED);
    $sql = "SELECT a.id, a.reviewid, c.label AS criterionlabel, l.label AS levellabel, a.score, a.commenttext
              FROM {peerreviewstudio_r_answers} a
              JOIN {peerreviewstudio_r_criteria} c ON c.id = a.criterionid
              JOIN {peerreviewstudio_r_levels} l ON l.id = a.levelid
             WHERE a.reviewid $insql
          ORDER BY a.reviewid ASC, c.sortorder ASC, c.id ASC";
    $records = $DB->get_records_sql($sql, $params);
    $grouped = [];
    foreach ($records as $record) {
        $grouped[(int)$record->reviewid][] = $record;
    }

    return $grouped;
}

/**
 * Save a submitted review and update related states.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $assignment Assignment record.
 * @param int $reviewerid Reviewer user id.
 * @param string $generalcomment Review comment.
 * @param float $grade Review grade.
 * @param array $rubricanswers Rubric answer payloads to store with the review.
 * @return stdClass
 */
function peerreviewstudio_save_review(
    stdClass $activity,
    stdClass $assignment,
    int $reviewerid,
    string $generalcomment,
    float $grade,
    array $rubricanswers = [],
): stdClass {
    global $DB;

    $now = time();
    $grade = max(0.0, $grade);
    $grademaxsubmission = (float)($activity->grademaxsubmission ?? 0);
    if ($grademaxsubmission > 0) {
        $grade = min($grade, $grademaxsubmission);
    }

    $transaction = $DB->start_delegated_transaction();

    $record = new stdClass();
    $record->assignmentid = $assignment->id;
    $record->submissionid = $assignment->submissionid;
    $record->reviewerid = $reviewerid;
    $record->status = 'submitted';
    $record->generalcomment = trim($generalcomment);
    $record->grade = $grade;
    $record->timesubmitted = $now;
    $record->timemodified = $now;

    $existingid = $DB->get_field('peerreviewstudio_reviews', 'id', ['assignmentid' => $assignment->id]);
    if ($existingid) {
        $record->id = $existingid;
        $DB->update_record('peerreviewstudio_reviews', $record);
        $DB->delete_records('peerreviewstudio_r_answers', ['reviewid' => $record->id]);
    } else {
        $record->timecreated = $now;
        $record->id = $DB->insert_record('peerreviewstudio_reviews', $record);
    }

    foreach ($rubricanswers as $answer) {
        $DB->insert_record('peerreviewstudio_r_answers', (object) [
            'reviewid' => $record->id,
            'criterionid' => (int)$answer['criterionid'],
            'levelid' => (int)$answer['levelid'],
            'commenttext' => (string)($answer['commenttext'] ?? ''),
            'score' => (float)$answer['score'],
        ]);
    }

    $DB->update_record('peerreviewstudio_assignments', (object) [
        'id' => $assignment->id,
        'status' => 'completed',
        'timecompleted' => $now,
        'timemodified' => $now,
    ]);

    $status = peerreviewstudio_update_submission_status($activity, (int)$assignment->submissionid);
    if ($status === 'closed') {
        peerreviewstudio_close_queue_entries((int)$assignment->submissionid);
    }

    peerreviewstudio_refresh_submission_grade($activity, (int)$assignment->submissionid);
    $reviewersubmission = peerreviewstudio_get_user_submission((int)$activity->id, $reviewerid);
    if ($reviewersubmission) {
        peerreviewstudio_refresh_submission_grade($activity, (int)$reviewersubmission->id);
    }

    $transaction->allow_commit();

    $event = \mod_peerreviewstudio\event\review_submitted::create([
        'objectid' => $record->id,
        'context' => peerreviewstudio_get_activity_context((int)$activity->id),
        'userid' => $reviewerid,
    ]);
    $event->trigger();

    peerreviewstudio_notify_grade_released($activity, (int)$assignment->submissionid);
    peerreviewstudio_update_completion_state($activity, $reviewerid);

    if (\mod_peerreviewstudio\local\ai\client::instance()->is_enabled_for($activity)) {
        peerreviewstudio_queue_ai_review((int)$record->id);
    }

    return $DB->get_record('peerreviewstudio_reviews', ['id' => $record->id], '*', MUST_EXIST);
}

/**
 * Queue the AI second review of one peer review.
 *
 * @param int $reviewid Review id.
 * @return void
 */
function peerreviewstudio_queue_ai_review(int $reviewid): void {
    $task = new \mod_peerreviewstudio\task\ai_review_task();
    $task->set_custom_data(['reviewid' => $reviewid]);
    // A review that is already waiting in the queue is not queued twice.
    \core\task\manager::queue_adhoc_task($task, true);
}

/**
 * Queue the AI evaluations of a learner's work that have not been attempted.
 *
 * Content is only sent to the AI once the person who wrote it has accepted
 * the site AI policy, so reviews submitted before that are left waiting.
 * This picks them up: the learner's own reviews, and the reviews received by
 * the learner's submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $userid Learner who has accepted the AI policy.
 * @return int Number of reviews queued.
 */
function peerreviewstudio_queue_pending_ai_reviews(stdClass $activity, int $userid): int {
    global $DB;

    $client = \mod_peerreviewstudio\local\ai\client::instance();
    if (
        peerreviewstudio_get_current_phase($activity) === 'closed'
            || !$client->is_enabled_for($activity)
            || !$client->has_accepted_policy($userid)
    ) {
        return 0;
    }

    // An evaluation with a time and no grade was attempted and abandoned.
    $reviewids = $DB->get_fieldset_sql(
        "SELECT r.id
           FROM {peerreviewstudio_reviews} r
           JOIN {peerreviewstudio_submissions} s ON s.id = r.submissionid
          WHERE s.peerreviewstudioid = :activityid
            AND r.status = 'submitted'
            AND ((r.reviewerid = :reviewerid AND r.aireviewergrade IS NULL AND r.aitimegraded = 0)
                 OR (s.userid = :authorid AND s.aigrade IS NULL AND s.aitimegraded = 0))
       ORDER BY r.id",
        ['activityid' => $activity->id, 'reviewerid' => $userid, 'authorid' => $userid]
    );
    foreach ($reviewids as $reviewid) {
        peerreviewstudio_queue_ai_review((int)$reviewid);
    }

    return count($reviewids);
}

// Learner guidance.

/**
 * Work out what a learner should do next in the activity.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass|false $submission The learner's submission, or false when none.
 * @param int $openreviews Reviews assigned to the learner and not completed yet.
 * @param int $completedreviews Reviews the learner has completed.
 * @param bool $cansubmit Whether the learner may submit work.
 * @return string Key of the language string describing the next step; empty when there is none.
 */
function peerreviewstudio_learner_next_step(
    stdClass $activity,
    stdClass|false $submission,
    int $openreviews,
    int $completedreviews,
    bool $cansubmit
): string {
    if (!$submission) {
        if (!$cansubmit) {
            return '';
        }
        return peerreviewstudio_phase_allows_submissions($activity) ? 'nextstep_submit' : 'nextstep_submissionclosed';
    }

    if ($openreviews > 0) {
        return peerreviewstudio_phase_allows_reviews($activity) ? 'nextstep_reviewassigned' : 'nextstep_reviewsoon';
    }
    if (peerreviewstudio_phase_allows_feedback($activity, $submission)) {
        return 'nextstep_viewfeedback';
    }
    if ($completedreviews >= max(1, (int)($activity->reviewcountrequired ?? 1))) {
        return 'nextstep_waitforfeedback';
    }

    return 'nextstep_waitforpeer';
}

// Tutor dashboard.

/**
 * Count the submissions of an activity by workflow status.
 *
 * @param int $activityid Activity id.
 * @param int|null $groupid Group to restrict to; 0 for everybody, null for nobody.
 * @return array{total:int, waiting:int, inreview:int, complete:int}
 */
function peerreviewstudio_get_dashboard_counts(int $activityid, ?int $groupid = 0): array {
    global $DB;

    ['where' => $where, 'params' => $params] = peerreviewstudio_tutor_rows_where($activityid, '', '', $groupid);
    $bystatus = $DB->get_records_sql_menu(
        "SELECT s.status, COUNT(1)
           FROM {peerreviewstudio_submissions} s
           JOIN {user} u ON u.id = s.userid
          WHERE {$where}
       GROUP BY s.status",
        $params
    );

    $count = static fn(array $statuses): int => (int)array_sum(array_intersect_key($bystatus, array_flip($statuses)));

    return [
        'total' => (int)array_sum($bystatus),
        'waiting' => $count(['submitted', 'queued']),
        'inreview' => $count(['assigned', 'partiallyreviewed']),
        'complete' => $count(['closed']),
    ];
}

/**
 * Whether the current user may work with one learner's submission.
 *
 * In separate groups mode a tutor without access to every group only
 * reaches learners who share one of the tutor's groups.
 *
 * @param stdClass|cm_info $cm Course module.
 * @param context_module $context Module context.
 * @param int $ownerid Submission author user id.
 * @return bool
 */
function peerreviewstudio_can_access_submission_owner($cm, context_module $context, int $ownerid): bool {
    global $USER;

    if (
        groups_get_activity_groupmode($cm) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $context)
    ) {
        return true;
    }

    $mygroups = array_keys(groups_get_all_groups((int)$cm->course, (int)$USER->id, (int)$cm->groupingid, 'g.id'));
    $ownergroups = array_keys(groups_get_all_groups((int)$cm->course, $ownerid, (int)$cm->groupingid, 'g.id'));

    return (bool)array_intersect($mygroups, $ownergroups);
}

/**
 * Build the shared WHERE fragment and params for tutor dashboard queries.
 *
 * @param int $activityid Activity id.
 * @param string $statusfilter Optional submission status filter.
 * @param string $search Optional name (and, when allowed, email) search.
 * @param int|null $groupid Group to restrict to; 0 for everybody, null for nobody.
 * @param bool $searchemail Whether the search may also match email addresses.
 * @return array{where:string, params:array}
 */
function peerreviewstudio_tutor_rows_where(
    int $activityid,
    string $statusfilter,
    string $search,
    ?int $groupid = 0,
    bool $searchemail = false
): array {
    global $DB;

    $where = 's.peerreviewstudioid = :activityid AND u.deleted = 0';
    $params = ['activityid' => $activityid];

    if ($groupid === null) {
        $where .= ' AND 1 = 0';
    } else if ($groupid > 0) {
        $where .= ' AND EXISTS (SELECT 1
                                  FROM {groups_members} gmf
                                 WHERE gmf.groupid = :filtergroupid
                                   AND gmf.userid = s.userid)';
        $params['filtergroupid'] = $groupid;
    }

    if ($statusfilter !== '') {
        $where .= ' AND s.status = :statusfilter';
        $params['statusfilter'] = $statusfilter;
    }

    if ($search !== '') {
        $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
        $conditions = [$DB->sql_like($fullname, ':searchname', false, false)];
        $params['searchname'] = '%' . $DB->sql_like_escape($search) . '%';
        if ($searchemail) {
            $conditions[] = $DB->sql_like('u.email', ':searchemail', false, false);
            $params['searchemail'] = '%' . $DB->sql_like_escape($search) . '%';
        }
        $where .= ' AND (' . implode(' OR ', $conditions) . ')';
    }

    return ['where' => $where, 'params' => $params];
}

/**
 * Count the submissions matching the tutor dashboard filters.
 *
 * @param int $activityid Activity id.
 * @param string $statusfilter Optional submission status filter.
 * @param string $search Optional search.
 * @param int|null $groupid Group to restrict to; 0 for everybody, null for nobody.
 * @param bool $searchemail Whether the search may also match email addresses.
 * @return int
 */
function peerreviewstudio_count_tutor_submission_rows(
    int $activityid,
    string $statusfilter = '',
    string $search = '',
    ?int $groupid = 0,
    bool $searchemail = false
): int {
    global $DB;

    ['where' => $where, 'params' => $params] = peerreviewstudio_tutor_rows_where(
        $activityid,
        $statusfilter,
        $search,
        $groupid,
        $searchemail
    );
    $sql = "SELECT COUNT(1)
              FROM {peerreviewstudio_submissions} s
              JOIN {user} u ON u.id = s.userid
             WHERE {$where}";

    return (int)$DB->count_records_sql($sql, $params);
}

/**
 * Return tutor-facing submission rows with user and assignment counters.
 *
 * Filtering and pagination happen in SQL so that large cohorts never load
 * every submission.
 *
 * @param int $activityid Activity id.
 * @param string $statusfilter Optional submission status filter.
 * @param string $search Optional search.
 * @param int $page Zero-based page number.
 * @param int $perpage Rows per page (0 = no limit; avoid on large cohorts).
 * @param int[] $onlyids Restrict to these submission ids (empty = no restriction).
 * @param int|null $groupid Group to restrict to; 0 for everybody, null for nobody.
 * @param bool $searchemail Whether the search may also match email addresses.
 * @return array
 */
function peerreviewstudio_get_tutor_submission_rows(
    int $activityid,
    string $statusfilter = '',
    string $search = '',
    int $page = 0,
    int $perpage = 0,
    array $onlyids = [],
    ?int $groupid = 0,
    bool $searchemail = false
): array {
    global $DB;

    $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    ['where' => $where, 'params' => $params] = peerreviewstudio_tutor_rows_where(
        $activityid,
        $statusfilter,
        $search,
        $groupid,
        $searchemail
    );
    if ($onlyids) {
        [$idsql, $idparams] = $DB->get_in_or_equal(array_map('intval', $onlyids), SQL_PARAMS_NAMED, 'onlyid');
        $where .= " AND s.id {$idsql}";
        $params += $idparams;
    }

    $sql = "SELECT s.id,
                   s.userid,
                   s.status,
                   s.timesubmitted,
                   s.timemodified,
                   s.submissiongrade,
                   s.reviewgrade,
                   s.finalgrade,
                   s.teachergradeoverride,
                   s.aigrade,
                   s.timeescalated,
                   {$namefields},
                   u.email,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} ao
                     WHERE ao.submissionid = s.id
                       AND ao.status = 'assigned') AS openassignedcount,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_reviews} r
                      JOIN {peerreviewstudio_assignments} ax ON ax.id = r.assignmentid
                     WHERE ax.submissionid = s.id
                       AND r.status = 'submitted') AS completedcount,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_queue} q
                     WHERE q.submissionid = s.id
                       AND q.status = 'pending') AS pendingqueuecount,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} aw
                     WHERE aw.peerreviewstudioid = s.peerreviewstudioid
                       AND aw.reviewerid = s.userid
                       AND aw.status = 'assigned') AS workloadopen,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} ac
                     WHERE ac.peerreviewstudioid = s.peerreviewstudioid
                       AND ac.reviewerid = s.userid
                       AND ac.status = 'completed') AS workloadcompleted
              FROM {peerreviewstudio_submissions} s
              JOIN {user} u ON u.id = s.userid
             WHERE {$where}
          ORDER BY s.timesubmitted ASC, s.id ASC";

    $limitfrom = $perpage > 0 ? max(0, $page) * $perpage : 0;

    return $DB->get_records_sql($sql, $params, $limitfrom, $perpage);
}

/**
 * Return tutor incident rows for operational follow-up.
 *
 * Only submissions that can actually produce an incident are scanned,
 * capped at PEERREVIEWSTUDIO_MAX_INCIDENT_ROWS, so the incident panel
 * stays cheap on large cohorts. Incidents are:
 * - escalated: the automatic cycle gave up and a tutor must act;
 * - noreviewers: waiting for a reviewer and nobody is eligible;
 * - stuckqueue: waiting for a reviewer although candidates exist;
 * - unassigned: automatic matching is off and nobody was assigned.
 *
 * While matching has not started yet (for example during the scheduled
 * submission phase) waiting submissions are expected, not incidents.
 *
 * @param stdClass $activity Activity record.
 * @param int|null $groupid Group to restrict to; 0 for everybody, null for nobody.
 * @return array
 */
function peerreviewstudio_get_tutor_incident_rows(stdClass $activity, ?int $groupid = 0): array {
    global $DB;

    if ($groupid === null) {
        return [];
    }

    $matchingallowed = peerreviewstudio_matching_allowed($activity);
    $conditions = ["(s.timeescalated > 0 AND s.status <> 'closed' AND s.teachergradeoverride IS NULL)"];
    if ($matchingallowed) {
        $conditions[] = "EXISTS (SELECT 1
                                   FROM {peerreviewstudio_queue} q
                                  WHERE q.submissionid = s.id
                                    AND q.status = 'pending')";
        if (empty($activity->autoassignenabled)) {
            $conditions[] = "s.status = 'submitted'";
        }
    }

    $candidateids = $DB->get_fieldset_sql(
        "SELECT s.id
           FROM {peerreviewstudio_submissions} s
          WHERE s.peerreviewstudioid = :activityid
            AND s.userid > 0
            AND (" . implode(' OR ', $conditions) . ")
       ORDER BY s.timesubmitted ASC, s.id ASC",
        ['activityid' => $activity->id],
        0,
        PEERREVIEWSTUDIO_MAX_INCIDENT_ROWS
    );
    if (!$candidateids) {
        return [];
    }

    $rows = peerreviewstudio_get_tutor_submission_rows((int)$activity->id, '', '', 0, 0, $candidateids, $groupid);
    $incidents = [];
    foreach ($rows as $row) {
        $candidates = peerreviewstudio_get_reviewer_candidates($activity, (object) [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
        ]);
        $row->eligiblecount = count($candidates['eligible']);
        $row->ineligiblecount = count($candidates['ineligible']);
        $row->ineligiblesummary = peerreviewstudio_ineligible_candidates_summary($candidates['ineligible']);

        if ((int)$row->timeescalated > 0 && (string)$row->status !== 'closed') {
            $row->incidenttype = 'escalated';
        } else if ((int)$row->pendingqueuecount > 0) {
            $row->incidenttype = $row->eligiblecount ? 'stuckqueue' : 'noreviewers';
        } else {
            $row->incidenttype = 'unassigned';
        }
        $incidents[] = $row;
    }

    return $incidents;
}

// Data removal.

/**
 * Delete every piece of learner data stored for one activity.
 *
 * Used when the activity is deleted, when the course is reset, and when
 * the privacy API purges a context. The rubric definition is kept.
 *
 * @param int $activityid Activity id.
 * @param context|null $context Module context, to remove the submission files too.
 * @return void
 */
function peerreviewstudio_delete_activity_user_data(int $activityid, ?context $context = null): void {
    global $DB;

    $params = ['activityid' => $activityid];
    $assignmentsql = "SELECT a.id
                        FROM {peerreviewstudio_assignments} a
                       WHERE a.peerreviewstudioid = :activityid";

    $DB->delete_records_select(
        'peerreviewstudio_r_answers',
        "reviewid IN (SELECT r.id
                        FROM {peerreviewstudio_reviews} r
                       WHERE r.assignmentid IN ($assignmentsql))",
        $params
    );
    $DB->delete_records_select('peerreviewstudio_reviews', "assignmentid IN ($assignmentsql)", $params);
    $DB->delete_records('peerreviewstudio_assignments', ['peerreviewstudioid' => $activityid]);
    $DB->delete_records('peerreviewstudio_queue', ['peerreviewstudioid' => $activityid]);
    $DB->delete_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $activityid]);

    if ($context) {
        get_file_storage()->delete_area_files($context->id, 'mod_peerreviewstudio', 'submission_files');
    }
}
