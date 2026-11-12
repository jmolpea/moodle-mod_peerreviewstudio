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
     * Render learner workspace card.
     *
     * @param \stdClass $activity Activity record.
     * @param \context_module $context Context.
     * @param bool $cansubmit Can submit.
     * @param bool $canreview Can review.
     * @param \stdClass|false $submission User submission.
     * @param array $submissionfiles User submission files.
     * @param array $userassignments User assignments.
     * @param \mod_peerreviewstudio\local\form\submission_form|null $submissionform Submission form.
     * @param \mod_peerreviewstudio\local\form\review_form|null $reviewform Review form.
     * @param \stdClass|null $activeassignment Active assignment.
     * @param \stdClass|null $reviewtargetsubmission Submission currently assigned for review.
     * @param array $reviewtargetfiles Files attached to the assigned submission.
     * @return string
     */
    public function learner_workspace(
        \stdClass $activity,
        \context_module $context,
        bool $cansubmit,
        bool $canreview,
        \stdClass|false $submission,
        array $submissionfiles,
        array $userassignments,
        ?\mod_peerreviewstudio\local\form\submission_form $submissionform,
        ?\mod_peerreviewstudio\local\form\review_form $reviewform,
        ?\stdClass $activeassignment,
        ?\stdClass $reviewtargetsubmission,
        array $reviewtargetfiles
    ): string {
        $out = html_writer::start_div('peerreviewstudio-card peerreviewstudio-card-summary');
        $out .= html_writer::tag('h3', $this->str('learnerworkspace'), ['class' => 'peerreviewstudio-card-title']);

        if ($cansubmit) {
            if ($submission) {
                // Clear "already submitted" success state.
                $submittedwhen = !empty($submission->timesubmitted)
                    ? userdate((int)$submission->timesubmitted)
                    : userdate((int)$submission->timemodified);
                $out .= html_writer::start_div('peerreviewstudio-success-banner');
                $out .= html_writer::tag('span', '✓', ['class' => 'peerreviewstudio-success-icon', 'aria-hidden' => 'true']);
                $out .= html_writer::start_div('peerreviewstudio-success-body');
                $out .= html_writer::tag('strong', $this->str('submissionalreadyexists'));
                $out .= html_writer::tag(
                    'span',
                    $this->str('submissionsubmittedon', $submittedwhen),
                    ['class' => 'peerreviewstudio-muted']
                );
                $out .= html_writer::end_div();
                $out .= html_writer::end_div();
                if (trim((string)$submission->onlinetext) !== '') {
                    $out .= html_writer::tag(
                        'div',
                        format_text((string)$submission->onlinetext, (int)$submission->onlinetextformat),
                        ['class' => 'peerreviewstudio-submission-preview']
                    );
                }
                if ($submissionfiles) {
                    $out .= html_writer::tag(
                        'h4',
                        $this->str('submissionfilesheading'),
                        ['class' => 'peerreviewstudio-section-title']
                    );
                    $out .= $this->submission_files_list($submissionfiles);
                }
            }
            if (\peerreviewstudio_phase_allows_submissions($activity) && \peerreviewstudio_submissions_open($activity)) {
                ob_start();
                $submissionform?->display();
                $formhtml = ob_get_clean();
                if ($submission) {
                    // The form stays available but collapsed: the primary
                    // state after submitting is "done", editing is secondary.
                    // Reopen it when a failed edit needs its validation
                    // errors to stay visible.
                    $detailsattrs = ['class' => 'peerreviewstudio-editwrap'];
                    if ($submissionform && $submissionform->is_submitted()) {
                        $detailsattrs['open'] = 'open';
                    }
                    $out .= html_writer::start_tag('details', $detailsattrs);
                    $out .= html_writer::tag('summary', $this->str('editmysubmission'), [
                        'class' => 'peerreviewstudio-editwrap-summary',
                    ]);
                    $out .= html_writer::tag(
                        'p',
                        $this->str('submissionpaneleditintro'),
                        ['class' => 'peerreviewstudio-muted']
                    );
                    $out .= $formhtml;
                    $out .= html_writer::end_tag('details');
                } else {
                    $out .= html_writer::tag(
                        'p',
                        $this->str('submissionpanelintro'),
                        ['class' => 'peerreviewstudio-muted']
                    );
                    $out .= $formhtml;
                }
            } else if (!$submission) {
                $out .= html_writer::tag('p', $this->str('submissionphasenotopen'), ['class' => 'peerreviewstudio-notice']);
            }
        }

        if ($submission && $canreview) {
            if (\peerreviewstudio_phase_allows_reviews($activity)) {
                $out .= html_writer::tag('p', $this->str('reviewpanelintro'), ['class' => 'peerreviewstudio-muted']);
            } else {
                $out .= html_writer::tag('p', $this->str('reviewphasenotopen'), ['class' => 'peerreviewstudio-notice']);
            }
            if ($userassignments && \peerreviewstudio_phase_allows_reviews($activity)) {
                $openassignments = array_filter(
                    $userassignments,
                    static fn($assignment) => (string)$assignment->status === 'assigned'
                );
                $out .= html_writer::tag(
                    'p',
                    $this->str('assignedreviewscount', count($openassignments)),
                    ['class' => 'peerreviewstudio-kpi']
                );
            }
            if ($activeassignment && \peerreviewstudio_phase_allows_reviews($activity)) {
                $out .= html_writer::tag('p', $this->str('reviewassignmentready'), ['class' => 'peerreviewstudio-kpi']);
                if ($reviewtargetsubmission) {
                    $out .= html_writer::start_div('peerreviewstudio-review-target');
                    $out .= html_writer::tag(
                        'h4',
                        $this->str('reviewtargetheading'),
                        ['class' => 'peerreviewstudio-section-title']
                    );
                    if (trim((string)$reviewtargetsubmission->onlinetext) !== '') {
                        $out .= html_writer::tag(
                            'div',
                            format_text(
                                (string)$reviewtargetsubmission->onlinetext,
                                (int)$reviewtargetsubmission->onlinetextformat
                            ),
                            ['class' => 'peerreviewstudio-submission-preview']
                        );
                    }
                    if ($reviewtargetfiles) {
                        $out .= html_writer::tag(
                            'h5',
                            $this->str('submissionfilesheading'),
                            ['class' => 'peerreviewstudio-section-title']
                        );
                        $out .= $this->submission_files_list($reviewtargetfiles);
                    }
                    $out .= html_writer::end_div();
                }
                ob_start();
                $reviewform?->display();
                $out .= ob_get_clean();
            } else if ($userassignments && \peerreviewstudio_phase_allows_reviews($activity)) {
                $out .= html_writer::tag('p', $this->str('reviewassignmentnoneopen'), ['class' => 'peerreviewstudio-muted']);
            }
        }

        $out .= html_writer::end_div();
        return $out;
    }

    /**
     * Render submission file links.
     *
     * @param array $files Stored files.
     * @return string
     */
    protected function submission_files_list(array $files): string {
        $items = [];
        foreach ($files as $file) {
            $url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                true,
            );
            $items[] = html_writer::tag(
                'li',
                html_writer::link($url, s($file->get_filename())),
                ['class' => 'peerreviewstudio-file-item']
            );
        }
        return html_writer::tag('ul', implode('', $items), ['class' => 'peerreviewstudio-file-list']);
    }

    /**
     * Render received feedback panel.
     *
     * @param array $receivedreviews Reviews received.
     * @param array $receivedreviewanswers Answers grouped by review id.
     * @param bool $feedbackvisible Feedback visibility.
     * @return string
     */
    public function received_feedback(array $receivedreviews, array $receivedreviewanswers, bool $feedbackvisible): string {
        if (!$receivedreviews) {
            return '';
        }
        if (!$feedbackvisible) {
            return html_writer::tag('p', $this->str('feedbacknotreleasedyet'), ['class' => 'peerreviewstudio-notice']);
        }

        $out = html_writer::start_div('peerreviewstudio-card');
        $out .= html_writer::tag('h3', $this->str('receivedfeedbackheading'), ['class' => 'peerreviewstudio-card-title']);
        foreach (array_values($receivedreviews) as $reviewindex => $review) {
            $out .= html_writer::start_div('peerreviewstudio-feedback');
            $out .= html_writer::start_div('peerreviewstudio-feedback-head');
            $out .= html_writer::tag('strong', $this->str('feedbackfromreviewer', $reviewindex + 1));
            if ($review->grade !== null) {
                $out .= html_writer::tag(
                    'span',
                    $this->str('reviewgradelabel', format_float((float)$review->grade, 2)),
                    ['class' => 'peerreviewstudio-chip']
                );
            }
            $out .= html_writer::end_div();
            if (!empty($receivedreviewanswers[(int)$review->id])) {
                $items = [];
                foreach ($receivedreviewanswers[(int)$review->id] as $answer) {
                    $answerline = html_writer::tag('div', $this->str('rubricanswerlabel', (object) [
                        'criterion' => s($answer->criterionlabel),
                        'level' => s($answer->levellabel),
                        'score' => format_float((float)$answer->score, 2),
                    ]), ['class' => 'peerreviewstudio-rubric-line']);
                    if (trim((string)$answer->commenttext) !== '') {
                        $answerline .= html_writer::tag(
                            'div',
                            format_text((string)$answer->commenttext, FORMAT_PLAIN),
                            ['class' => 'peerreviewstudio-rubric-comment']
                        );
                    }
                    $items[] = html_writer::tag('li', $answerline, ['class' => 'peerreviewstudio-rubric-item']);
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
        }
        $out .= html_writer::end_div();
        return $out;
    }

    /**
     * Render the AI feedback panel for the submission author.
     *
     * @param \stdClass $activity Activity record.
     * @param \stdClass|false $submission The author's submission.
     * @param bool $feedbackvisible Whether feedback may be shown.
     * @return string
     */
    public function ai_feedback(\stdClass $activity, \stdClass|false $submission, bool $feedbackvisible): string {
        if (empty($activity->aireviewenabled) || !$submission || !$feedbackvisible) {
            return '';
        }
        if ($submission->aigrade === null) {
            return '';
        }

        $out = html_writer::start_div('peerreviewstudio-card peerreviewstudio-card-ai');
        $out .= html_writer::tag('h3', $this->str('aifeedbackheading'), ['class' => 'peerreviewstudio-card-title']);
        $out .= html_writer::tag(
            'span',
            $this->str('aigradelabel', format_float((float)$submission->aigrade, 2)),
            ['class' => 'peerreviewstudio-chip peerreviewstudio-chip-ai']
        );
        if (trim((string)$submission->aifeedback) !== '') {
            $out .= html_writer::tag(
                'div',
                format_text((string)$submission->aifeedback, FORMAT_PLAIN),
                ['class' => 'peerreviewstudio-feedback-comment']
            );
        }
        $out .= html_writer::tag('p', $this->str('aifeedbackdisclaimer'), ['class' => 'peerreviewstudio-muted']);
        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Render tutor dashboard sidebar.
     *
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $activity Activity record.
     * @param array $dashboard Dashboard payload.
     * @param string $statusfilter Active status filter.
     * @param string $search Active search.
     * @param bool $canmanageassignments Can manage assignments.
     * @return string
     */
    public function tutor_dashboard(
        \stdClass $cm,
        \context_module $context,
        \stdClass $activity,
        array $dashboard,
        string $statusfilter,
        string $search,
        bool $canmanageassignments
    ): string {
        $counts = $dashboard['counts'];
        $filteredrows = $dashboard['filteredrows'];
        $incidents = $dashboard['incidents'];
        $total = (int)($dashboard['total'] ?? count($filteredrows));
        $page = (int)($dashboard['page'] ?? 0);
        $perpage = (int)($dashboard['perpage'] ?? 0);

        $out = html_writer::start_div('peerreviewstudio-sidebar');
        $out .= html_writer::start_div('peerreviewstudio-card peerreviewstudio-card-dashboard');
        $out .= html_writer::tag('h3', $this->str('tutordashboardheading'), ['class' => 'peerreviewstudio-card-title']);
        $out .= html_writer::start_tag('dl', [
            'class' => 'peerreviewstudio-metrics',
            'aria-label' => $this->str('tutordashboardheading'),
        ]);
        foreach ([
            'submitted' => $this->str('dashboardsubmittedshort'),
            'queued' => $this->str('dashboardqueuedshort'),
            'assignments' => $this->str('dashboardassignmentsshort'),
            'completed' => $this->str('dashboardcompletedshort'),
            'pendingqueue' => $this->str('dashboardpendingshort'),
        ] as $key => $label) {
            $out .= html_writer::start_tag('div', ['class' => 'peerreviewstudio-metric']);
            $out .= html_writer::tag('dt', $label, ['class' => 'peerreviewstudio-sr-only']);
            $out .= html_writer::tag(
                'dd',
                html_writer::tag('strong', (string)$counts[$key]) . html_writer::tag('span', $label),
                ['class' => 'peerreviewstudio-metric-body']
            );
            $out .= html_writer::end_tag('div');
        }
        $out .= html_writer::end_tag('dl');

        if ($canmanageassignments) {
            $out .= html_writer::start_tag('form', ['method' => 'post', 'class' => 'peerreviewstudio-queueform']);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'processqueue', 'value' => 1]);
            $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $out .= html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => $this->str('dashboardactionprocessqueue'),
            ]);
            $out .= html_writer::tag('span', $this->str('dashboardactionprocessqueue_hint'), [
                'class' => 'peerreviewstudio-muted peerreviewstudio-queueform-hint',
            ]);
            $out .= html_writer::end_tag('form');
        }

        $out .= $this->dashboard_filters($cm, $statusfilter, $search, count($filteredrows));
        $out .= $this->dashboard_rows_table($cm, $context, $activity, $filteredrows, $canmanageassignments);
        $out .= $this->dashboard_pagination($cm, $statusfilter, $search, $total, $page, $perpage);
        $out .= html_writer::end_div();
        $out .= $this->dashboard_incidents_table($incidents);
        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Render pagination controls for the dashboard rows table.
     *
     * @param \stdClass $cm Course module.
     * @param string $statusfilter Active status filter.
     * @param string $search Active search.
     * @param int $total Total matching rows.
     * @param int $page Current zero-based page.
     * @param int $perpage Rows per page.
     * @return string
     */
    protected function dashboard_pagination(
        \stdClass $cm,
        string $statusfilter,
        string $search,
        int $total,
        int $page,
        int $perpage
    ): string {
        if ($perpage <= 0 || $total <= $perpage) {
            return '';
        }

        $pages = (int)ceil($total / $perpage);
        $baseparams = ['id' => $cm->id];
        if ($statusfilter !== '') {
            $baseparams['statusfilter'] = $statusfilter;
        }
        if ($search !== '') {
            $baseparams['search'] = $search;
        }

        $out = html_writer::start_div('peerreviewstudio-pagination');
        if ($page > 0) {
            $out .= html_writer::link(
                new moodle_url('/mod/peerreviewstudio/view.php', $baseparams + ['dashpage' => $page - 1]),
                $this->str('dashboardpageprev'),
                ['class' => 'peerreviewstudio-pagelink']
            );
        }
        $out .= html_writer::tag('span', $this->str('dashboardpageinfo', (object) [
            'page' => $page + 1,
            'pages' => $pages,
            'total' => $total,
        ]), ['class' => 'peerreviewstudio-pageinfo']);
        if ($page < $pages - 1) {
            $out .= html_writer::link(
                new moodle_url('/mod/peerreviewstudio/view.php', $baseparams + ['dashpage' => $page + 1]),
                $this->str('dashboardpagenext'),
                ['class' => 'peerreviewstudio-pagelink']
            );
        }
        $out .= html_writer::end_div();

        return $out;
    }

    /**
     * Render dashboard filters.
     *
     * @param \stdClass $cm Course module.
     * @param string $statusfilter Status filter.
     * @param string $search Search string.
     * @param int $visiblecount Visible rows count.
     * @return string
     */
    protected function dashboard_filters(\stdClass $cm, string $statusfilter, string $search, int $visiblecount): string {
        $out = html_writer::start_tag('form', ['method' => 'get', 'class' => 'peerreviewstudio-filters']);
        $out .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        $out .= html_writer::tag('label', $this->str('dashboardfilterstatus'), ['for' => 'id_statusfilter']);
        $out .= html_writer::start_tag('select', ['name' => 'statusfilter', 'id' => 'id_statusfilter']);
        $statusoptions = [
            '' => $this->str('dashboardfilterall'),
            'submitted' => \peerreviewstudio_status_label('submitted'),
            'queued' => \peerreviewstudio_status_label('queued'),
            'assigned' => \peerreviewstudio_status_label('assigned'),
            'partiallyreviewed' => \peerreviewstudio_status_label('partiallyreviewed'),
            'reviewed' => \peerreviewstudio_status_label('reviewed'),
            'closed' => \peerreviewstudio_status_label('closed'),
        ];
        foreach ($statusoptions as $value => $label) {
            $attrs = ['value' => $value];
            if ((string)$value === $statusfilter) {
                $attrs['selected'] = 'selected';
            }
            $out .= html_writer::tag('option', $label, $attrs);
        }
        $out .= html_writer::end_tag('select');
        $out .= ' ';
        $out .= html_writer::tag('label', $this->str('dashboardfiltersearch'), ['for' => 'id_search']);
        $out .= html_writer::empty_tag('input', [
            'type' => 'text',
            'name' => 'search',
            'id' => 'id_search',
            'value' => $search,
            'placeholder' => $this->str('dashboardfiltersearchplaceholder'),
        ]);
        $out .= ' ';
        $out .= html_writer::empty_tag('input', ['type' => 'submit', 'value' => $this->str('dashboardfilterapply')]);
        $out .= ' ';
        $out .= html_writer::link(
            new moodle_url('/mod/peerreviewstudio/view.php', ['id' => $cm->id]),
            $this->str('dashboardfilterreset')
        );
        $out .= html_writer::end_tag('form');
        $out .= html_writer::tag(
            'p',
            $this->str('dashboardvisibleparticipants', $visiblecount),
            ['class' => 'peerreviewstudio-muted']
        );
        return $out;
    }

    /**
     * Render main dashboard rows table.
     *
     * @param \stdClass $cm Course module.
     * @param \context_module $context Module context.
     * @param \stdClass $activity Activity record.
     * @param array $filteredrows Filtered rows.
     * @param bool $canmanageassignments Can manage assignments.
     * @return string
     */
    protected function dashboard_rows_table(
        \stdClass $cm,
        \context_module $context,
        \stdClass $activity,
        array $filteredrows,
        bool $canmanageassignments
    ): string {
        if (!$filteredrows) {
            return html_writer::tag('p', $this->str('dashboardnoparticipantsmatch'), ['class' => 'peerreviewstudio-notice']);
        }

        $table = new html_table();
        $table->attributes['class'] = 'generaltable peerreviewstudio-table';
        $table->caption = $this->str('dashboardtablecaption');
        $table->head = [
            $this->str('dashboardparticipant'),
            $this->str('status'),
            $this->str('dashboardsubmittedlabel'),
            $this->str('dashboardreceivedprogress'),
            $this->str('dashboardmatching'),
            $this->str('dashboardgrade'),
            $this->str('dashboardqueueflag'),
            $this->str('dashboardreviewload'),
            $this->str('dashboardlastactivity'),
            $this->str('dashboardactions'),
        ];

        foreach ($filteredrows as $row) {
            $statuslabel = \peerreviewstudio_status_label((string)$row->status);
            $submittedlabel = !empty($row->timesubmitted) ? userdate((int)$row->timesubmitted) : '-';
            $receivedprogress = $this->str('dashboardreceivedprogressvalue', (object) [
                'done' => (int)$row->completedcount,
                'target' => (int)$activity->reviewsreceivedtarget,
            ]);
            $matching = html_writer::tag('div', $this->str('dashboardreviewercountsummary', (object) [
                'eligible' => (int)($row->eligiblecount ?? 0),
                'blocked' => (int)($row->ineligiblecount ?? 0),
            ]), ['class' => 'peerreviewstudio-matching-meta']);
            $matching .= html_writer::tag(
                'div',
                s($row->ineligiblesummary ?: $this->str('dashboardineligiblesummarynone')),
                ['class' => 'peerreviewstudio-muted']
            );
            $queueflag = !empty($row->pendingqueuecount)
                ? $this->str('dashboardqueuependingvalue', (int)$row->pendingqueuecount)
                : $this->str('dashboardqueuenone');
            $reviewload = $this->str('dashboardreviewloadvalue', (object) [
                'open' => (int)$row->workloadopen,
                'completed' => (int)$row->workloadcompleted,
            ]);
            $gradesummary = html_writer::tag(
                'div',
                $this->str('dashboardgradesubmissionvalue', format_float((float)($row->submissiongrade ?? 0), 2))
            );
            $gradesummary .= html_writer::tag(
                'div',
                $this->str('dashboardgradereviewvalue', format_float((float)($row->reviewgrade ?? 0), 2))
            );
            if (!empty($activity->aireviewenabled) && ($row->aigrade ?? null) !== null) {
                $gradesummary .= html_writer::tag(
                    'div',
                    $this->str('dashboardgradeaivalue', format_float((float)$row->aigrade, 2))
                );
            }
            $gradesummary .= html_writer::tag(
                'div',
                $this->str('dashboardgradefinalvalue', format_float((float)($row->finalgrade ?? 0), 2)),
                ['class' => 'peerreviewstudio-kpi']
            );
            if ($row->teachergradeoverride !== null) {
                $gradesummary .= html_writer::tag(
                    'div',
                    $this->str('dashboardgradeoverridevalue', format_float((float)$row->teachergradeoverride, 2)),
                    ['class' => 'peerreviewstudio-muted']
                );
            }
            $actions = '-';
            // Any state short of the reviews-received target is actionable:
            // 'reviewed' owners finished their own reviewing but their
            // submission may still be waiting for reviewers.
            $actionablestatuses = ['submitted', 'queued', 'assigned', 'partiallyreviewed', 'reviewed'];
            if ($canmanageassignments && in_array((string)$row->status, $actionablestatuses, true)
                    && (int)$row->completedcount < (int)$activity->reviewsreceivedtarget) {
                $actions = $this->dashboard_row_actions($cm, $row);
            }
            if (has_capability('mod/peerreviewstudio:grade', $context)) {
                $actions .= $this->dashboard_grade_form($cm, $row);
            }
            $table->data[] = [
                s(fullname($row)) . html_writer::tag('div', s((string)$row->email), ['class' => 'peerreviewstudio-email']),
                html_writer::tag('span', $statuslabel, ['class' => 'peerreviewstudio-chip']),
                $submittedlabel,
                $receivedprogress,
                $matching,
                $gradesummary,
                $queueflag,
                $reviewload,
                userdate((int)$row->timemodified),
                $actions,
            ];
        }

        return html_writer::div(html_writer::table($table), 'peerreviewstudio-table-wrap');
    }

    /**
     * Render requeue and manual-assignment controls for one dashboard row.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $row Dashboard row.
     * @return string
     */
    protected function dashboard_row_actions(\stdClass $cm, \stdClass $row): string {
        $eligible = $row->eligiblereviewers ?? [];
        $parts = [];
        $parts[] = html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-block;']);
        $parts[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        $parts[] = html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'requeuesubmission',
            'value' => (int)$row->id,
        ]);
        $parts[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $parts[] = html_writer::tag('span', $this->str('dashboardparticipantactions'), ['class' => 'peerreviewstudio-sr-only']);
        $parts[] = html_writer::empty_tag('input', ['type' => 'submit', 'value' => $this->str('dashboardactionrequeue')]);
        $parts[] = html_writer::end_tag('form');
        if ($eligible) {
            $selectid = 'id_manualreviewerid_' . (int)$row->id;
            $parts[] = html_writer::start_tag('form', [
                'method' => 'post',
                'style' => 'display:inline-block;margin-left:8px;',
            ]);
            $parts[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
            $parts[] = html_writer::empty_tag('input', [
                'type' => 'hidden',
                'name' => 'manualassignsubmission',
                'value' => (int)$row->id,
            ]);
            $parts[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            $parts[] = html_writer::tag('label', $this->str('dashboardeligiblereviewers'), [
                'for' => $selectid,
                'class' => 'peerreviewstudio-sr-only',
            ]);
            $parts[] = html_writer::start_tag('select', ['name' => 'manualreviewerid', 'id' => $selectid]);
            foreach ($eligible as $candidate) {
                $parts[] = html_writer::tag('option', s(fullname($candidate)), ['value' => (int)$candidate->userid]);
            }
            $parts[] = html_writer::end_tag('select');
            $parts[] = html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => $this->str('dashboardactionassignmanual'),
            ]);
            $parts[] = html_writer::end_tag('form');
        }
        return implode(' ', $parts);
    }

    /**
     * Render the teacher grade override form for one dashboard row.
     *
     * @param \stdClass $cm Course module.
     * @param \stdClass $row Dashboard row.
     * @return string
     */
    protected function dashboard_grade_form(\stdClass $cm, \stdClass $row): string {
        $inputid = 'id_teachergradeoverride_' . (int)$row->id;
        $gradeform = [];
        $gradeform[] = html_writer::start_tag('form', ['method' => 'post', 'class' => 'peerreviewstudio-gradeform']);
        $gradeform[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $cm->id]);
        $gradeform[] = html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'gradeoverridesubmission',
            'value' => (int)$row->id,
        ]);
        $gradeform[] = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $gradeform[] = html_writer::tag('label', $this->str('dashboardactionsmanagegrade'), [
            'for' => $inputid,
            'class' => 'peerreviewstudio-sr-only',
        ]);
        $gradeform[] = html_writer::empty_tag('input', [
            'type' => 'text',
            'id' => $inputid,
            'name' => 'teachergradeoverride',
            'value' => $row->teachergradeoverride !== null ? format_float((float)$row->teachergradeoverride, 2) : '',
            'placeholder' => $this->str('dashboardgradeoverrideplaceholder'),
            'inputmode' => 'decimal',
        ]);
        $gradeform[] = html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => $this->str('dashboardactionsavegrade'),
        ]);
        $gradeform[] = html_writer::end_tag('form');
        return html_writer::tag('div', implode('', $gradeform), ['class' => 'peerreviewstudio-gradeform-wrap']);
    }

    /**
     * Render incidents table.
     *
     * @param array $incidents Incident rows.
     * @return string
     */
    protected function dashboard_incidents_table(array $incidents): string {
        $out = html_writer::start_div('peerreviewstudio-card');
        $out .= html_writer::tag('h3', $this->str('dashboardincidentsheading'), ['class' => 'peerreviewstudio-card-title']);
        if ($incidents) {
            $incidenttable = new html_table();
            $incidenttable->attributes['class'] = 'generaltable peerreviewstudio-table';
            $incidenttable->caption = $this->str('dashboardincidenttablecaption');
            $incidenttable->head = [
                $this->str('dashboardparticipant'),
                $this->str('dashboardincidenttype'),
                $this->str('status'),
                $this->str('dashboardqueueflag'),
                $this->str('dashboardeligiblereviewers'),
                $this->str('dashboardineligiblereviewers'),
                $this->str('dashboardmatching'),
                $this->str('dashboardlastactivity'),
            ];
            foreach ($incidents as $incident) {
                $participant = s(fullname($incident))
                    . html_writer::tag('div', s((string)$incident->email), ['class' => 'peerreviewstudio-email']);
                $incidentchip = html_writer::tag(
                    'span',
                    $this->str('dashboardincident_' . $incident->incidenttype),
                    ['class' => 'peerreviewstudio-chip peerreviewstudio-chip-alert']
                );
                $statuschip = html_writer::tag(
                    'span',
                    \peerreviewstudio_status_label((string)$incident->status),
                    ['class' => 'peerreviewstudio-chip']
                );
                $incidenttable->data[] = [
                    $participant,
                    $incidentchip,
                    $statuschip,
                    (int)$incident->pendingqueuecount,
                    (int)$incident->eligiblecount,
                    (int)($incident->ineligiblecount ?? 0),
                    s($incident->ineligiblesummary ?: $this->str('dashboardineligiblesummarynone')),
                    userdate((int)$incident->timemodified),
                ];
            }
            $out .= html_writer::div(html_writer::table($incidenttable), 'peerreviewstudio-table-wrap');
            $out .= html_writer::tag('p', $this->str('dashboardincidentreasonhint'), ['class' => 'peerreviewstudio-muted']);
        } else {
            $out .= html_writer::tag(
                'p',
                $this->str('dashboardincidentsnone'),
                ['class' => 'peerreviewstudio-notice peerreviewstudio-notice-success']
            );
        }
        $out .= html_writer::end_div();
        return $out;
    }
}
