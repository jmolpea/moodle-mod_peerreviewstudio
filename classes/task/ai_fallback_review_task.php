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

namespace mod_peerreviewstudio\task;

use mod_peerreviewstudio\local\ai\client;
use mod_peerreviewstudio\local\ai\submission_reader;

/**
 * Ad-hoc task that grades one submission with the AI when no peer review
 * could be obtained for it.
 *
 * This is the automatic last-resort remedy of the review follow-up cycle:
 * the AI grade stands in fully for the missing peer average and the AI
 * feedback is released to the author, so a learner abandoned by their
 * reviewers still receives a grade and feedback without tutor action. When
 * the AI cannot do it (unreadable work, or the provider keeps failing) the
 * tutors are told instead.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_fallback_review_task extends ai_review_task {
    /**
     * Grade the submission in custom data and close its review cycle.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $data = $this->get_custom_data();
        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => (int)($data->submissionid ?? 0)]);
        // A submission that was requeued by a tutor is back in the normal cycle.
        if (!$submission || (int)$submission->userid <= 0 || (int)$submission->timeescalated === 0) {
            return;
        }
        $activity = $DB->get_record('peerreviewstudio', ['id' => $submission->peerreviewstudioid]);
        $client = client::instance();
        if (!$activity || empty($activity->aifallbackenabled) || !$client->is_enabled_for($activity)) {
            return;
        }

        // A real peer review may have arrived between escalation and this
        // run; if so the normal peer-average path applies and the fallback
        // is no longer needed.
        if (\peerreviewstudio_count_received_reviews((int)$submission->id) > 0) {
            return;
        }

        if ($submission->aigrade === null) {
            $context = \peerreviewstudio_get_activity_context((int)$activity->id);
            $authorid = (int)$submission->userid;
            if (!$client->has_accepted_policy($authorid)) {
                mtrace('The author of submission ' . $submission->id
                    . ' has not accepted the AI policy; tutors are notified instead.');
                \peerreviewstudio_notify_tutors_of_unresolved($activity, [$submission]);
                return;
            }
            $work = submission_reader::read($context, $submission);
            if (!$work['readable']) {
                mtrace('Submission ' . $submission->id . ' has content the AI cannot read; tutors are notified instead.');
                \peerreviewstudio_notify_tutors_of_unresolved($activity, [$submission]);
                return;
            }

            $result = $this->evaluate_submission(
                $activity,
                $context,
                $this->describe_activity($context, $activity),
                $work['text'],
                $authorid
            );
            if ($result === null) {
                if ($this->should_retry($client->last_error(), $client->is_rate_limited())) {
                    throw new \moodle_exception('aifallbackfailed', 'mod_peerreviewstudio', '', $client->last_error());
                }
                // The provider keeps failing: a person has to step in.
                \peerreviewstudio_notify_tutors_of_unresolved($activity, [$submission]);
                return;
            }

            $DB->update_record('peerreviewstudio_submissions', (object) [
                'id' => $submission->id,
                'aigrade' => $result['grade'],
                'aifeedback' => $result['feedback'],
                'aitimegraded' => time(),
            ]);
        }

        // The AI review stands in for the missing peer reviews: the
        // submission is closed (which also releases feedback in open mode)
        // and no longer waits for a reviewer.
        \peerreviewstudio_close_queue_entries((int)$submission->id);
        \peerreviewstudio_update_submission_status($activity, (int)$submission->id);
        \peerreviewstudio_refresh_submission_grade($activity, (int)$submission->id);
        \peerreviewstudio_notify_grade_released($activity, (int)$submission->id);
    }
}
