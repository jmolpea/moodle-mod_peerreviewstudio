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
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

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
        // Optional AI second review: when a teacher enables it for an
        // activity, the content below is sent to the external AI provider
        // configured site-wide. No user identities (names, emails, ids)
        // are ever included in that payload.
        $collection->add_external_location_link('aiprovider', [
            'activitycontext' => 'privacy:metadata:aiprovider:activitycontext',
            'submissioncontent' => 'privacy:metadata:aiprovider:submissioncontent',
            'reviewcontent' => 'privacy:metadata:aiprovider:reviewcontent',
        ], 'privacy:metadata:aiprovider');
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
        $sql = "SELECT DISTINCT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {peerreviewstudio} prs ON prs.id = cm.instance
             LEFT JOIN {peerreviewstudio_submissions} s ON s.peerreviewstudioid = prs.id AND s.userid = :userid1
             LEFT JOIN {peerreviewstudio_assignments} a ON a.peerreviewstudioid = prs.id AND a.reviewerid = :userid2
             LEFT JOIN {peerreviewstudio_reviews} r ON r.assignmentid = a.id AND r.reviewerid = :userid3
                 WHERE s.id IS NOT NULL OR a.id IS NOT NULL OR r.id IS NOT NULL";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'peerreviewstudio',
            'userid1' => $userid,
            'userid2' => $userid,
            'userid3' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Return the users holding data in one context.
     *
     * @param userlist $userlist Userlist to fill.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        $activityid = self::context_to_activityid($context);
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
        $userlist->add_from_sql(
            'reviewerid',
            "SELECT r.reviewerid
               FROM {peerreviewstudio_reviews} r
               JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
              WHERE a.peerreviewstudioid = :activityid AND r.reviewerid > 0",
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

        $userid = $contextlist->get_userid();
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('peerreviewstudio', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $activity = $DB->get_record('peerreviewstudio', ['id' => $cm->instance], '*', MUST_EXIST);
            $submissions = array_values($DB->get_records('peerreviewstudio_submissions', [
                'peerreviewstudioid' => $activity->id,
                'userid' => $userid,
            ]));
            $assignments = array_values($DB->get_records('peerreviewstudio_assignments', [
                'peerreviewstudioid' => $activity->id,
                'reviewerid' => $userid,
            ]));
            $reviews = [];
            $answersbyreview = [];
            foreach ($assignments as $assignment) {
                $review = $DB->get_record('peerreviewstudio_reviews', [
                    'assignmentid' => $assignment->id,
                    'reviewerid' => $userid,
                ]);
                if ($review) {
                    $reviews[] = $review;
                    $answersbyreview[$review->id] = array_values(
                        $DB->get_records('peerreviewstudio_r_answers', ['reviewid' => $review->id])
                    );
                }
            }

            $receivedfeedback = [];
            $receivedanswers = [];
            if ($submissions) {
                $submissionids = array_map(static fn($submission) => (int)$submission->id, $submissions);
                [$submissionidsql, $submissionparams] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);
                $receivedsql = "SELECT r.*, a.submissionid AS receivedsubmissionid
                                  FROM {peerreviewstudio_reviews} r
                                  JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
                                 WHERE a.submissionid $submissionidsql
                                   AND r.status = 'submitted'
                              ORDER BY r.timesubmitted ASC, r.id ASC";
                $receivedfeedback = array_values($DB->get_records_sql($receivedsql, $submissionparams));
                foreach ($receivedfeedback as $review) {
                    // Never expose reviewer identities inside the author-facing export payload.
                    unset($review->reviewerid);
                    $receivedanswers[$review->id] = array_values(
                        $DB->get_records('peerreviewstudio_r_answers', ['reviewid' => $review->id])
                    );
                }
            }

            writer::with_context($context)->export_data([], (object) [
                'activityname' => format_string($activity->name, true, ['context' => $context]),
                'submissionownership' => (object) [
                    'submissions' => $submissions,
                ],
                'reviewwork' => (object) [
                    'assignments' => $assignments,
                    'reviews' => $reviews,
                    'rubricanswers' => $answersbyreview,
                ],
                'feedbackreceived' => (object) [
                    'reviews' => $receivedfeedback,
                    'rubricanswers' => $receivedanswers,
                ],
            ]);

            foreach ($submissions as $submission) {
                writer::with_context($context)->export_area_files(
                    [],
                    'mod_peerreviewstudio',
                    'submission_files',
                    (int)$submission->id
                );
            }
        }
    }

    /**
     * Delete all user data in one context.
     *
     * @param context $context Context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        $activityid = self::context_to_activityid($context);
        if ($activityid === null) {
            return;
        }

        self::purge_activity_user_data($activityid, $context);
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
            self::delete_user_data_in_activity($activityid, $context, $contextlist->get_userid());
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
            self::delete_user_data_in_activity($activityid, $context, $userid);
        }
    }

    /**
     * Delete or anonymise one user's data in one activity.
     *
     * @param int $activityid Activity id.
     * @param context $context Module context.
     * @param int $userid User id.
     * @return void
     */
    protected static function delete_user_data_in_activity(int $activityid, context $context, int $userid): void {
        self::anonymise_submission_owner_data($activityid, $context, $userid);
        self::anonymise_reviewer_data($activityid, $userid);
    }

    /**
     * Purge all user-generated data for one activity context.
     *
     * @param int $activityid Activity id.
     * @param context $context Module context.
     * @return void
     */
    protected static function purge_activity_user_data(int $activityid, context $context): void {
        global $DB;

        $submissions = $DB->get_records('peerreviewstudio_submissions', ['peerreviewstudioid' => $activityid], '', 'id');
        foreach ($submissions as $submission) {
            self::delete_submission_tree((int)$submission->id, $context);
        }

        $assignmentids = $DB->get_fieldset_select(
            'peerreviewstudio_assignments',
            'id',
            'peerreviewstudioid = :activityid',
            ['activityid' => $activityid]
        );
        if ($assignmentids) {
            [$insql, $params] = $DB->get_in_or_equal($assignmentids, SQL_PARAMS_NAMED);
            $reviewids = $DB->get_fieldset_select('peerreviewstudio_reviews', 'id', 'assignmentid ' . $insql, $params);
            if ($reviewids) {
                [$reviewinsql, $reviewparams] = $DB->get_in_or_equal($reviewids, SQL_PARAMS_NAMED);
                $DB->delete_records_select('peerreviewstudio_r_answers', 'reviewid ' . $reviewinsql, $reviewparams);
            }
            $DB->delete_records_select('peerreviewstudio_reviews', 'assignmentid ' . $insql, $params);
        }

        $DB->delete_records('peerreviewstudio_assignments', ['peerreviewstudioid' => $activityid]);
        $DB->delete_records('peerreviewstudio_queue', ['peerreviewstudioid' => $activityid]);
    }

    /**
     * Delete a submission and its dependent operational tree, including files.
     *
     * @param int $submissionid Submission id.
     * @param context $context Module context.
     * @return void
     */
    protected static function delete_submission_tree(int $submissionid, context $context): void {
        global $DB;

        $assignments = $DB->get_records('peerreviewstudio_assignments', ['submissionid' => $submissionid], '', 'id');
        foreach ($assignments as $assignment) {
            $reviews = $DB->get_records('peerreviewstudio_reviews', ['assignmentid' => $assignment->id], '', 'id');
            foreach ($reviews as $review) {
                $DB->delete_records('peerreviewstudio_r_answers', ['reviewid' => $review->id]);
            }
            $DB->delete_records('peerreviewstudio_reviews', ['assignmentid' => $assignment->id]);
        }
        $DB->delete_records('peerreviewstudio_assignments', ['submissionid' => $submissionid]);
        $DB->delete_records('peerreviewstudio_queue', ['submissionid' => $submissionid]);
        $DB->delete_records('peerreviewstudio_submissions', ['id' => $submissionid]);

        get_file_storage()->delete_area_files($context->id, 'mod_peerreviewstudio', 'submission_files', $submissionid);
    }

    /**
     * Anonymise submission-owner data while preserving review history records.
     *
     * @param int $activityid Activity id.
     * @param context $context Module context.
     * @param int $userid User id.
     * @return void
     */
    protected static function anonymise_submission_owner_data(int $activityid, context $context, int $userid): void {
        global $DB;

        $submissions = $DB->get_records('peerreviewstudio_submissions', [
            'peerreviewstudioid' => $activityid,
            'userid' => $userid,
        ]);

        foreach ($submissions as $submission) {
            $submission->userid = 0;
            $submission->onlinetext = '';
            $submission->onlinetextformat = FORMAT_HTML;
            $submission->submissiongrade = null;
            $submission->reviewgrade = null;
            $submission->finalgrade = null;
            $submission->teachergradeoverride = null;
            $submission->aigrade = null;
            $submission->aifeedback = '';
            $submission->timemodified = time();
            $DB->update_record('peerreviewstudio_submissions', $submission);

            get_file_storage()->delete_area_files($context->id, 'mod_peerreviewstudio', 'submission_files', (int)$submission->id);

            foreach ($DB->get_records('peerreviewstudio_queue', ['submissionid' => $submission->id]) as $queueitem) {
                $queueitem->status = 'failed';
                $queueitem->timemodified = time();
                $DB->update_record('peerreviewstudio_queue', $queueitem);
            }
        }
    }

    /**
     * Anonymise reviewer-linked data while preserving activity integrity.
     *
     * @param int $activityid Activity id.
     * @param int|null $userid Specific user or null for all reviewers.
     * @return void
     */
    protected static function anonymise_reviewer_data(int $activityid, ?int $userid): void {
        global $DB;

        $params = ['activityid' => $activityid];
        $sql = "SELECT a.id
                  FROM {peerreviewstudio_assignments} a
                 WHERE a.peerreviewstudioid = :activityid";
        if ($userid !== null) {
            $sql .= " AND a.reviewerid = :userid";
            $params['userid'] = $userid;
        }
        $assignments = $DB->get_records_sql($sql, $params);
        foreach ($assignments as $assignment) {
            $reviews = $DB->get_records('peerreviewstudio_reviews', ['assignmentid' => $assignment->id], '', 'id');
            foreach ($reviews as $review) {
                foreach ($DB->get_records('peerreviewstudio_r_answers', ['reviewid' => $review->id]) as $answer) {
                    $answer->commenttext = '';
                    $DB->update_record('peerreviewstudio_r_answers', $answer);
                }
                $reviewrecord = $DB->get_record('peerreviewstudio_reviews', ['id' => $review->id], '*', MUST_EXIST);
                $reviewrecord->reviewerid = 0;
                $reviewrecord->generalcomment = '';
                $reviewrecord->aireviewergrade = null;
                $reviewrecord->aireviewerfeedback = '';
                $reviewrecord->timemodified = time();
                $DB->update_record('peerreviewstudio_reviews', $reviewrecord);
            }

            if ($userid !== null) {
                $assignmentrecord = $DB->get_record('peerreviewstudio_assignments', ['id' => $assignment->id], '*', MUST_EXIST);
                $assignmentrecord->reviewerid = 0;
                if ((string)$assignmentrecord->status === 'assigned') {
                    $assignmentrecord->status = 'cancelled';
                }
                $assignmentrecord->timemodified = time();
                $DB->update_record('peerreviewstudio_assignments', $assignmentrecord);
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
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return null;
        }
        $cm = get_coursemodule_from_id('peerreviewstudio', $context->instanceid);
        if (!$cm) {
            return null;
        }
        return (int)$cm->instance;
    }
}
