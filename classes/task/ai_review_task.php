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
     * Once the retry delay of a failing task reaches this many seconds the
     * task gives up, so a permanent provider problem never retries forever.
     */
    const GIVE_UP_FAIL_DELAY = 1800;

    /**
     * Same limit when the AI request limit was reached: limits are per hour,
     * so the task waits longer before giving up.
     */
    const GIVE_UP_RATE_LIMIT_FAIL_DELAY = 14400;

    /**
     * Run both AI evaluations for the review in custom data.
     *
     * @return void
     */
    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        $data = $this->get_custom_data();
        $review = $DB->get_record('peerreviewstudio_reviews', ['id' => (int)($data->reviewid ?? 0)]);
        if (!$review || (string)$review->status !== 'submitted') {
            return;
        }
        $submission = $DB->get_record('peerreviewstudio_submissions', ['id' => $review->submissionid]);
        if (!$submission) {
            return;
        }
        $activity = $DB->get_record('peerreviewstudio', ['id' => $submission->peerreviewstudioid]);
        $client = client::instance();
        if (!$activity || !$client->is_enabled_for($activity)) {
            return;
        }

        $context = \peerreviewstudio_get_activity_context((int)$activity->id);
        $activitycontext = $this->describe_activity($context, $activity);
        $work = submission_reader::read($context, $submission);
        $authorid = (int)$submission->userid;
        $reviewerid = (int)$review->reviewerid;
        $authoragreed = $client->has_accepted_policy($authorid);
        $now = time();
        // What failed in this run: table => record id.
        $failed = [];
        $failure = '';
        $ratelimited = false;

        // 1. AI grade for the submission itself (stored as 0-100). Only work
        // the AI could read in full is graded, and only once its author has
        // accepted the site AI policy.
        if ($submission->aigrade === null && (int)$submission->aitimegraded === 0 && $authorid > 0) {
            if (!$authoragreed) {
                mtrace('The author of submission ' . $submission->id . ' has not accepted the AI policy; it is not graded by AI.');
            } else if (!$work['readable']) {
                mtrace('Submission ' . $submission->id . ' has content the AI cannot read; it is not graded by AI.');
                $DB->set_field('peerreviewstudio_submissions', 'aitimegraded', $now, ['id' => $submission->id]);
            } else {
                $result = $this->evaluate_submission($activity, $context, $activitycontext, $work['text'], $authorid);
                if ($result !== null) {
                    $DB->update_record('peerreviewstudio_submissions', (object) [
                        'id' => $submission->id,
                        'aigrade' => $result['grade'],
                        'aifeedback' => $result['feedback'],
                        'aitimegraded' => $now,
                    ]);
                } else {
                    $failed['peerreviewstudio_submissions'] = (int)$submission->id;
                    $failure = $client->last_error();
                    $ratelimited = $client->is_rate_limited();
                }
            }
        }

        // 2. AI grade for the reviewer's feedback quality (stored as 0-100),
        // once the reviewer has accepted the site AI policy.
        if ($review->aireviewergrade === null && (int)$review->aitimegraded === 0 && $reviewerid > 0) {
            if (!$client->has_accepted_policy($reviewerid)) {
                mtrace('The author of review ' . $review->id . ' has not accepted the AI policy; it is not assessed by AI.');
            } else {
                // The reviewed work is only shown to the AI when it is readable and its author agreed.
                $reviewedwork = ($work['readable'] && $authoragreed)
                    ? client::wrap_untrusted($work['text'])
                    : '(The reviewed submission is not available. Judge only how specific, respectful, and'
                        . ' actionable the review is.)';
                $content = $activitycontext
                    . "\n\nSUBMISSION THAT WAS REVIEWED (untrusted learner content between the markers):\n"
                    . $reviewedwork
                    . "\n\nPEER REVIEW TO ASSESS (untrusted learner content between the markers):\n"
                    . client::wrap_untrusted($this->describe_review((int)$review->id, (string)$review->generalcomment));
                $result = $client->evaluate(
                    $this->resolve_prompt($activity, 'aipromptreviewer'),
                    $content,
                    (int)$context->id,
                    $reviewerid
                );
                if ($result !== null) {
                    $DB->update_record('peerreviewstudio_reviews', (object) [
                        'id' => $review->id,
                        'aireviewergrade' => $result['grade'],
                        'aireviewerfeedback' => $result['feedback'],
                        'aitimegraded' => $now,
                    ]);
                } else {
                    $failed['peerreviewstudio_reviews'] = (int)$review->id;
                    $failure = $client->last_error();
                    $ratelimited = $ratelimited || $client->is_rate_limited();
                }
            }
        }

        // Refresh the stored grades that depend on the new AI results.
        \peerreviewstudio_refresh_submission_grade($activity, (int)$submission->id);
        $reviewersubmission = \peerreviewstudio_get_user_submission((int)$activity->id, $reviewerid);
        if ($reviewersubmission) {
            \peerreviewstudio_refresh_submission_grade($activity, (int)$reviewersubmission->id);
        }

        if ($failure === '') {
            return;
        }
        if ($this->should_retry($failure, $ratelimited)) {
            // Fail the task so the task manager retries it later; whatever
            // was already evaluated is stored and will not be asked again.
            throw new \moodle_exception('aireviewfailed', 'mod_peerreviewstudio', '', $failure);
        }
        // Given up: record it, so the evaluation is not queued again.
        foreach ($failed as $table => $recordid) {
            $DB->set_field($table, 'aitimegraded', $now, ['id' => $recordid]);
        }
    }

    /**
     * Whether a failed AI call should be retried by failing the task.
     *
     * @param string $failure Why the call failed.
     * @param bool $ratelimited Whether the call was rejected by a request limit.
     * @return bool False once the task has been retrying for long enough.
     */
    protected function should_retry(string $failure, bool $ratelimited = false): bool {
        $limit = $ratelimited ? self::GIVE_UP_RATE_LIMIT_FAIL_DELAY : self::GIVE_UP_FAIL_DELAY;
        if ((int)$this->get_fail_delay() >= $limit) {
            mtrace('AI evaluation abandoned after repeated failures: ' . $failure);
            return false;
        }

        return true;
    }

    /**
     * Ask the AI to grade the text of one submission.
     *
     * @param \stdClass $activity Activity record.
     * @param \context_module $context Module context.
     * @param string $activitycontext Trusted activity context for the prompt.
     * @param string $worktext Plain text of the submission.
     * @param int $authorid Author of the submission.
     * @return array|null ['grade' => float 0-100, 'feedback' => string] or null on failure.
     */
    protected function evaluate_submission(
        \stdClass $activity,
        \context_module $context,
        string $activitycontext,
        string $worktext,
        int $authorid
    ): ?array {
        $content = $activitycontext
            . "\n\nSUBMISSION TO GRADE (untrusted learner content between the markers):\n"
            . client::wrap_untrusted($worktext);

        return client::instance()->evaluate(
            $this->resolve_prompt($activity, 'aipromptsubmission'),
            $content,
            (int)$context->id,
            $authorid
        );
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
        $parts = ['ACTIVITY: ' . \peerreviewstudio_plain_activity_name($activity, $context)];

        $intro = submission_reader::html_to_plain(format_text(
            (string)($activity->intro ?? ''),
            (int)($activity->introformat ?? FORMAT_HTML),
            ['context' => $context, 'filter' => false]
        ));
        if ($intro !== '') {
            $parts[] = "ACTIVITY DESCRIPTION / INSTRUCTIONS GIVEN TO LEARNERS:\n" . $intro;
        }

        $parts[] = 'GRADING FRAME: submissions are peer reviewed with the rubric below. '
            . 'Always express your grade on a 0-100 scale.';
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
            $line = '- ' . $answer->criterionlabel . ': ' . $answer->levellabel . ' (' . (float)$answer->score . ')';
            if (trim((string)$answer->commenttext) !== '') {
                $line .= ' - ' . trim((string)$answer->commenttext);
            }
            $lines[] = $line;
        }
        $lines[] = 'General comment: ' . ($generalcomment !== '' ? $generalcomment : '(none)');
        return implode("\n", $lines);
    }
}
