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

/**
 * Ad-hoc task that grades one submission with the AI when no peer review
 * could be obtained for it.
 *
 * This is the automatic last-resort remedy of the review follow-up cycle:
 * the AI grade stands in fully for the missing peer average and the AI
 * feedback is released to the author, so a learner abandoned by their
 * reviewers still receives a grade and feedback without tutor action.
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
        $submissionid = (int)($data->submissionid ?? 0);
        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $submissionid]);
        if (!$submission) {
            return;
        }
        $activity = $DB->get_record('peerreviewstudio', ['id' => $submission->peerreviewstudioid]);
        if (!$activity || empty($activity->aireviewenabled) || empty($activity->aifallbackenabled)
                || !client::is_configured()) {
            return;
        }

        // A real peer review may have arrived between escalation and this
        // run; if so the normal peer-average path applies and the fallback
        // is no longer needed.
        $hasreview = $DB->record_exists_sql(
            "SELECT 1
               FROM {peerreviewstudio_reviews} r
               JOIN {peerreviewstudio_assignments} a ON a.id = r.assignmentid
              WHERE a.submissionid = :submissionid
                AND r.status = 'submitted'",
            ['submissionid' => $submission->id]
        );
        if ($hasreview) {
            return;
        }

        $now = time();
        if ($submission->aigrade === null) {
            $context = \peerreviewstudio_get_activity_context((int)$activity->id);
            $prompt = $this->resolve_prompt($activity, 'aipromptsubmission');
            $content = $this->describe_activity($context, $activity)
                . "\n\nSUBMISSION TO GRADE (untrusted learner content between the markers):\n"
                . client::wrap_untrusted($this->describe_submission($context, $activity, $submission));
            $result = client::evaluate($prompt, $content);
            if ($result === null) {
                // Transient provider failure: fail the task so the adhoc
                // manager retries it, otherwise the learner stays without
                // grade or feedback forever.
                throw new \moodle_exception('aifallbackfailed', 'mod_peerreviewstudio');
            }
            $submission->aigrade = $result['grade'];
            $submission->aifeedback = $result['feedback'];
            $submission->aitimegraded = $now;
        }

        // The AI review stands in for the missing peer reviews: close the
        // submission (which also releases feedback in open mode) and
        // resolve any queue entries still waiting for a reviewer.
        $submission->status = 'closed';
        $submission->timemodified = $now;
        $DB->update_record('peerreviewstudio_submissions', $submission);

        $pendingqueue = $DB->get_records('peerreviewstudio_queue', [
            'submissionid' => $submission->id,
            'status' => 'pending',
        ]);
        foreach ($pendingqueue as $queueentry) {
            $queueentry->status = 'resolved';
            $queueentry->timemodified = $now;
            $DB->update_record('peerreviewstudio_queue', $queueentry);
        }

        \peerreviewstudio_refresh_submission_grade($activity, (int)$submission->id);
        \peerreviewstudio_send_notification($activity, 'gradereceived', (int)$submission->userid);
    }
}
