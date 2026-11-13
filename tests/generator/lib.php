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
 * Data generator for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @category    test
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_peerreviewstudio_generator extends testing_module_generator {
    /**
     * Create one activity instance with sensible defaults.
     *
     * The default is an open (self-paced) activity where every submission
     * needs one review and reciprocal reviews are allowed, so the smallest
     * scenario (two learners) completes a full cycle.
     *
     * @param array|stdClass|null $record Instance values.
     * @param array|null $options Course module options.
     * @return stdClass Activity record with the cmid property set.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object)(array)$record;

        $defaults = [
            'submissiontype' => 'onlinetext',
            'reviewcountrequired' => 1,
            'reviewsreceivedtarget' => 1,
            'autoassignenabled' => 1,
            'reviewpoolmode' => 'any',
            'avoidreciprocalreviews' => 0,
            'reviewreminderdays' => 2,
            'reviewreassigndays' => 4,
            'workflowmode' => 'open',
            'manualphase' => 'submission',
            'timeopen' => 0,
            'timeclose' => 0,
            'timereviewdue' => 0,
            'grademaxsubmission' => 100,
            'grademaxreview' => 0,
            'aireviewenabled' => 0,
            'aifallbackenabled' => 0,
            'aigradeweight' => 30,
            'aipromptsubmission' => '',
            'aipromptreviewer' => '',
            'completionsubmit' => 0,
            'completionreviews' => 0,
        ];
        foreach ($defaults as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        return parent::create_instance($record, (array)$options);
    }

    /**
     * Return the stored activity record.
     *
     * @param stdClass|int $activity Activity record or id.
     * @return stdClass
     */
    public function get_activity($activity): stdClass {
        global $DB;

        $id = is_object($activity) ? $activity->id : $activity;
        return $DB->get_record('peerreviewstudio', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a learner submission through the real save path.
     *
     * @param stdClass $activity Activity record (as returned by create_instance).
     * @param int $userid Author user id.
     * @param string $text Online text content.
     * @return stdClass Submission record.
     */
    public function create_submission(stdClass $activity, int $userid, string $text = 'Submission text'): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

        return peerreviewstudio_save_submission(
            $this->get_activity($activity),
            $userid,
            ['text' => $text, 'format' => FORMAT_HTML]
        );
    }

    /**
     * Return the open assignment of one reviewer for one submission.
     *
     * @param int $submissionid Submission id.
     * @param int $reviewerid Reviewer user id.
     * @return stdClass|false
     */
    public function get_open_assignment(int $submissionid, int $reviewerid) {
        global $DB;

        return $DB->get_record('peerreviewstudio_assignments', [
            'submissionid' => $submissionid,
            'reviewerid' => $reviewerid,
            'status' => 'assigned',
        ]);
    }

    /**
     * Complete one open review assignment through the real save path,
     * choosing the given level position for every rubric criterion.
     *
     * @param stdClass $activity Activity record.
     * @param stdClass $assignment Open assignment record.
     * @param int $levelindex Zero-based level position to select in every criterion.
     * @param string $comment General comment.
     * @return stdClass Review record.
     */
    public function create_review(
        stdClass $activity,
        stdClass $assignment,
        int $levelindex = 0,
        string $comment = 'Review comment'
    ): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/lib.php');

        $activity = $this->get_activity($activity);
        $rubric = peerreviewstudio_get_activity_rubric((int)$activity->id);
        $formdata = [];
        foreach ($rubric as $criterion) {
            $level = $criterion['levels'][min($levelindex, count($criterion['levels']) - 1)];
            $formdata['criterion_' . $criterion['id']] = $level['id'];
            $formdata['criterioncomment_' . $criterion['id']] = 'Comment on ' . $criterion['label'];
        }
        $result = peerreviewstudio_calculate_rubric_result($rubric, $formdata, (float)$activity->grademaxsubmission);

        return peerreviewstudio_save_review(
            $activity,
            $assignment,
            (int)$assignment->reviewerid,
            $comment,
            (float)$result['grade'],
            $result['answers']
        );
    }

    /**
     * Replace the AI client with one that answers from memory, so no test
     * ever talks to an AI provider.
     *
     * @return \mod_peerreviewstudio\local\ai\fake_client
     */
    public function use_fake_ai(): \mod_peerreviewstudio\local\ai\fake_client {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/tests/fixtures/fake_client.php');

        $client = new \mod_peerreviewstudio\local\ai\fake_client();
        \core\di::set(\mod_peerreviewstudio\local\ai\client::class, $client);

        return $client;
    }

    /**
     * Record that users accept the site AI policy.
     *
     * @param int ...$userids Users accepting the policy.
     * @return void
     */
    public function accept_ai_policy(int ...$userids): void {
        foreach ($userids as $userid) {
            \core_ai\manager::user_policy_accepted($userid, \context_system::instance()->id);
        }
    }
}
