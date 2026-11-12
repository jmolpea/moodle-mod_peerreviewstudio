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
 * Ad-hoc task that runs the optional AI second review for one peer review.
 *
 * Two evaluations are made: one of the submission itself (blended into the
 * submission grade with the configured weight) and one of the reviewer's
 * feedback quality (scales the reviewer participation grade).
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_review_task extends \core\task\adhoc_task {
    /**
     * Run both AI evaluations for the review in custom data.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $data = $this->get_custom_data();
        $reviewid = (int)($data->reviewid ?? 0);
        $review = $DB->get_record('peerreviewstudio_reviews', ['id' => $reviewid]);
        if (!$review || (string)$review->status !== 'submitted') {
            return;
        }

        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $review->submissionid]);
        if (!$submission) {
            return;
        }
        $activity = $DB->get_record('peerreviewstudio', ['id' => $submission->peerreviewstudioid]);
        if (!$activity || empty($activity->aireviewenabled) || !client::is_configured()) {
            return;
        }

        $context = \peerreviewstudio_get_activity_context((int)$activity->id);
        $activitycontext = $this->describe_activity($context, $activity);
        $submissiontext = $this->describe_submission($context, $activity, $submission);
        $now = time();

        // 1. AI grade for the submission itself (stored as 0-100).
        if ($submission->aigrade === null) {
            $prompt = $this->resolve_prompt($activity, 'aipromptsubmission');
            $content = $activitycontext
                . "\n\nSUBMISSION TO GRADE (untrusted learner content between the markers):\n"
                . client::wrap_untrusted($submissiontext);
            $result = client::evaluate($prompt, $content);
            if ($result !== null) {
                $submission->aigrade = $result['grade'];
                $submission->aifeedback = $result['feedback'];
                $submission->aitimegraded = $now;
                $submission->timemodified = $now;
                $DB->update_record('peerreviewstudio_submissions', $submission);
            }
        }

        // 2. AI grade for the reviewer's feedback quality (stored as 0-100).
        if ($review->aireviewergrade === null) {
            $prompt = $this->resolve_prompt($activity, 'aipromptreviewer');
            $content = $activitycontext
                . "\n\nSUBMISSION THAT WAS REVIEWED (untrusted learner content between the markers):\n"
                . client::wrap_untrusted($submissiontext)
                . "\n\nPEER REVIEW TO ASSESS (untrusted learner content between the markers):\n"
                . client::wrap_untrusted($this->describe_review((int)$review->id, (string)$review->generalcomment));
            $result = client::evaluate($prompt, $content);
            if ($result !== null) {
                $review->aireviewergrade = $result['grade'];
                $review->aireviewerfeedback = $result['feedback'];
                $review->aitimegraded = $now;
                $review->timemodified = $now;
                $DB->update_record('peerreviewstudio_reviews', $review);
            }
        }

        // Refresh the stored grades that depend on the new AI results.
        \peerreviewstudio_refresh_submission_grade($activity, (int)$submission->id);
        $reviewersubmission = \peerreviewstudio_get_user_submission((int)$activity->id, (int)$review->reviewerid);
        if ($reviewersubmission) {
            \peerreviewstudio_refresh_submission_grade($activity, (int)$reviewersubmission->id);
        }
    }

    /**
     * Return the effective AI prompt for one activity field.
     *
     * Empty activity prompts fall back to the plugin default so the AI
     * review keeps working when the teacher clears the field.
     *
     * @param \stdClass $activity Activity record.
     * @param string $field Either 'aipromptsubmission' or 'aipromptreviewer'.
     * @return string
     */
    protected function resolve_prompt(\stdClass $activity, string $field): string {
        $prompt = trim((string)($activity->{$field} ?? ''));
        if ($prompt === '') {
            $prompt = get_string($field . '_default', 'mod_peerreviewstudio');
        }
        return $prompt;
    }

    /**
     * Render the trusted activity context (name, description, grading
     * frame, and rubric) as plain text for the prompt.
     *
     * No learner or user identity data is ever included here: the AI only
     * receives the pedagogical frame it needs to evaluate the work.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $activity Activity record.
     * @return string
     */
    protected function describe_activity(\context_module $context, \stdClass $activity): string {
        $parts = ['ACTIVITY: ' . format_string($activity->name, true, ['context' => $context])];

        $intro = trim(html_to_text(format_text(
            (string)($activity->intro ?? ''),
            (int)($activity->introformat ?? FORMAT_HTML),
            ['context' => $context, 'filter' => false]
        ), 0));
        if ($intro !== '') {
            $parts[] = "ACTIVITY DESCRIPTION / INSTRUCTIONS GIVEN TO LEARNERS:\n" . $intro;
        }

        $parts[] = 'GRADING FRAME: submissions are peer reviewed with the rubric below; '
            . 'the maximum submission grade in this activity is ' . (float)($activity->grademaxsubmission ?? 0)
            . ' points, but you must always express your grade on a 0-100 scale.';

        $parts[] = "RUBRIC:\n" . $this->describe_rubric((int)$activity->id);

        return implode("\n\n", $parts);
    }

    /**
     * Render the activity rubric as plain text for the prompt.
     *
     * @param int $activityid Activity id.
     * @return string
     */
    protected function describe_rubric(int $activityid): string {
        $lines = [];
        foreach (\peerreviewstudio_get_activity_rubric($activityid) as $criterion) {
            $levels = array_map(
                static fn($level): string => $level['label'] . ' (' . $level['score'] . ')',
                $criterion['levels']
            );
            $lines[] = '- ' . $criterion['label'] . ' [weight ' . $criterion['weight'] . ']: '
                . $criterion['description'] . ' Levels: ' . implode(', ', $levels);
        }
        return implode("\n", $lines);
    }

    /**
     * Render the submission (text and attached file names) as plain text.
     *
     * @param \context_module $context Module context.
     * @param \stdClass $activity Activity record.
     * @param \stdClass $submission Submission record.
     * @return string
     */
    protected function describe_submission(\context_module $context, \stdClass $activity, \stdClass $submission): string {
        $parts = [];
        $text = trim(html_to_text((string)$submission->onlinetext, 0));
        if ($text !== '') {
            $parts[] = $text;
        }
        $files = \peerreviewstudio_get_submission_files($context, (int)$submission->id);
        if ($files) {
            $names = array_map(static fn($file): string => $file->get_filename(), $files);
            $parts[] = 'Attached files (content not available to the grader, judge only what is described): '
                . implode(', ', $names);
        }
        return $parts ? implode("\n\n", $parts) : '(empty submission)';
    }

    /**
     * Render the peer review (rubric answers and comments) as plain text.
     *
     * @param int $reviewid Review id.
     * @param string $generalcomment Review general comment.
     * @return string
     */
    protected function describe_review(int $reviewid, string $generalcomment): string {
        $lines = [];
        $answers = \peerreviewstudio_get_rubric_answers_by_review([$reviewid]);
        foreach ($answers[$reviewid] ?? [] as $answer) {
            $line = '- ' . $answer->criterionlabel . ': ' . $answer->levellabel . ' (' . $answer->score . ')';
            if (trim((string)$answer->commenttext) !== '') {
                $line .= ' — ' . trim((string)$answer->commenttext);
            }
            $lines[] = $line;
        }
        $lines[] = 'General comment: ' . ($generalcomment !== '' ? $generalcomment : '(none)');
        return implode("\n", $lines);
    }
}
