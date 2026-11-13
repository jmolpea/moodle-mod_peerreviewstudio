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

namespace mod_peerreviewstudio\privacy;

use context;
use context_module;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by the plugin.
     *
     * @param collection $collection Metadata collection.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('peerreviewstudio_submissions', [
            'userid' => 'privacy:metadata:peerreviewstudio_submissions:userid',
            'status' => 'privacy:metadata:peerreviewstudio_submissions:status',
            'onlinetext' => 'privacy:metadata:peerreviewstudio_submissions:onlinetext',
            'timesubmitted' => 'privacy:metadata:peerreviewstudio_submissions:timesubmitted',
            'submissiongrade' => 'privacy:metadata:peerreviewstudio_submissions:submissiongrade',
            'reviewgrade' => 'privacy:metadata:peerreviewstudio_submissions:reviewgrade',
            'finalgrade' => 'privacy:metadata:peerreviewstudio_submissions:finalgrade',
            'teachergradeoverride' => 'privacy:metadata:peerreviewstudio_submissions:teachergradeoverride',
            'aigrade' => 'privacy:metadata:peerreviewstudio_submissions:aigrade',
            'aifeedback' => 'privacy:metadata:peerreviewstudio_submissions:aifeedback',
        ], 'privacy:metadata:peerreviewstudio_submissions');
        $collection->add_database_table('peerreviewstudio_assignments', [
            'reviewerid' => 'privacy:metadata:peerreviewstudio_assignments:reviewerid',
            'status' => 'privacy:metadata:peerreviewstudio_assignments:status',
            'timeassigned' => 'privacy:metadata:peerreviewstudio_assignments:timeassigned',
            'timeopened' => 'privacy:metadata:peerreviewstudio_assignments:timeopened',
            'timereminded' => 'privacy:metadata:peerreviewstudio_assignments:timereminded',
            'timecompleted' => 'privacy:metadata:peerreviewstudio_assignments:timecompleted',
        ], 'privacy:metadata:peerreviewstudio_assignments');
        $collection->add_database_table('peerreviewstudio_reviews', [
            'reviewerid' => 'privacy:metadata:peerreviewstudio_reviews:reviewerid',
            'status' => 'privacy:metadata:peerreviewstudio_reviews:status',
            'generalcomment' => 'privacy:metadata:peerreviewstudio_reviews:generalcomment',
            'grade' => 'privacy:metadata:peerreviewstudio_reviews:grade',
            'timesubmitted' => 'privacy:metadata:peerreviewstudio_reviews:timesubmitted',
            'aireviewergrade' => 'privacy:metadata:peerreviewstudio_reviews:aireviewergrade',
            'aireviewerfeedback' => 'privacy:metadata:peerreviewstudio_reviews:aireviewerfeedback',
        ], 'privacy:metadata:peerreviewstudio_reviews');
        $collection->add_database_table('peerreviewstudio_r_answers', [
            'commenttext' => 'privacy:metadata:peerreviewstudio_r_answers:commenttext',
            'score' => 'privacy:metadata:peerreviewstudio_r_answers:score',
        ], 'privacy:metadata:peerreviewstudio_r_answers');
        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:core_files');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        // Optional AI second review: when a teacher enables it for an
        // activity, the content below is passed to the Moodle AI subsystem,
        // which sends it to the site's AI provider and keeps a record of the
        // request for the user who wrote the content. The plugin never adds
        // user identities (names, emails) to that content.
        $collection->add_subsystem_link('core_ai', [
            'activitycontext' => 'privacy:metadata:core_ai:activitycontext',
            'submissioncontent' => 'privacy:metadata:core_ai:submissioncontent',
            'reviewcontent' => 'privacy:metadata:core_ai:reviewcontent',
        ], 'privacy:metadata:core_ai');
        return $collection;
    }

    /**
     * Return the contexts holding data for one user.
     *
     * @param int $userid User id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'peerreviewstudio',
            'userid' => $userid,
        ];
        $from = "FROM {context} ctx
                 JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                 JOIN {modules} m ON m.id = cm.module AND m.name = :modname";

        // Submissions the user owns.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               $from
               JOIN {peerreviewstudio_submissions} s ON s.peerreviewstudioid = cm.instance
              WHERE s.userid = :userid",
            $params
        );
        // Reviews assigned to, or written by, the user.
        $contextlist->add_from_sql(
            "SELECT ctx.id
               $from
               JOIN {peerreviewstudio_assignments} a ON a.peerreviewstudioid = cm.instance
              WHERE a.reviewerid = :userid",
            $params
        );

        return $contextlist;
    }

    /**
     * Return the users holding data in one context.
     *
     * @param userlist $userlist Userlist to fill.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $activityid = self::context_to_activityid($userlist->get_context());
        if ($activityid === null) {
            return;
        }

        $params = ['activityid' => $activityid];
        $userlist->add_from_sql(
            'userid',
            "SELECT userid FROM {peerreviewstudio_submissions} WHERE peerreviewstudioid = :activityid AND userid > 0",
            $params
        );
        $userlist->add_from_sql(
            'reviewerid',
            "SELECT reviewerid FROM {peerreviewstudio_assignments} WHERE peerreviewstudioid = :activityid AND reviewerid > 0",
            $params
        );
    }

    /**
     * Export all user data in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $activityid = self::context_to_activityid($context);
            if ($activityid === null) {
                continue;
            }

            $data = \core_privacy\local\request\helper::get_context_data($context, $contextlist->get_user());

            // The user's own submission and the feedback it received. The
            // identity of the reviewers is never part of the author's export.
            $submissions = $DB->get_records('peerreviewstudio_submissions', [
                'peerreviewstudioid' => $activityid,
                'userid' => $userid,
            ]);
            $data->submissions = [];
            foreach ($submissions as $submission) {
                $received = $DB->get_records_sql(
                    "SELECT r.*
                       FROM {peerreviewstudio_reviews} r
                       JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
                      WHERE a.submissionid = :submissionid
                        AND r.status = 'submitted'
                   ORDER BY r.timesubmitted ASC, r.id ASC",
                    ['submissionid' => $submission->id]
                );
                $data->submissions[] = (object) [
                    'status' => $submission->status,
                    'onlinetext' => format_text((string)$submission->onlinetext, (int)$submission->onlinetextformat, [
                        'context' => $context,
                    ]),
                    'timesubmitted' => transform::datetime((int)$submission->timesubmitted),
                    'submissiongrade' => $submission->submissiongrade,
                    'reviewgrade' => $submission->reviewgrade,
                    'finalgrade' => $submission->finalgrade,
                    'teachergradeoverride' => $submission->teachergradeoverride,
                    'aigrade' => $submission->aigrade,
                    'aifeedback' => $submission->aifeedback,
                    'feedbackreceived' => array_values(array_map([self::class, 'export_review'], $received)),
                ];
                writer::with_context($context)->export_area_files(
                    [],
                    'mod_peerreviewstudio',
                    'submission_files',
                    (int)$submission->id
                );
            }

            // The reviewing work the user did for others.
            $assignments = $DB->get_records('peerreviewstudio_assignments', [
                'peerreviewstudioid' => $activityid,
                'reviewerid' => $userid,
            ], 'timeassigned ASC, id ASC');
            $data->reviewsassigned = [];
            foreach ($assignments as $assignment) {
                $review = $DB->get_record('peerreviewstudio_reviews', ['assignmentid' => $assignment->id]);
                $data->reviewsassigned[] = (object) [
                    'status' => $assignment->status,
                    'timeassigned' => transform::datetime((int)$assignment->timeassigned),
                    'timeopened' => $assignment->timeopened ? transform::datetime((int)$assignment->timeopened) : null,
                    'timereminded' => $assignment->timereminded ? transform::datetime((int)$assignment->timereminded) : null,
                    'timecompleted' => $assignment->timecompleted ? transform::datetime((int)$assignment->timecompleted) : null,
                    'review' => $review ? self::export_review($review, true) : null,
                ];
            }

            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Shape one review for export.
     *
     * @param \stdClass $review Review record.
     * @param bool $own Whether the exporting user wrote the review (adds the AI assessment of it).
     * @return \stdClass
     */
    protected static function export_review(\stdClass $review, bool $own = false): \stdClass {
        global $DB;

        $answers = $DB->get_records_sql(
            "SELECT ans.id, c.label AS criterionlabel, l.label AS levellabel, ans.score, ans.commenttext
               FROM {peerreviewstudio_r_answers} ans
               JOIN {peerreviewstudio_r_criteria} c ON c.id = ans.criterionid
               JOIN {peerreviewstudio_r_levels} l ON l.id = ans.levelid
              WHERE ans.reviewid = :reviewid
           ORDER BY c.sortorder ASC, c.id ASC",
            ['reviewid' => $review->id]
        );

        $export = (object) [
            'grade' => $review->grade,
            'generalcomment' => $review->generalcomment,
            'timesubmitted' => transform::datetime((int)$review->timesubmitted),
            'rubricanswers' => array_values(array_map(static fn($answer) => (object) [
                'criterion' => $answer->criterionlabel,
                'level' => $answer->levellabel,
                'score' => $answer->score,
                'comment' => $answer->commenttext,
            ], $answers)),
        ];
        if ($own) {
            $export->aireviewergrade = $review->aireviewergrade;
            $export->aireviewerfeedback = $review->aireviewerfeedback;
        }

        return $export;
    }

    /**
     * Delete all user data in one context.
     *
     * @param context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $activityid = self::context_to_activityid($context);
        if ($activityid === null) {
            return;
        }

        \peerreviewstudio_delete_activity_user_data($activityid, $context);
    }

    /**
     * Delete data for one user in the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        foreach ($contextlist->get_contexts() as $context) {
            $activityid = self::context_to_activityid($context);
            if ($activityid === null) {
                continue;
            }
            self::delete_user_data_in_activity($activityid, $context, (int)$contextlist->get_user()->id);
        }
    }

    /**
     * Delete data for several users in one context.
     *
     * @param approved_userlist $userlist Approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        $activityid = self::context_to_activityid($context);
        if ($activityid === null) {
            return;
        }
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_data_in_activity($activityid, $context, (int)$userid);
        }
    }

    /**
     * Delete or anonymise one user's data in one activity.
     *
     * The user's own work is erased. What the user wrote about other
     * people's work, and what others wrote about the user's work, is
     * stripped of identity and free text but the rows are kept, so the
     * grades of the other participants do not change.
     *
     * @param int $activityid Activity id.
     * @param context $context Module context.
     * @param int $userid User id.
     * @return void
     */
    protected static function delete_user_data_in_activity(int $activityid, context $context, int $userid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $activity = $DB->get_record('peerreviewstudio', ['id' => $activityid]);
        if (!$activity || $userid <= 0) {
            return;
        }
        $now = time();

        // 1. The user as author: erase the submission content and files, and
        // stop anybody from being asked to review it.
        $submissions = $DB->get_records('peerreviewstudio_submissions', [
            'peerreviewstudioid' => $activityid,
            'userid' => $userid,
        ]);
        foreach ($submissions as $submission) {
            $DB->update_record('peerreviewstudio_submissions', (object) [
                'id' => $submission->id,
                'userid' => 0,
                'onlinetext' => '',
                'onlinetextformat' => FORMAT_HTML,
                'submissiongrade' => null,
                'reviewgrade' => null,
                'finalgrade' => null,
                'teachergradeoverride' => null,
                'aigrade' => null,
                'aifeedback' => '',
                'timemodified' => $now,
            ]);
            get_file_storage()->delete_area_files($context->id, 'mod_peerreviewstudio', 'submission_files', (int)$submission->id);
            $DB->set_field_select(
                'peerreviewstudio_assignments',
                'status',
                'cancelled',
                "submissionid = :submissionid AND status = 'assigned'",
                ['submissionid' => $submission->id]
            );
            \peerreviewstudio_close_queue_entries((int)$submission->id, 'failed');
        }

        // 2. The user as reviewer: strip identity and free text from the
        // reviews, and hand the reviews still pending back to the matching.
        $assignments = $DB->get_records('peerreviewstudio_assignments', [
            'peerreviewstudioid' => $activityid,
            'reviewerid' => $userid,
        ]);
        foreach ($assignments as $assignment) {
            $reviewids = $DB->get_fieldset_select(
                'peerreviewstudio_reviews',
                'id',
                'assignmentid = :assignmentid',
                ['assignmentid' => $assignment->id]
            );
            foreach ($reviewids as $reviewid) {
                $DB->set_field('peerreviewstudio_r_answers', 'commenttext', '', ['reviewid' => $reviewid]);
                $DB->update_record('peerreviewstudio_reviews', (object) [
                    'id' => $reviewid,
                    'reviewerid' => 0,
                    'generalcomment' => '',
                    'aireviewergrade' => null,
                    'aireviewerfeedback' => '',
                    'timemodified' => $now,
                ]);
            }

            $wasopen = (string)$assignment->status === 'assigned';
            $DB->update_record('peerreviewstudio_assignments', (object) [
                'id' => $assignment->id,
                'reviewerid' => 0,
                'status' => $wasopen ? 'cancelled' : $assignment->status,
                'timemodified' => $now,
            ]);
            if ($wasopen) {
                if (
                    !empty($activity->autoassignenabled)
                        && \peerreviewstudio_needed_assignments($activity, (int)$assignment->submissionid) > 0
                ) {
                    \peerreviewstudio_queue_submission($activity, (int)$assignment->submissionid);
                }
                \peerreviewstudio_update_submission_status($activity, (int)$assignment->submissionid);
            }
        }
    }

    /**
     * Resolve activity id from a module context.
     *
     * @param context $context Context.
     * @return int|null
     */
    protected static function context_to_activityid(context $context): ?int {
        if (!$context instanceof context_module) {
            return null;
        }
        $cm = get_coursemodule_from_id('peerreviewstudio', $context->instanceid);
        if (!$cm) {
            return null;
        }
        return (int)$cm->instance;
    }
}
