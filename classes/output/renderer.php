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

namespace mod_peerreviewstudio\output;

use html_table;
use html_writer;
use moodle_url;

/**
 * Module renderer.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends \plugin_renderer_base {
    /**
     * Shortcut to a plugin language string.
     *
     * @param string $key String key.
     * @param mixed $a Optional placeholder payload.
     * @return string
     */
    protected function str(string $key, $a = null): string {
        return get_string($key, 'mod_peerreviewstudio', $a);
    }

    /**
     * Format a grade for display.
     *
     * @param mixed $grade Grade value or null.
     * @return string
     */
    protected function grade($grade): string {
        return $grade === null ? '–' : format_float((float)$grade, 2);
    }

    /**
     * Render a status chip.
     *
     * @param string $label Chip text (already safe).
     * @param string $modifier Optional style modifier: phase, alert, success, or ai.
     * @return string
     */
    protected function chip(string $label, string $modifier = ''): string {
        $class = 'peerreviewstudio-chip' . ($modifier !== '' ? ' peerreviewstudio-chip-' . $modifier : '');
        return html_writer::tag('span', $label, ['class' => $class]);
    }

    /**
     * Open a card with a heading.
     *
     * @param string $title Card title.
     * @param string $extraclass Extra CSS class.
     * @param string $id Optional element id, used as an in-page anchor.
     * @return string
     */
    protected function card_start(string $title, string $extraclass = '', string $id = ''): string {
        $attributes = ['class' => trim('peerreviewstudio-card ' . $extraclass)];
        if ($id !== '') {
            $attributes['id'] = $id;
        }
        return html_writer::start_tag('section', $attributes)
            . html_writer::tag('h3', $title, ['class' => 'peerreviewstudio-card-title']);
    }

    /**
     * Close a card.
     *
     * @return string
     */
    protected function card_end(): string {
        return html_writer::end_tag('section');
    }

    /**
     * Render the notice that tells learners AI review is enabled.
     *
     * A learner who has not accepted the site AI policy is shown the policy
     * and a button to accept it; until then none of their content is sent to
     * the AI.
     *
     * @param bool $policyaccepted Whether the learner has accepted the site AI policy.
     * @param moodle_url $pageurl URL of the activity page.
     * @return string
     */
    public function ai_notice(bool $policyaccepted, moodle_url $pageurl): string {
        if ($policyaccepted) {
            return $this->output->notification(
                $this->str('aiprivacynotice'),
                \core\output\notification::NOTIFY_INFO,
                false
            );
        }

        $out = html_writer::start_tag('section', [
            'class' => 'peerreviewstudio-aipolicy',
            'aria-label' => get_string('aiusagepolicy', 'core_ai'),
        ]);
        $out .= html_writer::tag('p', $this->str('aiprivacynotice'));
        $out .= html_writer::tag('p', html_writer::tag('strong', $this->str('aipolicyrequired')));
        $out .= html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('aiusagepolicy', 'core_ai'))
                . html_writer::div(get_string('userpolicy', 'core_ai'), 'peerreviewstudio-aipolicy-text')
        );
        $button = new \single_button(
            new moodle_url($pageurl, ['acceptaipolicy' => 1]),
            get_string('acceptai', 'core_ai'),
            'post',
            \single_button::BUTTON_PRIMARY
        );
        $out .= $this->output->render($button);
        $out .= html_writer::end_tag('section');

        return $out;
    }

    /**
     * Render the workflow summary shown at the top of the activity.
     *
     * @param \stdClass $activity Activity record.
     * @param string $nextstepkey Language string key of the learner's next step; empty for none.
     * @param array|null $learner Learner state (submission, completedreviews, openreviews), or null for staff.
     * @return string
     */
    public function activity_summary(\stdClass $activity, string $nextstepkey, ?array $learner): string {
        $currentphase = \peerreviewstudio_get_current_phase($activity);

        $out = html_writer::start_div('peerreviewstudio-hero');
        $out .= html_writer::start_div('peerreviewstudio-phasebar', [
            'role' => 'list',
            'aria-label' => $this->str('phasebarlabel'),
        ]);
        foreach (\peerreviewstudio_visible_workflow_phases($activity) as $phasekey => $phaselabel) {
            $attributes = ['role' => 'listitem', 'class' => 'peerreviewstudio-phasebar-item'];
            if ($phasekey === $currentphase) {
                $attributes['class'] .= ' is-active';
                $attributes['aria-current'] = 'step';
            }
            $out .= html_writer::tag('span', s($phaselabel), $attributes);
        }
        $out .= html_writer::end_div();

        if ($nextstepkey !== '') {
            $out .= html_writer::tag(
                'p',
                $this->str('nextsteplabel', $this->str($nextstepkey)),
                ['class' => 'peerreviewstudio-nextstep']
            );
        }

        if ($learner !== null) {
            $submission = $learner['submission'];
            $status = $submission
                ? \peerreviewstudio_status_label((string)$submission->status)
                : $this->str('status_notsubmitted');
            $stats = [
                $this->str('currentstatuslabel', $status),
                $this->str('reviewprogress', (object) [
                    'done' => (int)$learner['completedreviews'],
                    'required' => max(1, (int)$activity->reviewcountrequired),
                ]),
            ];
            if ((int)$learner['openreviews'] > 0) {
                $stats[] = $this->str('assignedreviewscount', (int)$learner['openreviews']);
            }
            $out .= html_writer::start_tag('ul', ['class' => 'peerreviewstudio-hero-stats']);
            foreach ($stats as $stat) {
                $out .= html_writer::tag('li', $stat, ['class' => 'peerreviewstudio-stat']);
            }
            $out .= html_writer::end_tag('ul');
        }
        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Render the learner's own submission: what was sent and the form to send or edit it.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass|false $submission The learner's submission.
     * @param \stored_file[] $files Files attached to the submission.
     * @param \mod_peerreviewstudio\local\form\submission_form|null $form Submission form, when editing is allowed.
     * @param bool $cansubmit Whether the learner may submit.
     * @param \context_module $context Module context.
     * @return string
     */
    public function learner_submission(
        \stdClass $activity,
        \stdClass|false $submission,
        array $files,
        ?\mod_peerreviewstudio\local\form\submission_form $form,
        bool $cansubmit,
        \context_module $context
    ): string {
        if (!$submission && !$cansubmit) {
            return '';
        }

        $out = $this->card_start($this->str('mysubmissionheading'));

        if ($submission) {
            $out .= html_writer::start_div('peerreviewstudio-success-banner');
            $out .= html_writer::tag('span', '✓', ['class' => 'peerreviewstudio-success-icon', 'aria-hidden' => 'true']);
            $out .= html_writer::start_div('peerreviewstudio-success-body');
            $out .= html_writer::tag('strong', $this->str('submissionalreadyexists'));
            $out .= html_writer::tag(
                'span',
                $this->str('submissionsubmittedon', userdate((int)$submission->timesubmitted)),
                ['class' => 'peerreviewstudio-muted']
            );
            $out .= html_writer::end_div();
            $out .= html_writer::end_div();
            $out .= $this->submission_content($submission, $files, $context);
        }

        if ($form) {
            if ($submission) {
                // The form stays available but collapsed: the primary state
                // after submitting is "done", editing is secondary. It is
                // reopened when a failed edit needs its errors to stay visible.
                $detailsattrs = ['class' => 'peerreviewstudio-editwrap'];
                if ($form->is_submitted()) {
                    $detailsattrs['open'] = 'open';
                }
                $out .= html_writer::start_tag('details', $detailsattrs);
                $out .= html_writer::tag('summary', $this->str('editmysubmission'), [
                    'class' => 'peerreviewstudio-editwrap-summary',
                ]);
                $out .= html_writer::tag('p', $this->str('submissionpaneleditintro'), ['class' => 'peerreviewstudio-muted']);
                $out .= $form->render();
                $out .= html_writer::end_tag('details');
            } else {
                $out .= html_writer::tag('p', $this->str('submissionpanelintro'), ['class' => 'peerreviewstudio-muted']);
                $out .= $form->render();
            }
        } else if (!$submission) {
            $opens = (int)($activity->timeopen ?? 0);
            if ((string)$activity->workflowmode === 'scheduled' && $opens > time()) {
                $message = $this->str('submissionopenson', userdate($opens));
            } else {
                $message = $this->str('submissionphasenotopen');
            }
            $out .= html_writer::tag('p', $message, ['class' => 'peerreviewstudio-notice']);
        } else if (\peerreviewstudio_phase_allows_submissions($activity)) {
            $out .= html_writer::tag('p', $this->str('submissionlocked'), ['class' => 'peerreviewstudio-muted']);
        }

        $out .= $this->card_end();
        return $out;
    }

    /**
     * Render the text and files of one submission.
     *
     * @param \stdClass $submission Submission record.
     * @param \stored_file[] $files Files attached to the submission.
     * @param \context_module $context Module context.
     * @return string
     */
    protected function submission_content(\stdClass $submission, array $files, \context_module $context): string {
        $out = '';
        if (trim((string)$submission->onlinetext) !== '') {
            $out .= html_writer::tag(
                'div',
                format_text((string)$submission->onlinetext, (int)$submission->onlinetextformat, ['context' => $context]),
                ['class' => 'peerreviewstudio-submission-preview']
            );
        }
        if ($files) {
            $out .= html_writer::tag('h4', $this->str('submissionfilesheading'), ['class' => 'peerreviewstudio-section-title']);
            $items = [];
            foreach ($files as $file) {
                $url = moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    $file->get_itemid(),
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                );
                $label = s($file->get_filename()) . ' (' . display_size($file->get_filesize()) . ')';
                $items[] = html_writer::tag('li', html_writer::link($url, $label), ['class' => 'peerreviewstudio-file-item']);
            }
            $out .= html_writer::tag('ul', implode('', $items), ['class' => 'peerreviewstudio-file-list']);
        }

        return $out;
    }

    /**
     * Render the reviews assigned to the learner and the form for the one in progress.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass[] $assignments The learner's review assignments.
     * @param \stdClass|null $activeassignment The assignment being worked on.
     * @param \stdClass|null $reviewtarget The submission being reviewed.
     * @param \stored_file[] $reviewtargetfiles Files of the submission being reviewed.
     * @param \mod_peerreviewstudio\local\form\review_form|null $form Review form.
     * @param moodle_url $pageurl Activity page URL.
     * @param \context_module $context Module context.
     * @return string
     */
    public function learner_reviews(
        \stdClass $activity,
        array $assignments,
        ?\stdClass $activeassignment,
        ?\stdClass $reviewtarget,
        array $reviewtargetfiles,
        ?\mod_peerreviewstudio\local\form\review_form $form,
        moodle_url $pageurl,
        \context_module $context
    ): string {
        $out = $this->card_start($this->str('myreviewsheading'), '', 'peerreviewstudio-reviews');
        $reviewsallowed = \peerreviewstudio_phase_allows_reviews($activity);

        // Withdrawn assignments are history the learner cannot act on.
        $visible = array_filter($assignments, static fn($assignment) => (string)$assignment->status !== 'cancelled');
        if (!$visible) {
            $out .= html_writer::tag('p', $this->str('reviewnoneassigned'), ['class' => 'peerreviewstudio-muted']);
            return $out . $this->card_end();
        }

        if (!$reviewsallowed && $this->has_open($visible)) {
            $out .= html_writer::tag('p', $this->str('reviewphasenotopen'), ['class' => 'peerreviewstudio-notice']);
        }

        $items = [];
        $number = 0;
        foreach ($visible as $assignment) {
            $number++;
            $label = html_writer::tag('strong', $this->str('reviewnumber', $number));
            if ((string)$assignment->status === 'completed') {
                $state = $this->chip($this->str('reviewstate_completed'), 'success')
                    . ' ' . html_writer::tag(
                        'span',
                        userdate((int)$assignment->timecompleted, get_string('strftimedatetimeshort', 'langconfig')),
                        ['class' => 'peerreviewstudio-muted']
                    );
            } else if ($activeassignment && (int)$assignment->id === (int)$activeassignment->id) {
                $state = $this->chip($this->str('reviewstate_inprogress'), 'phase');
            } else {
                $state = $this->chip($this->str('reviewstate_pending'), 'alert');
                if ($reviewsallowed) {
                    $url = new moodle_url($pageurl, ['assignmentid' => $assignment->id]);
                    $url->set_anchor('peerreviewstudio-reviews');
                    $state .= ' ' . html_writer::link($url, $this->str('reviewstart'), [
                        'class' => 'btn btn-sm btn-secondary',
                        'aria-label' => $this->str('reviewstartnumber', $number),
                    ]);
                }
            }
            $items[] = html_writer::tag('li', $label . ' ' . $state, ['class' => 'peerreviewstudio-review-item']);
        }
        $out .= html_writer::tag('ul', implode('', $items), ['class' => 'peerreviewstudio-review-list']);

        if ($activeassignment && $reviewtarget && $form) {
            $out .= html_writer::start_div('peerreviewstudio-review-target');
            $out .= html_writer::tag('h4', $this->str('reviewtargetheading'), ['class' => 'peerreviewstudio-section-title']);
            $out .= html_writer::tag('p', $this->str('reviewpanelintro'), ['class' => 'peerreviewstudio-muted']);
            $out .= $this->submission_content($reviewtarget, $reviewtargetfiles, $context);
            $out .= html_writer::end_div();
            $out .= $form->render();
        } else if ($reviewsallowed && !$this->has_open($visible)) {
            $out .= html_writer::tag('p', $this->str('reviewassignmentnoneopen'), ['class' => 'peerreviewstudio-muted']);
        }

        return $out . $this->card_end();
    }

    /**
     * Whether any assignment of a list is still open.
     *
     * @param \stdClass[] $assignments Assignment records.
     * @return bool
     */
    protected function has_open(array $assignments): bool {
        foreach ($assignments as $assignment) {
            if ((string)$assignment->status === 'assigned') {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the feedback and grade the learner received, or when they will be available.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass $submission The learner's submission.
     * @param bool $feedbackvisible Whether feedback has been released to the learner.
     * @param \stdClass[] $reviews Reviews received (empty until released).
     * @param array $answers Rubric answers grouped by review id.
     * @return string
     */
    public function learner_feedback(
        \stdClass $activity,
        \stdClass $submission,
        bool $feedbackvisible,
        array $reviews,
        array $answers
    ): string {
        $out = $this->card_start($this->str('receivedfeedbackheading'));

        if (!$feedbackvisible) {
            $mode = (string)$activity->workflowmode;
            if ($mode === 'scheduled' && !empty($activity->timereviewdue)) {
                $message = $this->str('feedbackpending_scheduled', userdate((int)$activity->timereviewdue));
            } else if ($mode === 'open') {
                $message = $this->str('feedbackpending_open');
            } else {
                $message = $this->str('feedbackpending_manual');
            }
            $out .= html_writer::tag('p', $message, ['class' => 'peerreviewstudio-muted']);
            return $out . $this->card_end();
        }

        $out .= $this->grade_breakdown($activity, $submission);

        if (!$reviews && $submission->aigrade === null) {
            $out .= html_writer::tag('p', $this->str('feedbacknonereceived'), ['class' => 'peerreviewstudio-notice']);
        }
        foreach (array_values($reviews) as $index => $review) {
            $out .= $this->review_block($this->str('feedbackfromreviewer', $index + 1), $review, $answers[(int)$review->id] ?? []);
        }

        if (!empty($activity->aireviewenabled) && $submission->aigrade !== null) {
            $out .= html_writer::start_div('peerreviewstudio-feedback peerreviewstudio-feedback-ai');
            $out .= html_writer::start_div('peerreviewstudio-feedback-head');
            $out .= html_writer::tag('strong', $this->str('aifeedbackheading'));
            $out .= $this->chip($this->str('aigradelabel', format_float((float)$submission->aigrade, 0)), 'ai');
            $out .= html_writer::end_div();
            if (trim((string)$submission->aifeedback) !== '') {
                $out .= html_writer::tag(
                    'div',
                    format_text((string)$submission->aifeedback, FORMAT_PLAIN),
                    ['class' => 'peerreviewstudio-feedback-comment']
                );
            }
            $out .= html_writer::tag('p', $this->str('aifeedbackdisclaimer'), ['class' => 'peerreviewstudio-muted']);
            $out .= html_writer::end_div();
        }

        return $out . $this->card_end();
    }

    /**
     * Render the grade components of one submission.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass $submission Submission record.
     * @return string
     */
    protected function grade_breakdown(\stdClass $activity, \stdClass $submission): string {
        $submissionmax = (float)$activity->grademaxsubmission;
        $reviewmax = (float)$activity->grademaxreview;
        if ($submissionmax + $reviewmax <= 0) {
            return '';
        }

        $rows = [];
        if ($submissionmax > 0) {
            $rows[$this->str('gradesubmissioncomponent')] = $this->str('gradeoutof', (object) [
                'grade' => $this->grade($submission->submissiongrade),
                'max' => format_float($submissionmax, 2),
            ]);
        }
        if ($reviewmax > 0) {
            $rows[$this->str('gradereviewcomponent')] = $this->str('gradeoutof', (object) [
                'grade' => $this->grade($submission->reviewgrade),
                'max' => format_float($reviewmax, 2),
            ]);
        }
        $final = $this->str('gradeoutof', (object) [
            'grade' => $this->grade($submission->finalgrade),
            'max' => format_float($submissionmax + $reviewmax, 2),
        ]);
        if ($submission->teachergradeoverride !== null) {
            $final .= ' ' . $this->chip($this->str('gradeoverridden'), 'phase');
        }
        $rows[$this->str('gradefinalcomponent')] = html_writer::tag('strong', $final);

        $out = html_writer::start_tag('dl', ['class' => 'peerreviewstudio-grades']);
        foreach ($rows as $label => $value) {
            $out .= html_writer::tag('dt', $label) . html_writer::tag('dd', $value);
        }
        $out .= html_writer::end_tag('dl');

        return $out;
    }

    /**
     * Render one review: proposed grade, rubric answers, and comments.
     *
     * @param string $title Heading of the block (already safe).
     * @param \stdClass $review Review record.
     * @param \stdClass[] $answers Rubric answers of the review.
     * @return string
     */
    protected function review_block(string $title, \stdClass $review, array $answers): string {
        $out = html_writer::start_div('peerreviewstudio-feedback');
        $out .= html_writer::start_div('peerreviewstudio-feedback-head');
        $out .= html_writer::tag('strong', $title);
        if ($review->grade !== null) {
            $out .= $this->chip($this->str('reviewgradelabel', format_float((float)$review->grade, 2)));
        }
        $out .= html_writer::end_div();

        if ($answers) {
            $items = [];
            foreach ($answers as $answer) {
                $line = html_writer::tag('div', $this->str('rubricanswerlabel', (object) [
                    'criterion' => s($answer->criterionlabel),
                    'level' => s($answer->levellabel),
                    'score' => format_float((float)$answer->score, 2),
                ]), ['class' => 'peerreviewstudio-rubric-line']);
                if (trim((string)$answer->commenttext) !== '') {
                    $line .= html_writer::tag(
                        'div',
                        format_text((string)$answer->commenttext, FORMAT_PLAIN),
                        ['class' => 'peerreviewstudio-rubric-comment']
                    );
                }
                $items[] = html_writer::tag('li', $line, ['class' => 'peerreviewstudio-rubric-item']);
            }
            $out .= html_writer::tag('ul', implode('', $items), ['class' => 'peerreviewstudio-rubric-list']);
        }
        if (trim((string)$review->generalcomment) !== '') {
            $out .= html_writer::tag(
                'div',
                format_text((string)$review->generalcomment, FORMAT_PLAIN),
                ['class' => 'peerreviewstudio-feedback-comment']
            );
        }
        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Render the tutor dashboard.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $activity Activity record.
     * @param array $dashboard Dashboard payload from the dashboard service.
     * @param string $statusfilter Active status filter.
     * @param string $search Active search.
     * @param bool $canmanageassignments Whether the tutor may run the matching.
     * @param bool $showemail Whether email addresses may be shown.
     * @param string $groupmenu Group selector markup, when the activity uses groups.
     * @return string
     */
    public function tutor_dashboard(
        \cm_info $cm,
        \stdClass $activity,
        array $dashboard,
        string $statusfilter,
        string $search,
        bool $canmanageassignments,
        bool $showemail,
        string $groupmenu
    ): string {
        $counts = $dashboard['counts'];
        $pageurl = new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]);

        $out = $this->card_start($this->str('tutordashboardheading'), 'peerreviewstudio-card-dashboard');
        $out .= $groupmenu;

        $out .= html_writer::start_tag('dl', ['class' => 'peerreviewstudio-metrics']);
        $metrics = [
            'dashboardsubmittedshort' => $counts['total'],
            'dashboardwaitingshort' => $counts['waiting'],
            'dashboardinreviewshort' => $counts['inreview'],
            'dashboardcompleteshort' => $counts['complete'],
            'dashboardincidentsshort' => count($dashboard['incidents']),
        ];
        foreach ($metrics as $stringkey => $value) {
            $out .= html_writer::start_div('peerreviewstudio-metric');
            $out .= html_writer::tag('dt', $this->str($stringkey));
            $out .= html_writer::tag('dd', (string)(int)$value);
            $out .= html_writer::end_div();
        }
        $out .= html_writer::end_tag('dl');

        if ($canmanageassignments && !empty($activity->autoassignenabled)) {
            $out .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => $pageurl->out_omit_querystring(),
                'class' => 'peerreviewstudio-inline-form',
            ]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'processqueue', 'value' => 1]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $out .= html_writer::tag('button', $this->str('dashboardactionprocessqueue'), [
                'type' => 'submit',
                'class' => 'btn btn-secondary',
            ]);
            $hint = \peerreviewstudio_matching_allowed($activity)
                ? $this->str('dashboardactionprocessqueue_hint')
                : $this->str('dashboardmatchingnotstarted');
            $out .= html_writer::tag('span', $hint, ['class' => 'peerreviewstudio-muted']);
            $out .= html_writer::end_tag('form');
        }
        $out .= $this->card_end();

        $out .= $this->dashboard_incidents($cm, $dashboard['incidents']);

        $out .= $this->card_start($this->str('dashboardparticipantsheading'));
        $out .= $this->dashboard_filters($cm, $statusfilter, $search, $showemail);
        $out .= $this->dashboard_rows_table($cm, $activity, $dashboard['rows'], $showemail);
        $baseurl = new moodle_url($pageurl, array_filter(['statusfilter' => $statusfilter, 'search' => $search]));
        $out .= $this->output->paging_bar($dashboard['total'], $dashboard['page'], $dashboard['perpage'], $baseurl, 'dashpage');
        $out .= $this->card_end();

        return $out;
    }

    /**
     * Render the participant name cell, linking to the submission detail page.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $row Dashboard row with name fields.
     * @param bool $showemail Whether email addresses may be shown.
     * @return string
     */
    protected function participant_cell(\cm_info $cm, \stdClass $row, bool $showemail): string {
        $url = new moodle_url('/mod/peerreviewstudio/submission.php', ['id' => $cm->id, 'sid' => $row->id]);
        $cell = html_writer::link($url, s(fullname($row)));
        if ($showemail) {
            $cell .= html_writer::tag('div', s((string)$row->email), ['class' => 'peerreviewstudio-email']);
        }
        return $cell;
    }

    /**
     * Render dashboard filters.
     *
     * @param \cm_info $cm Course module.
     * @param string $statusfilter Status filter.
     * @param string $search Search string.
     * @param bool $showemail Whether the search also covers email addresses.
     * @return string
     */
    protected function dashboard_filters(\cm_info $cm, string $statusfilter, string $search, bool $showemail): string {
        $pageurl = new moodle_url('/mod/peerreviewstudio/view.php');
        $out = html_writer::start_tag('form', [
            'method' => 'get',
            'action' => $pageurl->out(false),
            'class' => 'peerreviewstudio-inline-form',
        ]);
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);

        $statusoptions = ['' => $this->str('dashboardfilterall')];
        foreach (\peerreviewstudio_submission_statuses() as $status) {
            $statusoptions[$status] = \peerreviewstudio_status_label($status);
        }
        $out .= html_writer::label($this->str('dashboardfilterstatus'), 'id_statusfilter');
        $out .= html_writer::select($statusoptions, 'statusfilter', $statusfilter, false, [
            'id' => 'id_statusfilter',
            'class' => 'custom-select form-select',
        ]);

        $out .= html_writer::label($this->str('dashboardfiltersearch'), 'id_search');
        $out .= html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'search',
            'id' => 'id_search',
            'value' => $search,
            'class' => 'form-control',
            'placeholder' => $this->str($showemail ? 'dashboardfiltersearchplaceholder' : 'dashboardfiltersearchname'),
        ]);
        $out .= html_writer::tag('button', $this->str('dashboardfilterapply'), [
            'type' => 'submit',
            'class' => 'btn btn-secondary',
        ]);
        $out .= html_writer::link(
            new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
            $this->str('dashboardfilterreset'),
            ['class' => 'btn btn-link']
        );
        $out .= html_writer::end_tag('form');

        return $out;
    }

    /**
     * Render main dashboard rows table.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $activity Activity record.
     * @param array $rows Dashboard rows.
     * @param bool $showemail Whether email addresses may be shown.
     * @return string
     */
    protected function dashboard_rows_table(\cm_info $cm, \stdClass $activity, array $rows, bool $showemail): string {
        if (!$rows) {
            return html_writer::tag('p', $this->str('dashboardnoparticipantsmatch'), ['class' => 'peerreviewstudio-notice']);
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable peerreviewstudio-table';
        $table->caption = $this->str('dashboardtablecaption');
        $table->captionhide = true;
        $table->head = [
            $this->str('dashboardparticipant'),
            $this->str('status'),
            $this->str('dashboardsubmittedlabel'),
            $this->str('dashboardreceivedprogress'),
            $this->str('dashboardreviewsdone'),
            $this->str('dashboardgrade'),
            $this->str('dashboardlastactivity'),
        ];

        $required = max(1, (int)$activity->reviewcountrequired);
        $target = max(1, (int)$activity->reviewsreceivedtarget);
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');
        foreach ($rows as $row) {
            $status = $this->chip(
                \peerreviewstudio_status_label((string)$row->status),
                (string)$row->status === 'closed' ? 'success' : ''
            );
            if ((int)$row->timeescalated > 0 && (string)$row->status !== 'closed') {
                $status .= ' ' . $this->chip($this->str('dashboardneedsattention'), 'alert');
            }

            $grade = html_writer::tag('strong', $this->grade($row->finalgrade));
            if ($row->teachergradeoverride !== null) {
                $grade .= ' ' . $this->chip($this->str('gradeoverridden'), 'phase');
            }

            $done = $this->str('progressvalue', (object) ['done' => (int)$row->workloadcompleted, 'target' => $required]);
            if ((int)$row->workloadopen > 0) {
                $done .= html_writer::tag(
                    'div',
                    $this->str('dashboardreviewsopen', (int)$row->workloadopen),
                    ['class' => 'peerreviewstudio-muted']
                );
            }

            $table->data[] = [
                $this->participant_cell($cm, $row, $showemail),
                $status,
                userdate((int)$row->timesubmitted, $dateformat),
                $this->str('progressvalue', (object) ['done' => (int)$row->completedcount, 'target' => $target]),
                $done,
                $grade,
                userdate((int)$row->timemodified, $dateformat),
            ];
        }

        return html_writer::div(html_writer::table($table), 'peerreviewstudio-table-wrap');
    }

    /**
     * Render the incidents that need a tutor.
     *
     * @param \cm_info $cm Course module.
     * @param array $incidents Incident rows.
     * @return string
     */
    protected function dashboard_incidents(\cm_info $cm, array $incidents): string {
        $out = $this->card_start($this->str('dashboardincidentsheading'));
        if (!$incidents) {
            $out .= html_writer::tag(
                'p',
                $this->str('dashboardincidentsnone'),
                ['class' => 'peerreviewstudio-notice peerreviewstudio-notice-success']
            );
            return $out . $this->card_end();
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable peerreviewstudio-table';
        $table->caption = $this->str('dashboardincidenttablecaption');
        $table->captionhide = true;
        $table->head = [
            $this->str('dashboardparticipant'),
            $this->str('dashboardincidenttype'),
            $this->str('dashboardeligiblereviewers'),
            $this->str('dashboardineligiblereviewers'),
        ];
        foreach ($incidents as $incident) {
            $blocked = (string)(int)$incident->ineligiblecount;
            if ($incident->ineligiblesummary !== '') {
                $blocked .= html_writer::tag('div', s($incident->ineligiblesummary), ['class' => 'peerreviewstudio-muted']);
            }
            $table->data[] = [
                $this->participant_cell($cm, $incident, false),
                $this->chip($this->str('dashboardincident_' . $incident->incidenttype), 'alert'),
                (int)$incident->eligiblecount,
                $blocked,
            ];
        }
        $out .= html_writer::div(html_writer::table($table), 'peerreviewstudio-table-wrap');
        $out .= html_writer::tag('p', $this->str('dashboardincidentreasonhint'), ['class' => 'peerreviewstudio-muted']);

        return $out . $this->card_end();
    }

    /**
     * Render the tutor view of one submission: content, grades, reviewers, and actions.
     *
     * @param \cm_info $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $activity Activity record.
     * @param \stdClass $submission Submission record.
     * @param \stdClass $author Author user record.
     * @param \stored_file[] $files Submission files.
     * @param \stdClass[] $assignments Assignments with reviewer names and review data.
     * @param array $answers Rubric answers grouped by review id.
     * @param array $candidates Reviewer candidates ('eligible' and 'ineligible' lists).
     * @return string
     */
    public function submission_detail(
        \cm_info $cm,
        \context_module $context,
        \stdClass $activity,
        \stdClass $submission,
        \stdClass $author,
        array $files,
        array $assignments,
        array $answers,
        array $candidates
    ): string {
        $pageurl = new moodle_url('/mod/peerreviewstudio/submission.php', ['id' => $cm->id, 'sid' => $submission->id]);
        $canmanage = has_capability('mod/peerreviewstudio:manageassignments', $context);
        $cangrade = has_capability('mod/peerreviewstudio:grade', $context);
        $dateformat = get_string('strftimedatetimeshort', 'langconfig');

        $out = html_writer::start_div('peerreviewstudio-shell');
        $out .= html_writer::tag('p', html_writer::link(
            new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
            '← ' . $this->str('backtodashboard')
        ));

        // The submission itself.
        $authorlink = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $author->id, 'course' => $cm->course]),
            s(fullname($author))
        );
        $out .= $this->card_start($this->str('submissionby', $authorlink));
        $status = $this->chip(
            \peerreviewstudio_status_label((string)$submission->status),
            (string)$submission->status === 'closed' ? 'success' : ''
        );
        if ((int)$submission->timeescalated > 0 && (string)$submission->status !== 'closed') {
            $status .= ' ' . $this->chip($this->str('dashboardneedsattention'), 'alert');
        }
        $out .= html_writer::tag('p', $status . ' ' . html_writer::tag(
            'span',
            $this->str('submissionsubmittedon', userdate((int)$submission->timesubmitted)),
            ['class' => 'peerreviewstudio-muted']
        ));
        $content = $this->submission_content($submission, $files, $context);
        $out .= $content !== '' ? $content : html_writer::tag('p', $this->str('submissionempty'), [
            'class' => 'peerreviewstudio-muted',
        ]);
        $out .= $this->card_end();

        // Grades and the teacher override.
        $out .= $this->card_start($this->str('dashboardgrade'));
        $out .= $this->grade_breakdown($activity, $submission);
        if (!\peerreviewstudio_grade_is_released($activity, $submission)) {
            $out .= html_writer::tag('p', $this->str('gradenotreleased'), ['class' => 'peerreviewstudio-muted']);
        }
        if (!empty($activity->aireviewenabled) && $submission->aigrade !== null) {
            $out .= html_writer::tag(
                'p',
                $this->chip($this->str('aigradelabel', format_float((float)$submission->aigrade, 0)), 'ai')
            );
            $out .= html_writer::tag(
                'div',
                format_text((string)$submission->aifeedback, FORMAT_PLAIN),
                ['class' => 'peerreviewstudio-feedback-comment']
            );
        } else if ($submission->aigrade === null && (int)$submission->aitimegraded === 0 && (int)$submission->userid > 0) {
            $aiclient = \mod_peerreviewstudio\local\ai\client::instance();
            if ($aiclient->is_enabled_for($activity) && !$aiclient->has_accepted_policy((int)$submission->userid)) {
                $out .= html_writer::tag('p', $this->str('aiwaitingpolicy'), ['class' => 'peerreviewstudio-muted']);
            }
        }
        if ($cangrade) {
            $inputid = 'id_teachergradeoverride';
            $out .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => $pageurl->out(false),
                'class' => 'peerreviewstudio-inline-form',
            ]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'override']);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $out .= html_writer::label($this->str('dashboardactionsmanagegrade'), $inputid);
            $out .= html_writer::empty_tag('input', [
                'type' => 'text',
                'id' => $inputid,
                'name' => 'teachergradeoverride',
                'value' => $submission->teachergradeoverride !== null
                    ? format_float((float)$submission->teachergradeoverride, 2)
                    : '',
                'class' => 'form-control',
                'size' => 8,
                'inputmode' => 'decimal',
            ]);
            $out .= html_writer::tag('button', $this->str('dashboardactionsavegrade'), [
                'type' => 'submit',
                'class' => 'btn btn-primary',
            ]);
            $out .= html_writer::tag('span', $this->str('gradeoverridehint'), ['class' => 'peerreviewstudio-muted']);
            $out .= html_writer::end_tag('form');
        }
        $out .= $this->card_end();

        // Reviewers and their reviews.
        $out .= $this->card_start($this->str('dashboardreviewersheading'));
        if (!$assignments) {
            $out .= html_writer::tag('p', $this->str('dashboardnoreviewers'), ['class' => 'peerreviewstudio-muted']);
        }
        foreach ($assignments as $assignment) {
            $reviewer = (int)$assignment->reviewerid > 0 ? s(fullname($assignment)) : $this->str('deletedreviewer');
            if ((string)$assignment->status === 'completed' && $assignment->reviewid) {
                $review = (object) [
                    'grade' => $assignment->grade,
                    'generalcomment' => $assignment->generalcomment,
                ];
                $out .= $this->review_block(
                    $this->str('reviewby', $reviewer) . ' ' . html_writer::tag(
                        'span',
                        userdate((int)$assignment->timecompleted, $dateformat),
                        ['class' => 'peerreviewstudio-muted']
                    ),
                    $review,
                    $answers[(int)$assignment->reviewid] ?? []
                );
                if (!empty($activity->aireviewenabled) && $assignment->aireviewergrade !== null) {
                    $out .= html_writer::start_div('peerreviewstudio-feedback peerreviewstudio-feedback-ai');
                    $out .= $this->chip(
                        $this->str('aireviewergradelabel', format_float((float)$assignment->aireviewergrade, 0)),
                        'ai'
                    );
                    $out .= html_writer::tag(
                        'div',
                        format_text((string)$assignment->aireviewerfeedback, FORMAT_PLAIN),
                        ['class' => 'peerreviewstudio-feedback-comment']
                    );
                    $out .= html_writer::end_div();
                }
            } else {
                $state = (string)$assignment->status === 'cancelled'
                    ? $this->chip($this->str('reviewstate_withdrawn'))
                    : $this->chip($this->str('reviewstate_pending'), 'alert');
                $detail = $this->str('reviewassignedon', userdate((int)$assignment->timeassigned, $dateformat));
                if ((int)$assignment->timeopened > 0) {
                    $detail .= ' · ' . $this->str('reviewopenedon', userdate((int)$assignment->timeopened, $dateformat));
                }
                $out .= html_writer::tag(
                    'p',
                    html_writer::tag('strong', $this->str('reviewby', $reviewer)) . ' ' . $state . ' '
                        . html_writer::tag('span', $detail, ['class' => 'peerreviewstudio-muted']),
                    ['class' => 'peerreviewstudio-review-item']
                );
            }
        }

        if ($canmanage && (string)$submission->status !== 'closed') {
            $out .= html_writer::tag('h4', $this->str('dashboardactions'), ['class' => 'peerreviewstudio-section-title']);
            if ($candidates['eligible']) {
                $options = [];
                foreach ($candidates['eligible'] as $candidate) {
                    $options[(int)$candidate->userid] = fullname($candidate);
                }
                $out .= html_writer::start_tag('form', [
                    'method' => 'post',
                    'action' => $pageurl->out(false),
                    'class' => 'peerreviewstudio-inline-form',
                ]);
                $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'assign']);
                $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                $out .= html_writer::label($this->str('dashboardeligiblereviewers'), 'id_reviewerid');
                $out .= html_writer::select($options, 'reviewerid', '', false, [
                    'id' => 'id_reviewerid',
                    'class' => 'custom-select form-select',
                ]);
                $out .= html_writer::tag('button', $this->str('dashboardactionassignmanual'), [
                    'type' => 'submit',
                    'class' => 'btn btn-secondary',
                ]);
                $out .= html_writer::end_tag('form');
            } else {
                $out .= html_writer::tag('p', $this->str('dashboardincident_noreviewers'), ['class' => 'peerreviewstudio-muted']);
            }
            $summary = \peerreviewstudio_ineligible_candidates_summary($candidates['ineligible']);
            if ($summary !== '') {
                $out .= html_writer::tag(
                    'p',
                    $this->str('dashboardblockedsummary', s($summary)),
                    ['class' => 'peerreviewstudio-muted']
                );
            }

            $out .= html_writer::start_tag('form', [
                'method' => 'post',
                'action' => $pageurl->out(false),
                'class' => 'peerreviewstudio-inline-form',
            ]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'requeue']);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $out .= html_writer::tag('button', $this->str('dashboardactionrequeue'), [
                'type' => 'submit',
                'class' => 'btn btn-secondary',
            ]);
            $out .= html_writer::tag('span', $this->str('dashboardactionrequeue_hint'), ['class' => 'peerreviewstudio-muted']);
            $out .= html_writer::end_tag('form');
        }
        $out .= $this->card_end();

        $out .= html_writer::end_div();
        return $out;
    }
}
