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

namespace mod_peerreviewstudio\courseformat;

use cm_info;
use core_courseformat\local\overview\overviewitem;
use core\output\action_link;
use core\output\local\properties\button;
use core\output\local\properties\text_align;
use core\url;
use stdClass;

/**
 * Course activity overview integration (Moodle 5.0 and later).
 *
 * Older Moodle versions never load this class.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class overview extends \core_courseformat\activityoverviewbase {
    /** @var stdClass The activity record. */
    private stdClass $activity;

    /** @var array|null Learner filter (sql, params) for tutors; null when the tutor sees no group. */
    private ?array $learnerfilter = null;

    /** @var bool Whether the learner filter was already built. */
    private bool $filterready = false;

    /**
     * Constructor.
     *
     * @param cm_info $cm The course module.
     */
    public function __construct(
        cm_info $cm,
    ) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/peerreviewstudio/locallib.php');

        parent::__construct($cm);
        $this->activity = $cm->get_instance_record();
    }

    #[\Override]
    public function get_due_date_overview(): ?overviewitem {
        $name = get_string('overview_duedate', 'mod_peerreviewstudio');
        $deadline = 0;
        if ((string)($this->activity->workflowmode ?? 'scheduled') === 'scheduled') {
            $deadline = match (peerreviewstudio_get_current_phase($this->activity)) {
                'submission' => (int)$this->activity->timeclose,
                'review' => (int)$this->activity->timereviewdue,
                default => 0,
            };
        }

        if (!$deadline) {
            return new overviewitem(name: $name, value: null, content: '-');
        }

        return new overviewitem(name: $name, value: $deadline, content: userdate($deadline));
    }

    #[\Override]
    public function get_actions_overview(): ?overviewitem {
        global $DB, $PAGE;

        if (!has_capability('mod/peerreviewstudio:viewreport', $this->context)) {
            return null;
        }

        $alertcount = 0;
        $filter = $this->get_learner_filter();
        if ($filter !== null) {
            $alertcount = (int)$DB->count_records_sql(
                "SELECT COUNT(s.id)
                   FROM {peerreviewstudio_submissions} s
                  WHERE s.peerreviewstudioid = :activityid
                    AND s.timeescalated > 0
                    AND s.status <> 'closed'
                    AND s.teachergradeoverride IS NULL
                    AND s.userid IN ({$filter['sql']})",
                ['activityid' => $this->activity->id] + $filter['params']
            );
        }

        $name = get_string('view');
        $alertlabel = get_string('overview_needattention', 'mod_peerreviewstudio');
        $text = $name;
        if ($alertcount > 0) {
            $text .= $PAGE->get_renderer('core')->notice_badge(contents: (string)$alertcount, title: $alertlabel);
        }

        return new overviewitem(
            name: get_string('actions'),
            value: $name,
            content: new action_link(
                url: new url('/mod/peerreviewstudio/view.php', ['id' => $this->cm->id]),
                text: $text,
                attributes: ['class' => button::SECONDARY_OUTLINE->classes()],
            ),
            textalign: text_align::CENTER,
            alertcount: $alertcount,
            alertlabel: $alertlabel,
        );
    }

    #[\Override]
    public function get_extra_overview_items(): array {
        return [
            'phase' => $this->get_extra_phase_overview(),
            'submissions' => $this->get_extra_submissions_overview(),
            'reviews' => $this->get_extra_reviews_overview(),
            'mysubmission' => $this->get_extra_mysubmission_overview(),
            'myreviews' => $this->get_extra_myreviews_overview(),
        ];
    }

    /**
     * Current workflow phase, for everybody.
     *
     * @return overviewitem
     */
    private function get_extra_phase_overview(): overviewitem {
        $phase = peerreviewstudio_get_current_phase($this->activity);
        $key = 'phase_' . $phase;
        $content = get_string_manager()->string_exists($key, 'mod_peerreviewstudio')
            ? get_string($key, 'mod_peerreviewstudio')
            : '-';

        return new overviewitem(
            name: get_string('overview_phase', 'mod_peerreviewstudio'),
            value: $phase,
            content: $content,
        );
    }

    /**
     * Learners who submitted, out of the learners who can submit (tutors only).
     *
     * @return overviewitem|null
     */
    private function get_extra_submissions_overview(): ?overviewitem {
        global $DB;

        if (!has_capability('mod/peerreviewstudio:viewreport', $this->context)) {
            return null;
        }

        $name = get_string('overview_submissions', 'mod_peerreviewstudio');
        $filter = $this->get_learner_filter();
        if ($filter === null) {
            return new overviewitem(name: $name, value: 0, content: '-', textalign: text_align::CENTER);
        }

        $total = (int)$DB->count_records_sql("SELECT COUNT(1) FROM ({$filter['sql']}) learners", $filter['params']);
        $submitted = (int)$DB->count_records_sql(
            "SELECT COUNT(s.id)
               FROM {peerreviewstudio_submissions} s
              WHERE s.peerreviewstudioid = :activityid
                AND s.userid IN ({$filter['sql']})",
            ['activityid' => $this->activity->id] + $filter['params']
        );

        if (!$total && !$submitted) {
            return new overviewitem(name: $name, value: 0, content: '-', textalign: text_align::CENTER);
        }

        return new overviewitem(
            name: $name,
            value: $submitted,
            content: get_string('count_of_total', 'core', ['count' => $submitted, 'total' => $total]),
            textalign: text_align::CENTER,
        );
    }

    /**
     * Reviews completed, out of the reviews assigned and not withdrawn (tutors only).
     *
     * @return overviewitem|null
     */
    private function get_extra_reviews_overview(): ?overviewitem {
        global $DB;

        if (!has_capability('mod/peerreviewstudio:viewreport', $this->context)) {
            return null;
        }

        $name = get_string('overview_reviews', 'mod_peerreviewstudio');
        $filter = $this->get_learner_filter();
        if ($filter === null) {
            return new overviewitem(name: $name, value: 0, content: '-', textalign: text_align::CENTER);
        }

        $counts = $DB->get_record_sql(
            "SELECT COUNT(a.id) AS total,
                    COALESCE(SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed
               FROM {peerreviewstudio_assignments} a
               JOIN {peerreviewstudio_submissions} s ON s.id = a.submissionid
              WHERE a.peerreviewstudioid = :activityid
                AND a.status <> 'cancelled'
                AND s.userid IN ({$filter['sql']})",
            ['activityid' => $this->activity->id] + $filter['params']
        );
        $total = (int)($counts->total ?? 0);
        $completed = (int)($counts->completed ?? 0);

        if (!$total) {
            return new overviewitem(name: $name, value: 0, content: '-', textalign: text_align::CENTER);
        }

        return new overviewitem(
            name: $name,
            value: $completed,
            content: get_string('count_of_total', 'core', ['count' => $completed, 'total' => $total]),
            textalign: text_align::CENTER,
        );
    }

    /**
     * Status of the current learner's own submission (learners only).
     *
     * @return overviewitem|null
     */
    private function get_extra_mysubmission_overview(): ?overviewitem {
        global $USER;

        if (!has_capability('mod/peerreviewstudio:submit', $this->context, null, false)) {
            return null;
        }

        $submission = peerreviewstudio_get_user_submission((int)$this->activity->id, (int)$USER->id);
        $status = $submission ? (string)$submission->status : 'notsubmitted';

        return new overviewitem(
            name: get_string('overview_mysubmission', 'mod_peerreviewstudio'),
            value: $status,
            content: peerreviewstudio_status_label($status),
        );
    }

    /**
     * Reviews the current learner completed, out of the reviews required (learners only).
     *
     * @return overviewitem|null
     */
    private function get_extra_myreviews_overview(): ?overviewitem {
        global $USER;

        if (!has_capability('mod/peerreviewstudio:review', $this->context, null, false)) {
            return null;
        }

        $completed = peerreviewstudio_count_completed_reviews((int)$this->activity->id, (int)$USER->id);
        $required = max(1, (int)($this->activity->reviewcountrequired ?? 1));

        return new overviewitem(
            name: get_string('overview_myreviews', 'mod_peerreviewstudio'),
            value: $completed,
            content: get_string('count_of_total', 'core', ['count' => $completed, 'total' => $required]),
            textalign: text_align::CENTER,
        );
    }

    /**
     * Subquery selecting the learners a tutor may see in this activity.
     *
     * Learners are the active participants who can submit. In separate
     * groups mode a tutor without access to every group only counts the
     * learners of their own groups.
     *
     * @return array|null Array with 'sql' and 'params'; null when the tutor sees no group.
     */
    private function get_learner_filter(): ?array {
        if (!$this->filterready) {
            $this->learnerfilter = $this->build_learner_filter();
            $this->filterready = true;
        }
        return $this->learnerfilter;
    }

    /**
     * Build the learner subquery for {@see get_learner_filter()}.
     *
     * @return array|null
     */
    private function build_learner_filter(): ?array {
        $groupids = 0;
        if (
            groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $this->context)
        ) {
            $groupids = array_keys(groups_get_activity_allowed_groups($this->cm));
            if (!$groupids) {
                return null;
            }
        }

        [$sql, $params] = get_enrolled_sql($this->context, 'mod/peerreviewstudio:submit', $groupids, true);
        return ['sql' => $sql, 'params' => $params];
    }
}
