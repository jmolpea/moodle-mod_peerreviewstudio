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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../group/lib.php');

/**
 * Maximum number of files accepted per submission.
 */
const PEERREVIEWSTUDIO_MAXFILES = 20;

/**
 * Maximum automatic reassignments per submission before escalating.
 */
const PEERREVIEWSTUDIO_MAX_AUTO_REASSIGNMENTS = 3;

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
 * Return a default rubric definition preview for author guidance.
 *
 * @return string
 */
function peerreviewstudio_default_rubric_preview(): string {
    $lines = [];
    foreach (peerreviewstudio_default_rubric_definition() as $criterion) {
        $lines[] = '- ' . $criterion['label'];
    }
    return implode("\n", $lines);
}

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

    return [
        [
            'label' => get_string('defaultrubric_criterion1', 'mod_peerreviewstudio'),
            'description' => get_string('defaultrubric_criterion1_desc', 'mod_peerreviewstudio'),
            'weight' => 1,
            'levels' => $levels,
        ],
        [
            'label' => get_string('defaultrubric_criterion2', 'mod_peerreviewstudio'),
            'description' => get_string('defaultrubric_criterion2_desc', 'mod_peerreviewstudio'),
            'weight' => 1,
            'levels' => $levels,
        ],
        [
            'label' => get_string('defaultrubric_criterion3', 'mod_peerreviewstudio'),
            'description' => get_string('defaultrubric_criterion3_desc', 'mod_peerreviewstudio'),
            'weight' => 1,
            'levels' => $levels,
        ],
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
 * Return group ids for a user in one course module course.
 *
 * @param stdClass $course Course record.
 * @param int $userid User id.
 * @return int[]
 */
function peerreviewstudio_get_user_group_ids(stdClass $course, int $userid): array {
    $groups = groups_get_all_groups((int)$course->id, $userid);
    return array_map('intval', array_keys($groups ?: []));
}

/**
 * Return eligibility result for one candidate reviewer.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @param stdClass $candidate Candidate submission row.
 * @param int[] $authorgroups Author group ids.
 * @return array{eligible:bool,reasons:array}
 */
function peerreviewstudio_evaluate_reviewer_candidate(
    stdClass $activity,
    stdClass $submission,
    stdClass $candidate,
    array $authorgroups
): array {
    global $DB;

    $reasons = [];
    if ((int)$candidate->userid === (int)$submission->userid) {
        $reasons[] = 'self';
    }

    $activestatuses = ['submitted', 'queued', 'assigned', 'partiallyreviewed', 'reviewed', 'closed'];
    if (!in_array((string)$candidate->status, $activestatuses, true)) {
        $reasons[] = 'candidateinactive';
    }

    $alreadyassigned = $DB->record_exists('peerreviewstudio_assignments', [
        'submissionid' => $submission->id,
        'reviewerid' => $candidate->userid,
    ]);
    if ($alreadyassigned) {
        $reasons[] = 'alreadyassigned';
    }

    if (!empty($activity->avoidreciprocalreviews) && $DB->record_exists('peerreviewstudio_assignments', [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $candidate->id,
        'reviewerid' => $submission->userid,
    ])) {
        $reasons[] = 'reciprocal';
    }

    $poolmode = (string)($activity->reviewpoolmode ?? 'any');
    if ($poolmode !== 'any') {
        $candidategroups = peerreviewstudio_get_user_group_ids((object)['id' => $activity->course], (int)$candidate->userid);
        $shared = array_intersect($authorgroups, $candidategroups);
        if ($poolmode === 'samegroup' && !$shared) {
            $reasons[] = 'groupmismatch';
        }
        if ($poolmode === 'differentgroup' && $shared) {
            $reasons[] = 'samegroupblocked';
        }
    }

    return ['eligible' => !$reasons, 'reasons' => $reasons];
}

/**
 * Human-readable reason labels for ineligible reviewer candidates.
 *
 * @param array $reasons Reason keys.
 * @return string[]
 */
function peerreviewstudio_eligibility_reason_labels(array $reasons): array {
    $labels = [];
    foreach ($reasons as $reason) {
        $key = 'eligibilityreason_' . $reason;
        if (get_string_manager()->string_exists($key, 'mod_peerreviewstudio')) {
            $labels[] = get_string($key, 'mod_peerreviewstudio');
        } else {
            $labels[] = $reason;
        }
    }
    return $labels;
}

/**
 * Summarise blocked candidate reasons for tutor dashboards.
 *
 * @param array $candidates Ineligible candidate rows.
 * @return string
 */
function peerreviewstudio_ineligible_candidates_summary(array $candidates): string {
    $reasoncounts = [];
    foreach ($candidates as $candidate) {
        foreach (($candidate->reasonlabels ?? []) as $label) {
            $label = (string)$label;
            $reasoncounts[$label] = ($reasoncounts[$label] ?? 0) + 1;
        }
    }

    if (!$reasoncounts) {
        return '';
    }

    arsort($reasoncounts);
    $parts = [];
    foreach ($reasoncounts as $label => $count) {
        $parts[] = $label . ' (' . $count . ')';
        if (count($parts) >= 3) {
            break;
        }
    }

    return implode(', ', $parts);
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
    $timeopen = (int)($activity->timeopen ?? 0);
    $timeclose = (int)($activity->timeclose ?? 0);
    $timereviewdue = (int)($activity->timereviewdue ?? 0);

    if ($timeopen && $now < $timeopen) {
        return 'submission';
    }
    if (!$timeclose || $now <= $timeclose) {
        return 'submission';
    }
    if ($timeclose && $now > $timeclose && (!$timereviewdue || $now <= $timereviewdue)) {
        return 'review';
    }
    if ($timereviewdue && $now > $timereviewdue) {
        return 'feedback';
    }

    return 'closed';
}

/**
 * Whether the current phase allows submissions.
 *
 * Open mode never closes submissions: a rolling, self-paced activity
 * (e.g. an OER course) must accept work at any time, regardless of the
 * calendar.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_phase_allows_submissions(stdClass $activity): bool {
    if ((string)($activity->workflowmode ?? 'scheduled') === 'open') {
        return true;
    }
    return peerreviewstudio_get_current_phase($activity) === 'submission';
}

/**
 * Whether the current phase allows reviews.
 *
 * Open mode always allows reviewing: whether a specific learner actually
 * has something to review is already decided by whether they hold an
 * 'assigned' assignment (checked separately by the caller), not by a
 * shared activity-wide phase.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_phase_allows_reviews(stdClass $activity): bool {
    if ((string)($activity->workflowmode ?? 'scheduled') === 'open') {
        return true;
    }
    return in_array(peerreviewstudio_get_current_phase($activity), ['allocation', 'review'], true);
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
 * Return a phase label.
 *
 * @param string $phase Phase key.
 * @return string
 */
function peerreviewstudio_phase_label(string $phase): string {
    if ($phase === 'ongoing') {
        return get_string('phase_ongoing', 'mod_peerreviewstudio');
    }
    $options = peerreviewstudio_workflow_phase_options();
    return $options[$phase] ?? $phase;
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
 * Ensure the activity has at least one minimal rubric.
 *
 * @param int $activityid Activity id.
 * @return void
 */
function peerreviewstudio_ensure_default_rubric(int $activityid): void {
    global $DB;

    if ($DB->record_exists('peerreviewstudio_rubrics', ['peerreviewstudioid' => $activityid])) {
        return;
    }

    $now = time();
    $rubric = (object) [
        'peerreviewstudioid' => $activityid,
        'name' => get_string('defaultrubricname', 'mod_peerreviewstudio'),
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $rubricid = $DB->insert_record('peerreviewstudio_rubrics', $rubric);

    foreach (peerreviewstudio_default_rubric_definition() as $criterionindex => $criterion) {
        $criterionrecord = (object) [
            'rubricid' => $rubricid,
            'label' => $criterion['label'],
            'description' => $criterion['description'],
            'weight' => (float)$criterion['weight'],
            'sortorder' => $criterionindex,
        ];
        $criterionid = $DB->insert_record('peerreviewstudio_r_criteria', $criterionrecord);

        foreach ($criterion['levels'] as $levelindex => $level) {
            $levelrecord = (object) [
                'criterionid' => $criterionid,
                'label' => $level['label'],
                'score' => (float)$level['score'],
                'sortorder' => $levelindex,
            ];
            $DB->insert_record('peerreviewstudio_r_levels', $levelrecord);
        }
    }
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
        $rubric->timemodified = $now;
        $DB->update_record('peerreviewstudio_rubrics', $rubric);
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
 * Return the minimal rubric structure for one activity.
 *
 * @param int $activityid Activity id.
 * @return array
 */
function peerreviewstudio_get_activity_rubric(int $activityid): array {
    global $DB;

    peerreviewstudio_ensure_default_rubric($activityid);
    $rubric = $DB->get_record('peerreviewstudio_rubrics', ['peerreviewstudioid' => $activityid], '*', MUST_EXIST);
    $criteria = $DB->get_records('peerreviewstudio_r_criteria', ['rubricid' => $rubric->id], 'sortorder ASC, id ASC');
    $result = [];
    foreach ($criteria as $criterion) {
        $levels = $DB->get_records('peerreviewstudio_r_levels', ['criterionid' => $criterion->id], 'sortorder ASC, id ASC');
        $result[] = [
            'id' => (int)$criterion->id,
            'label' => (string)$criterion->label,
            'description' => (string)$criterion->description,
            'weight' => (float)$criterion->weight,
            'levels' => array_map(static function($level): array {
                return [
                    'id' => (int)$level->id,
                    'label' => (string)$level->label,
                    'score' => (float)$level->score,
                ];
            }, array_values($levels)),
        ];
    }

    return $result;
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
 * Return the current user submission for an activity.
 *
 * @param int $activityid Activity id.
 * @param int $userid User id.
 * @return stdClass|false
 */
function peerreviewstudio_get_user_submission(int $activityid, int $userid): stdClass|false {
    global $DB;

    return $DB->get_record('peerreviewstudio_submissions', [
        'peerreviewstudioid' => $activityid,
        'userid' => $userid,
    ]);
}

/**
 * Whether submissions are open right now.
 *
 * @param stdClass $activity Activity record.
 * @return bool
 */
function peerreviewstudio_submissions_open(stdClass $activity): bool {
    if ((string)($activity->workflowmode ?? 'scheduled') === 'open') {
        return true;
    }
    $now = time();
    if (!empty($activity->timeopen) && $now < (int)$activity->timeopen) {
        return false;
    }
    if (!empty($activity->timeclose) && $now > (int)$activity->timeclose) {
        return false;
    }
    return true;
}

/**
 * Calculate automatic submission grade from received submitted reviews.
 *
 * Each submitted review stores the grade it proposes for the submission
 * (already in submission grade space), so the automatic submission grade
 * is the average of the received proposals, capped at the maximum.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return float
 */
function peerreviewstudio_calculate_submission_grade(stdClass $activity, int $submissionid): float {
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
    if ($average === false || $average === null) {
        // AI fallback: when a submission never received any peer review and
        // the fallback is enabled, the AI grade (0-100) stands in fully so
        // the learner is not punished with a zero for peers' inaction. As
        // soon as a real peer review arrives, the normal peer-average path
        // below takes over again.
        if (!empty($activity->aireviewenabled) && !empty($activity->aifallbackenabled)) {
            $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $submissionid]);
            if ($submission && $submission->aigrade !== null) {
                return round(min($submissionmax, max(0.0, ((float)$submission->aigrade / 100.0) * $submissionmax)), 2);
            }
        }
        return 0.0;
    }
    $peergrade = min($submissionmax, max(0.0, (float)$average));

    // Optional AI second review: blend the peer average with the AI grade
    // (stored as 0-100) using the configured weight. The blend only kicks
    // in once the AI result exists, so grades never wait on the AI call.
    if (!empty($activity->aireviewenabled)) {
        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $submissionid]);
        if ($submission && $submission->aigrade !== null) {
            $weight = min(100.0, max(0.0, (float)($activity->aigradeweight ?? 0))) / 100.0;
            $aigrade = ((float)$submission->aigrade / 100.0) * $submissionmax;
            $peergrade = (1 - $weight) * $peergrade + $weight * $aigrade;
        }
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

    $completed = $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {peerreviewstudio_reviews} r
          WHERE r.reviewerid = :reviewerid
            AND r.submissionid IN (
                SELECT s.id FROM {peerreviewstudio_submissions} s WHERE s.peerreviewstudioid = :activityid
            )
            AND r.status = 'submitted'",
        ['reviewerid' => $userid, 'activityid' => $activity->id]
    );
    $required = max(1, (int)($activity->reviewcountrequired ?? 1));
    $reviewmax = (float)($activity->grademaxreview ?? 0);

    if ($reviewmax <= 0) {
        return 0.0;
    }

    $grade = peerreviewstudio_calculate_review_completion_grade((int)$completed, $required, $reviewmax);

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
 * @param stdClass $activity Activity record.
 * @param string $rawvalue Raw request value.
 * @return float|null
 */
function peerreviewstudio_normalize_teacher_grade_override(stdClass $activity, string $rawvalue): ?float {
    $rawvalue = trim($rawvalue);
    if ($rawvalue === '') {
        return null;
    }

    if (!is_numeric($rawvalue)) {
        throw new invalid_parameter_exception('Invalid override grade value.');
    }

    $value = (float)$rawvalue;
    if (!is_finite($value)) {
        throw new invalid_parameter_exception('Invalid override grade value.');
    }

    $maxgrade = max(0.0, (float)($activity->grademaxsubmission ?? 0) + (float)($activity->grademaxreview ?? 0));
    $value = max(0.0, $value);
    if ($maxgrade > 0) {
        $value = min($maxgrade, $value);
    }

    return round($value, 2);
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
    $finalgrade = $submissiongrade + $reviewgrade;

    $submission->submissiongrade = $submissiongrade;
    $submission->reviewgrade = $reviewgrade;
    $submission->finalgrade = $submission->teachergradeoverride !== null
        ? (float)$submission->teachergradeoverride
        : $finalgrade;
    $submission->timemodified = time();
    $DB->update_record('peerreviewstudio_submissions', $submission);

    peerreviewstudio_grade_user_update($activity, (int)$submission->userid, (float)$submission->finalgrade);
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
    $submission->teachergradeoverride = $override;
    $submission->timemodified = time();
    $DB->update_record('peerreviewstudio_submissions', $submission);

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

    if ($override !== null) {
        peerreviewstudio_send_notification($activity, 'gradereceived', (int)$submission->userid);
    }
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
    if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
        return;
    }

    $activityname = format_string($activity->name);
    if ($a === null) {
        $a = $activityname;
    }
    $stringmanager = get_string_manager();
    $subject = $stringmanager->get_string(
        'message_' . $providername . '_subject', 'mod_peerreviewstudio', $a, $user->lang
    );
    $body = $stringmanager->get_string(
        'message_' . $providername . '_body', 'mod_peerreviewstudio', $a, $user->lang
    );

    $cm = get_coursemodule_from_instance('peerreviewstudio', (int)$activity->id, (int)$activity->course, false, IGNORE_MISSING);

    $message = new \core\message\message();
    $message->component = 'mod_peerreviewstudio';
    $message->name = $providername;
    $message->userfrom = \core_user::get_noreply_user();
    $message->userto = $user;
    $message->subject = $subject;
    $message->fullmessage = $body;
    $message->fullmessageformat = FORMAT_PLAIN;
    $message->fullmessagehtml = text_to_html($body, false, false, true);
    $message->smallmessage = $subject;
    $message->notification = 1;
    $message->courseid = (int)$activity->course;
    if ($cm) {
        $url = new moodle_url('/mod/peerreviewstudio/view.php', ['id' => (int)$cm->id]);
        $message->contexturl = $url->out(false);
        $message->contexturlname = $activityname;
    }

    message_send($message);
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

    $now = time();
    $submission = peerreviewstudio_get_user_submission((int)$activity->id, $userid);
    $record = new stdClass();
    $record->peerreviewstudioid = $activity->id;
    $record->userid = $userid;
    $record->onlinetext = trim((string)($editordata['text'] ?? ''));
    $record->onlinetextformat = (int)($editordata['format'] ?? FORMAT_HTML);
    $record->timesubmitted = $now;
    $record->timemodified = $now;

    if ($submission) {
        // Editing an existing submission keeps its workflow status so open
        // assignments and completed reviews are not disturbed.
        $record->id = $submission->id;
        $record->status = $submission->status;
        $record->timecreated = $submission->timecreated;
        $DB->update_record('peerreviewstudio_submissions', $record);
    } else {
        $record->status = !empty($activity->autoassignenabled) ? 'queued' : 'submitted';
        $record->timecreated = $now;
        $record->id = $DB->insert_record('peerreviewstudio_submissions', $record);
        $event = \mod_peerreviewstudio\event\submission_created::create([
            'objectid' => $record->id,
            'context' => peerreviewstudio_get_activity_context((int)$activity->id),
            'userid' => $userid,
        ]);
        $event->trigger();

        peerreviewstudio_send_notification($activity, 'submissionreceived', $userid);
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
                'accepted_types' => '*',
            ]
        );
    }

    if (!empty($activity->autoassignenabled)) {
        if (peerreviewstudio_needed_assignments($activity, (int)$record->id) > 0) {
            peerreviewstudio_queue_submission($activity, (int)$record->id, 'awaiting_reviewer');
        }
        peerreviewstudio_process_assignment_queue($activity);
    }

    peerreviewstudio_refresh_submission_grade($activity, (int)$record->id);

    return $DB->get_record('peerreviewstudio_submissions', ['id' => $record->id], '*', MUST_EXIST);
}

/**
 * Count open assignments plus submitted reviews for one submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @return int
 */
function peerreviewstudio_count_review_capacity(stdClass $activity, int $submissionid): int {
    global $DB;

    $open = $DB->count_records('peerreviewstudio_assignments', [
        'submissionid' => $submissionid,
        'status' => 'assigned',
    ]);
    $completed = $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {peerreviewstudio_reviews} r
           JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
          WHERE a.submissionid = :submissionid
            AND r.status = 'submitted'",
        ['submissionid' => $submissionid]
    );

    return (int)$open + (int)$completed;
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

    return max(0, $target - peerreviewstudio_count_review_capacity($activity, $submissionid));
}

/**
 * Queue a submission for later assignment processing.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @param string $queuetype Queue type.
 * @return void
 */
function peerreviewstudio_queue_submission(stdClass $activity, int $submissionid, string $queuetype): void {
    global $DB;

    $existing = $DB->get_record('peerreviewstudio_queue', [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submissionid,
        'queuetype' => $queuetype,
        'status' => 'pending',
    ]);
    if ($existing) {
        return;
    }

    $now = time();
    $queue = (object) [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submissionid,
        'queuetype' => $queuetype,
        'status' => 'pending',
        'attempts' => 0,
        'lastattemptat' => 0,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $DB->insert_record('peerreviewstudio_queue', $queue);
}

/**
 * Process pending queue entries and create assignments up to the
 * configured reviews-received target for each queued submission.
 *
 * @param stdClass $activity Activity record.
 * @return void
 */
function peerreviewstudio_process_assignment_queue(stdClass $activity): void {
    global $DB;

    $queueentries = $DB->get_records('peerreviewstudio_queue', [
        'peerreviewstudioid' => $activity->id,
        'status' => 'pending',
    ], 'timecreated ASC, id ASC');

    foreach ($queueentries as $queueentry) {
        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $queueentry->submissionid], '*', IGNORE_MISSING);
        if (!$submission) {
            $queueentry->status = 'failed';
            $queueentry->timemodified = time();
            $DB->update_record('peerreviewstudio_queue', $queueentry);
            continue;
        }

        $queueentry->attempts = (int)$queueentry->attempts + 1;
        $queueentry->lastattemptat = time();
        $queueentry->timemodified = time();

        $needed = peerreviewstudio_needed_assignments($activity, (int)$submission->id);
        $assignedsomeone = false;
        while ($needed > 0) {
            $reviewer = peerreviewstudio_find_reviewer_for_submission($activity, $submission);
            if (!$reviewer) {
                break;
            }

            $assignment = (object) [
                'peerreviewstudioid' => $activity->id,
                'submissionid' => $submission->id,
                'reviewerid' => $reviewer->userid,
                'status' => 'assigned',
                'timeassigned' => time(),
                'timeopened' => 0,
                'timecompleted' => 0,
                'timemodified' => time(),
            ];
            $DB->insert_record('peerreviewstudio_assignments', $assignment);
            $assignedsomeone = true;
            $needed--;

            peerreviewstudio_send_notification($activity, 'reviewassigned', (int)$reviewer->userid);

            $reviewersubmission = $DB->get_record('peerreviewstudio_submissions', [
                'peerreviewstudioid' => $activity->id,
                'userid' => $reviewer->userid,
            ]);
            if ($reviewersubmission && in_array((string)$reviewersubmission->status, ['submitted', 'queued'], true)) {
                $reviewersubmission->status = 'assigned';
                $reviewersubmission->timemodified = time();
                $DB->update_record('peerreviewstudio_submissions', $reviewersubmission);
            }
        }

        if ($assignedsomeone && in_array((string)$submission->status, ['submitted', 'queued'], true)) {
            $submission->status = 'assigned';
            $submission->timemodified = time();
            $DB->update_record('peerreviewstudio_submissions', $submission);
        }

        if ($needed <= 0) {
            $queueentry->status = 'resolved';
        }
        $DB->update_record('peerreviewstudio_queue', $queueentry);
    }
}

/**
 * Find a simple eligible reviewer for a submission.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return stdClass|false
 */
function peerreviewstudio_find_reviewer_for_submission(stdClass $activity, stdClass $submission): stdClass|false {
    $reviewers = peerreviewstudio_get_eligible_reviewers_for_submission($activity, $submission);
    if (!$reviewers) {
        return false;
    }

    return reset($reviewers);
}

/**
 * Return eligible reviewers for one submission ordered by current load.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return array
 */
function peerreviewstudio_get_eligible_reviewers_for_submission(stdClass $activity, stdClass $submission): array {
    global $DB;

    $authorgroups = peerreviewstudio_get_user_group_ids((object)['id' => $activity->course], (int)$submission->userid);
    $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

    $sql = "SELECT s.id, s.userid, s.status, s.timesubmitted, {$namefields}, COUNT(a.id) AS assignmentcount
              FROM {peerreviewstudio_submissions} s
              JOIN {user} u ON u.id = s.userid
         LEFT JOIN {peerreviewstudio_assignments} a ON a.reviewerid = s.userid
               AND a.peerreviewstudioid = s.peerreviewstudioid
             WHERE s.peerreviewstudioid = :activityid
               AND s.userid <> :userid
               AND s.status IN ('submitted', 'queued', 'assigned', 'partiallyreviewed', 'reviewed', 'closed')
               AND NOT EXISTS (
                    SELECT 1
                      FROM {peerreviewstudio_assignments} ax
                     WHERE ax.submissionid = :submissionid
                       AND ax.reviewerid = s.userid
               )
          GROUP BY s.id, s.userid, s.status, s.timesubmitted, {$namefields}
          ORDER BY assignmentcount ASC, s.timesubmitted ASC, s.id ASC";

    $candidates = $DB->get_records_sql($sql, [
        'activityid' => $activity->id,
        'userid' => $submission->userid,
        'submissionid' => $submission->id,
    ]);

    $eligible = [];
    foreach ($candidates as $candidate) {
        $evaluation = peerreviewstudio_evaluate_reviewer_candidate($activity, $submission, $candidate, $authorgroups);
        if ($evaluation['eligible']) {
            $eligible[] = $candidate;
        }
    }

    return $eligible;
}

/**
 * Return rejected reviewer candidates with reasons.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return array
 */
function peerreviewstudio_get_ineligible_reviewers_for_submission(stdClass $activity, stdClass $submission): array {
    global $DB;

    $authorgroups = peerreviewstudio_get_user_group_ids((object)['id' => $activity->course], (int)$submission->userid);
    $sql = "SELECT s.id, s.userid, s.status, u.firstname, u.lastname
              FROM {peerreviewstudio_submissions} s
              JOIN {user} u ON u.id = s.userid
             WHERE s.peerreviewstudioid = :activityid
               AND s.userid <> :userid
          ORDER BY s.timesubmitted ASC, s.id ASC";
    $candidates = $DB->get_records_sql($sql, [
        'activityid' => $activity->id,
        'userid' => $submission->userid,
    ]);
    $rejected = [];
    foreach ($candidates as $candidate) {
        $evaluation = peerreviewstudio_evaluate_reviewer_candidate($activity, $submission, $candidate, $authorgroups);
        if ($evaluation['eligible']) {
            continue;
        }
        $candidate->reasonlabels = peerreviewstudio_eligibility_reason_labels($evaluation['reasons']);
        $rejected[] = $candidate;
    }

    return $rejected;
}

/**
 * Manually assign a concrete reviewer to one submission.
 *
 * @param stdClass $activity Activity record.
 * @param int $submissionid Submission id.
 * @param int $reviewerid Reviewer user id.
 * @return bool
 */
function peerreviewstudio_assign_submission_to_reviewer(stdClass $activity, int $submissionid, int $reviewerid): bool {
    global $DB, $USER;

    $submission = $DB->get_record('peerreviewstudio_submissions', [
        'id' => $submissionid,
        'peerreviewstudioid' => $activity->id,
    ], '*', MUST_EXIST);

    if ((int)$submission->userid === $reviewerid) {
        return false;
    }

    $eligible = peerreviewstudio_get_eligible_reviewers_for_submission($activity, $submission);
    $selected = null;
    foreach ($eligible as $candidate) {
        if ((int)$candidate->userid === $reviewerid) {
            $selected = $candidate;
            break;
        }
    }
    if (!$selected) {
        return false;
    }

    $now = time();
    $assignment = (object) [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submission->id,
        'reviewerid' => $reviewerid,
        'status' => 'assigned',
        'timeassigned' => $now,
        'timeopened' => 0,
        'timecompleted' => 0,
        'timemodified' => $now,
    ];
    $DB->insert_record('peerreviewstudio_assignments', $assignment);

    peerreviewstudio_send_notification($activity, 'reviewassigned', $reviewerid);

    // Only move forward from pre-review states; a submission that already
    // received reviews keeps its progress status.
    if (in_array((string)$submission->status, ['submitted', 'queued'], true)) {
        $submission->status = 'assigned';
    }
    $submission->timemodified = $now;
    $DB->update_record('peerreviewstudio_submissions', $submission);

    $reviewersubmission = peerreviewstudio_get_user_submission((int)$activity->id, $reviewerid);
    if ($reviewersubmission && in_array((string)$reviewersubmission->status, ['submitted', 'queued'], true)) {
        $reviewersubmission->status = 'assigned';
        $reviewersubmission->timemodified = $now;
        $DB->update_record('peerreviewstudio_submissions', $reviewersubmission);
    }

    $pendingqueue = $DB->get_records('peerreviewstudio_queue', [
        'peerreviewstudioid' => $activity->id,
        'submissionid' => $submissionid,
        'status' => 'pending',
    ]);
    foreach ($pendingqueue as $queueentry) {
        $queueentry->status = 'resolved';
        $queueentry->timemodified = $now;
        $DB->update_record('peerreviewstudio_queue', $queueentry);
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
 * Derive tutor incident rows from already-computed dashboard rows.
 *
 * @param array $rows Rows produced by peerreviewstudio_get_tutor_submission_rows().
 * @return array
 */
function peerreviewstudio_derive_incident_rows(array $rows): array {
    $incidents = [];
    foreach ($rows as $row) {
        $incidenttype = '';
        if ((int)$row->pendingqueuecount > 0 && empty($row->eligiblereviewers)) {
            $incidenttype = 'noreviewers';
        } else if ((int)$row->pendingqueuecount > 0) {
            $incidenttype = 'stuckqueue';
        } else if (in_array((string)$row->status, ['submitted', 'queued'], true)
                && !(int)$row->openassignedcount && !(int)$row->completedcount) {
            $incidenttype = 'unassigned';
        }

        if ($incidenttype === '') {
            continue;
        }

        $incident = clone $row;
        $incident->incidenttype = $incidenttype;
        $incidents[] = $incident;
    }

    return $incidents;
}

/**
 * Maximum incident rows shown on the tutor dashboard per load.
 */
const PEERREVIEWSTUDIO_MAX_INCIDENT_ROWS = 20;

/**
 * Return tutor incident rows for operational follow-up.
 *
 * Only submissions that can actually produce an incident (pending queue
 * entries, or waiting without any assignment) are scanned, capped at
 * PEERREVIEWSTUDIO_MAX_INCIDENT_ROWS, so the incident panel stays cheap
 * on large cohorts.
 *
 * @param int $activityid Activity id.
 * @return array
 */
function peerreviewstudio_get_tutor_incident_rows(int $activityid): array {
    global $DB;

    $candidateids = $DB->get_fieldset_sql(
        "SELECT s.id
           FROM {peerreviewstudio_submissions} s
          WHERE s.peerreviewstudioid = :activityid
            AND (EXISTS (SELECT 1
                           FROM {peerreviewstudio_queue} q
                          WHERE q.submissionid = s.id
                            AND q.status = 'pending')
                 OR (s.status IN ('submitted', 'queued')
                     AND NOT EXISTS (SELECT 1
                                       FROM {peerreviewstudio_assignments} a
                                      WHERE a.submissionid = s.id)))
       ORDER BY s.timesubmitted ASC, s.id ASC",
        ['activityid' => $activityid],
        0,
        PEERREVIEWSTUDIO_MAX_INCIDENT_ROWS
    );
    if (!$candidateids) {
        return [];
    }

    $rows = peerreviewstudio_get_tutor_submission_rows($activityid, '', '', 0, 0, $candidateids);

    return peerreviewstudio_derive_incident_rows($rows);
}

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
    ], 'timeassigned ASC');
}

/**
 * Return submitted reviews received for one submission.
 *
 * @param int $submissionid Submission id.
 * @return array
 */
function peerreviewstudio_get_received_reviews(int $submissionid): array {
    global $DB;

    $sql = "SELECT r.*, a.reviewerid
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

    $draftitemid = 0;
    file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'mod_peerreviewstudio',
        'submission_files',
        $submission ? (int)$submission->id : null,
        [
            'subdirs' => 0,
            'maxfiles' => PEERREVIEWSTUDIO_MAXFILES,
            'accepted_types' => '*',
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
 * Build the shared WHERE fragment and params for tutor dashboard queries.
 *
 * @param int $activityid Activity id.
 * @param string $statusfilter Optional submission status filter.
 * @param string $search Optional name/email search.
 * @return array{where:string, params:array}
 */
function peerreviewstudio_tutor_rows_where(int $activityid, string $statusfilter, string $search): array {
    global $DB;

    $where = 's.peerreviewstudioid = :activityid';
    $params = ['activityid' => $activityid];

    if ($statusfilter !== '') {
        $where .= ' AND s.status = :statusfilter';
        $params['statusfilter'] = $statusfilter;
    }

    if ($search !== '') {
        $fullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $where .= ' AND (' . $DB->sql_like($fullname, ':searchname', false)
            . ' OR ' . $DB->sql_like('u.email', ':searchemail', false) . ')';
        $params['searchname'] = '%' . $DB->sql_like_escape($search) . '%';
        $params['searchemail'] = '%' . $DB->sql_like_escape($search) . '%';
    }

    return ['where' => $where, 'params' => $params];
}

/**
 * Count the submissions matching the tutor dashboard filters.
 *
 * @param int $activityid Activity id.
 * @param string $statusfilter Optional submission status filter.
 * @param string $search Optional name/email search.
 * @return int
 */
function peerreviewstudio_count_tutor_submission_rows(int $activityid, string $statusfilter = '', string $search = ''): int {
    global $DB;

    ['where' => $where, 'params' => $params] = peerreviewstudio_tutor_rows_where($activityid, $statusfilter, $search);
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
 * every submission, and the expensive per-row reviewer eligibility scan
 * only ever runs for the rows on the current page.
 *
 * @param int $activityid Activity id.
 * @param string $statusfilter Optional submission status filter.
 * @param string $search Optional name/email search.
 * @param int $page Zero-based page number.
 * @param int $perpage Rows per page (0 = no limit; avoid on large cohorts).
 * @param int[] $onlyids Restrict to these submission ids (empty = no restriction).
 * @return array
 */
function peerreviewstudio_get_tutor_submission_rows(
    int $activityid,
    string $statusfilter = '',
    string $search = '',
    int $page = 0,
    int $perpage = 0,
    array $onlyids = []
): array {
    global $DB;

    $activity = $DB->get_record('peerreviewstudio', ['id' => $activityid], '*', MUST_EXIST);
    $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    ['where' => $where, 'params' => $params] = peerreviewstudio_tutor_rows_where($activityid, $statusfilter, $search);
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
                   {$namefields},
                   u.email,
                   (SELECT COUNT(1)
                      FROM {peerreviewstudio_assignments} a
                     WHERE a.submissionid = s.id) AS assignedcount,
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
    $rows = $DB->get_records_sql($sql, $params, $limitfrom, $perpage);
    foreach ($rows as $row) {
        $submission = (object) [
            'id' => (int)$row->id,
            'userid' => (int)$row->userid,
            'status' => (string)$row->status,
        ];
        $ineligible = peerreviewstudio_get_ineligible_reviewers_for_submission($activity, $submission);
        $row->eligiblereviewers = peerreviewstudio_get_eligible_reviewers_for_submission($activity, $submission);
        $row->eligiblecount = count($row->eligiblereviewers);
        $row->ineligiblecount = count($ineligible);
        $row->ineligiblesummary = peerreviewstudio_ineligible_candidates_summary($ineligible);
    }

    return $rows;
}

/**
 * Requeue a submission for another assignment attempt.
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
        $reviewersubmission = peerreviewstudio_get_user_submission((int)$activity->id, (int)$assignment->reviewerid);
        $assignment->status = 'cancelled';
        $assignment->timemodified = time();
        $DB->update_record('peerreviewstudio_assignments', $assignment);

        if ($reviewersubmission) {
            $remaining = $DB->count_records('peerreviewstudio_assignments', [
                'peerreviewstudioid' => $activity->id,
                'reviewerid' => $assignment->reviewerid,
                'status' => 'assigned',
            ]);
            if ($remaining === 0 && (string)$reviewersubmission->status === 'assigned') {
                $reviewersubmission->status = 'submitted';
                $reviewersubmission->timemodified = time();
                $DB->update_record('peerreviewstudio_submissions', $reviewersubmission);
            }
        }
    }

    $submission->status = 'queued';
    // A manual requeue is a fresh start: allow the automatic follow-up
    // cycle (reassignment, fallback, escalation) to run again.
    $submission->timeescalated = 0;
    $submission->timemodified = time();
    $DB->update_record('peerreviewstudio_submissions', $submission);

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

    peerreviewstudio_queue_submission($activity, $submissionid, 'awaiting_reviewer');
    peerreviewstudio_process_assignment_queue($activity);
}

/**
 * Whether the review window is definitively over for one activity.
 *
 * Only scheduled mode has an irreversible review deadline. Manual mode
 * phases can be moved back by the teacher and open mode never ends, so
 * neither is treated as "over" — their unresolved reviews are handled by
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

/**
 * Cancel one open review assignment that will not be completed.
 *
 * The reviewer is notified their task was withdrawn, their own submission
 * status is downgraded if this was their last open review, and (when
 * reassignment is requested) the submission is queued again unless the
 * automatic reassignment cap has been reached.
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $assignment Open assignment record.
 * @param bool $tryreassign Whether to queue the submission for reassignment.
 * @return void
 */
function peerreviewstudio_expire_assignment(stdClass $activity, stdClass $assignment, bool $tryreassign): void {
    global $DB;

    $now = time();
    $assignment->status = 'cancelled';
    $assignment->timemodified = $now;
    $DB->update_record('peerreviewstudio_assignments', $assignment);

    $reviewersubmission = peerreviewstudio_get_user_submission((int)$activity->id, (int)$assignment->reviewerid);
    if ($reviewersubmission) {
        $remaining = $DB->count_records('peerreviewstudio_assignments', [
            'peerreviewstudioid' => $activity->id,
            'reviewerid' => $assignment->reviewerid,
            'status' => 'assigned',
        ]);
        if ($remaining === 0 && (string)$reviewersubmission->status === 'assigned') {
            $reviewersubmission->status = 'submitted';
            $reviewersubmission->timemodified = $now;
            $DB->update_record('peerreviewstudio_submissions', $reviewersubmission);
        }
    }

    peerreviewstudio_send_notification($activity, 'reviewwithdrawn', (int)$assignment->reviewerid);

    if ($tryreassign && !empty($activity->autoassignenabled)) {
        $cancelled = $DB->count_records('peerreviewstudio_assignments', [
            'submissionid' => $assignment->submissionid,
            'status' => 'cancelled',
        ]);
        if ($cancelled <= PEERREVIEWSTUDIO_MAX_AUTO_REASSIGNMENTS
                && peerreviewstudio_needed_assignments($activity, (int)$assignment->submissionid) > 0) {
            peerreviewstudio_queue_submission($activity, (int)$assignment->submissionid, 'awaiting_reviewer');
        }
    }
}

/**
 * Resolve one submission that automatic reassignment could not help.
 *
 * Preferred remedy is the optional AI fallback review (grade plus
 * feedback with no human involved); when that is not available the tutors
 * are notified so they can intervene. Either way the submission is marked
 * as escalated so this runs at most once (a manual requeue resets it).
 *
 * @param stdClass $activity Activity record.
 * @param stdClass $submission Submission record.
 * @return void
 */
function peerreviewstudio_escalate_submission(stdClass $activity, stdClass $submission): void {
    global $DB;

    $now = time();
    $submission->timeescalated = $now;
    $submission->timemodified = $now;
    $DB->update_record('peerreviewstudio_submissions', $submission);

    if (!empty($activity->aireviewenabled) && !empty($activity->aifallbackenabled)
            && \mod_peerreviewstudio\local\ai\client::is_configured()) {
        $task = new \mod_peerreviewstudio\task\ai_fallback_review_task();
        $task->set_custom_data(['submissionid' => (int)$submission->id]);
        \core\task\manager::queue_adhoc_task($task);
        return;
    }

    $context = peerreviewstudio_get_activity_context((int)$activity->id);
    $author = \core_user::get_user((int)$submission->userid);
    $a = (object) [
        'name' => format_string($activity->name),
        'student' => $author ? fullname($author) : '',
    ];
    $tutors = get_users_by_capability($context, 'mod/peerreviewstudio:manageassignments', 'u.id');
    foreach ($tutors as $tutor) {
        peerreviewstudio_send_notification($activity, 'tutorescalation', (int)$tutor->id, $a);
    }
}

/**
 * Escalate submissions with no feedback and no automatic remedy left.
 *
 * A submission qualifies when it has received zero submitted reviews, has
 * no open assignment in flight, was not escalated before, and one of the
 * dead ends applies: the scheduled review window is over, the automatic
 * reassignment cap was reached, or its queue entry has been pending for
 * longer than the reassignment threshold without ever finding a reviewer.
 *
 * @param stdClass $activity Activity record.
 * @return void
 */
function peerreviewstudio_escalate_unresolved_submissions(stdClass $activity): void {
    global $DB;

    $now = time();
    $reassigndays = max(0, (int)($activity->reviewreassigndays ?? 0));
    $windowover = peerreviewstudio_review_window_over($activity);

    $sql = "SELECT s.*
              FROM {peerreviewstudio_submissions} s
             WHERE s.peerreviewstudioid = :activityid
               AND s.timeescalated = 0
               AND s.status <> 'closed'
               AND NOT EXISTS (SELECT 1
                                 FROM {peerreviewstudio_assignments} ao
                                WHERE ao.submissionid = s.id
                                  AND ao.status = 'assigned')
               AND NOT EXISTS (SELECT 1
                                 FROM {peerreviewstudio_reviews} r
                                 JOIN {peerreviewstudio_assignments} ar ON ar.id = r.assignmentid
                                WHERE ar.submissionid = s.id
                                  AND r.status = 'submitted')";
    $candidates = $DB->get_records_sql($sql, ['activityid' => $activity->id]);

    foreach ($candidates as $submission) {
        $capreached = $DB->count_records('peerreviewstudio_assignments', [
            'submissionid' => $submission->id,
            'status' => 'cancelled',
        ]) >= PEERREVIEWSTUDIO_MAX_AUTO_REASSIGNMENTS;

        $queuestuck = false;
        if ($reassigndays > 0) {
            $oldestpending = $DB->get_field_sql(
                "SELECT MIN(timecreated)
                   FROM {peerreviewstudio_queue}
                  WHERE submissionid = :submissionid
                    AND status = 'pending'",
                ['submissionid' => $submission->id]
            );
            $queuestuck = $oldestpending && ($now - (int)$oldestpending) >= $reassigndays * DAYSECS;
        }

        if (!$windowover && !$capreached && !$queuestuck) {
            continue;
        }

        peerreviewstudio_escalate_submission($activity, $submission);
    }
}

/**
 * Run the automatic follow-up cycle for unresolved reviews.
 *
 * Three layers, all opt-out via the per-activity day thresholds:
 * reminders to inactive reviewers, expiry plus reassignment of stale
 * assignments, and escalation (AI fallback review or tutor notification)
 * of submissions nothing else could resolve. The inactivity clocks only
 * run while reviewing is actually possible, and in scheduled mode they
 * start at the review phase opening (timeclose), not at assignment time.
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

    $openassignments = $DB->get_records('peerreviewstudio_assignments', [
        'peerreviewstudioid' => $activity->id,
        'status' => 'assigned',
    ], 'timeassigned ASC, id ASC');

    $requeued = false;
    foreach ($openassignments as $assignment) {
        if ($windowover) {
            // The review deadline passed: this assignment can never be
            // completed anymore, so clear it out (no reassignment).
            peerreviewstudio_expire_assignment($activity, $assignment, false);
            continue;
        }
        if (!$reviewsallowed) {
            // Reviewing is not possible yet (e.g. scheduled submission
            // phase), so reviewers are not inactive: clocks do not run.
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

        if ($reminderdays > 0 && (int)$assignment->timereminded === 0
                && ($now - $clockstart) >= $reminderdays * DAYSECS) {
            peerreviewstudio_send_notification($activity, 'reviewreminder', (int)$assignment->reviewerid);
            $assignment->timereminded = $now;
            $assignment->timemodified = $now;
            $DB->update_record('peerreviewstudio_assignments', $assignment);
        }
    }

    if ($requeued && !empty($activity->autoassignenabled)) {
        peerreviewstudio_process_assignment_queue($activity);
    }

    peerreviewstudio_escalate_unresolved_submissions($activity);
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
    $review = $DB->get_record('peerreviewstudio_reviews', [
        'assignmentid' => $assignment->id,
        'reviewerid' => $reviewerid,
    ]);

    $grade = max(0.0, $grade);
    $grademaxsubmission = (float)($activity->grademaxsubmission ?? 0);
    if ($grademaxsubmission > 0) {
        $grade = min($grade, $grademaxsubmission);
    }

    $record = new stdClass();
    $record->assignmentid = $assignment->id;
    $record->submissionid = $assignment->submissionid;
    $record->reviewerid = $reviewerid;
    $record->status = 'submitted';
    $record->generalcomment = trim($generalcomment);
    $record->grade = $grade;
    $record->timesubmitted = $now;
    $record->timemodified = $now;

    if ($review) {
        $record->id = $review->id;
        $DB->update_record('peerreviewstudio_reviews', $record);
        $DB->delete_records('peerreviewstudio_r_answers', ['reviewid' => $record->id]);
    } else {
        $record->timecreated = $now;
        $record->id = $DB->insert_record('peerreviewstudio_reviews', $record);
    }

    foreach ($rubricanswers as $answer) {
        $answerrecord = (object) [
            'reviewid' => $record->id,
            'criterionid' => (int)$answer['criterionid'],
            'levelid' => (int)$answer['levelid'],
            'commenttext' => (string)($answer['commenttext'] ?? ''),
            'score' => (float)$answer['score'],
        ];
        $DB->insert_record('peerreviewstudio_r_answers', $answerrecord);
    }

    $assignment->status = 'completed';
    $assignment->timecompleted = $now;
    $assignment->timemodified = $now;
    $DB->update_record('peerreviewstudio_assignments', $assignment);

    $reviewersubmission = peerreviewstudio_get_user_submission((int)$activity->id, $reviewerid);
    if ($reviewersubmission && (string)$reviewersubmission->status !== 'closed') {
        // A 'closed' submission already received all its reviews; do not
        // downgrade it because its owner completed reviewing work.
        $remaining = $DB->count_records('peerreviewstudio_assignments', [
            'peerreviewstudioid' => $activity->id,
            'reviewerid' => $reviewerid,
            'status' => 'assigned',
        ]);
        $reviewersubmission->status = $remaining > 0 ? 'partiallyreviewed' : 'reviewed';
        $reviewersubmission->timemodified = $now;
        $DB->update_record('peerreviewstudio_submissions', $reviewersubmission);
    }

    $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $assignment->submissionid], '*', MUST_EXIST);
    $completedreviews = $DB->count_records_sql(
        "SELECT COUNT(r.id)
           FROM {peerreviewstudio_reviews} r
           JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
          WHERE a.submissionid = :submissionid
            AND r.status = 'submitted'",
        ['submissionid' => $submission->id],
    );

    if ($completedreviews >= (int)$activity->reviewsreceivedtarget) {
        $submission->status = 'closed';
    } else if ($completedreviews > 0) {
        $submission->status = 'partiallyreviewed';
    } else if (!empty($activity->autoassignenabled)) {
        $submission->status = 'assigned';
    }
    $submission->timemodified = $now;
    $DB->update_record('peerreviewstudio_submissions', $submission);

    peerreviewstudio_refresh_submission_grade($activity, (int)$assignment->submissionid);
    $reviewersubmission = peerreviewstudio_get_user_submission((int)$activity->id, $reviewerid);
    if ($reviewersubmission) {
        peerreviewstudio_refresh_submission_grade($activity, (int)$reviewersubmission->id);
    }

    $event = \mod_peerreviewstudio\event\review_submitted::create([
        'objectid' => $record->id,
        'context' => peerreviewstudio_get_activity_context((int)$activity->id),
        'userid' => $reviewerid,
    ]);
    $event->trigger();

    peerreviewstudio_send_notification($activity, 'gradereceived', (int)$submission->userid);

    if (!empty($activity->aireviewenabled) && \mod_peerreviewstudio\local\ai\client::is_configured()) {
        $aitask = new \mod_peerreviewstudio\task\ai_review_task();
        $aitask->set_custom_data(['reviewid' => (int)$record->id]);
        \core\task\manager::queue_adhoc_task($aitask);
    }

    return $DB->get_record('peerreviewstudio_reviews', ['id' => $record->id], '*', MUST_EXIST);
}
