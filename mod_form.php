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
 * Activity settings form for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/locallib.php');

use mod_peerreviewstudio\local\ai\client;

/**
 * Activity form for Peer Review Studio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_peerreviewstudio_mod_form extends moodleform_mod {
    /**
     * Define the activity form.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'generalhdr', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('peerreviewstudioname', 'mod_peerreviewstudio'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $this->add_workflow_elements();
        $this->add_grading_elements();
        $this->add_ai_elements();
        $this->add_rubric_elements();

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Add the submission, matching, follow-up, and workflow settings.
     *
     * @return void
     */
    protected function add_workflow_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'workflowhdr', get_string('workflowsettings', 'mod_peerreviewstudio'));
        $mform->setExpanded('workflowhdr');

        $mform->addElement(
            'select',
            'submissiontype',
            get_string('submissiontype', 'mod_peerreviewstudio'),
            peerreviewstudio_submission_type_options()
        );
        $mform->setDefault('submissiontype', 'file');
        $mform->addHelpButton('submissiontype', 'submissiontype', 'mod_peerreviewstudio');

        $mform->addElement(
            'select',
            'workflowmode',
            get_string('workflowmode', 'mod_peerreviewstudio'),
            peerreviewstudio_workflow_mode_options()
        );
        $mform->setDefault('workflowmode', 'scheduled');
        $mform->addHelpButton('workflowmode', 'workflowmode', 'mod_peerreviewstudio');

        $mform->addElement(
            'select',
            'manualphase',
            get_string('manualphase', 'mod_peerreviewstudio'),
            peerreviewstudio_workflow_phase_options()
        );
        $mform->setDefault('manualphase', 'submission');
        $mform->addHelpButton('manualphase', 'manualphase', 'mod_peerreviewstudio');
        $mform->hideIf('manualphase', 'workflowmode', 'neq', 'manual');

        foreach (['timeopen', 'timeclose', 'timereviewdue'] as $datefield) {
            $mform->addElement(
                'date_time_selector',
                $datefield,
                get_string($datefield, 'mod_peerreviewstudio'),
                ['optional' => true]
            );
            $mform->addHelpButton($datefield, $datefield, 'mod_peerreviewstudio');
            $mform->hideIf($datefield, 'workflowmode', 'neq', 'scheduled');
        }

        $mform->addElement('text', 'reviewcountrequired', get_string('reviewcountrequired', 'mod_peerreviewstudio'), ['size' => 4]);
        $mform->setType('reviewcountrequired', PARAM_INT);
        $mform->setDefault('reviewcountrequired', 2);
        $mform->addHelpButton('reviewcountrequired', 'reviewcountrequired', 'mod_peerreviewstudio');

        $mform->addElement(
            'text',
            'reviewsreceivedtarget',
            get_string('reviewsreceivedtarget', 'mod_peerreviewstudio'),
            ['size' => 4]
        );
        $mform->setType('reviewsreceivedtarget', PARAM_INT);
        $mform->setDefault('reviewsreceivedtarget', 2);
        $mform->addHelpButton('reviewsreceivedtarget', 'reviewsreceivedtarget', 'mod_peerreviewstudio');

        $mform->addElement('advcheckbox', 'autoassignenabled', get_string('autoassignenabled', 'mod_peerreviewstudio'));
        $mform->setDefault('autoassignenabled', 1);
        $mform->addHelpButton('autoassignenabled', 'autoassignenabled', 'mod_peerreviewstudio');

        $mform->addElement(
            'select',
            'reviewpoolmode',
            get_string('reviewpoolmode', 'mod_peerreviewstudio'),
            peerreviewstudio_review_pool_options()
        );
        $mform->setDefault('reviewpoolmode', 'any');
        $mform->addHelpButton('reviewpoolmode', 'reviewpoolmode', 'mod_peerreviewstudio');

        $mform->addElement('advcheckbox', 'avoidreciprocalreviews', get_string('avoidreciprocalreviews', 'mod_peerreviewstudio'));
        $mform->setDefault('avoidreciprocalreviews', 0);
        $mform->addHelpButton('avoidreciprocalreviews', 'avoidreciprocalreviews', 'mod_peerreviewstudio');

        $mform->addElement('text', 'reviewreminderdays', get_string('reviewreminderdays', 'mod_peerreviewstudio'), ['size' => 4]);
        $mform->setType('reviewreminderdays', PARAM_INT);
        $mform->setDefault('reviewreminderdays', 2);
        $mform->addHelpButton('reviewreminderdays', 'reviewreminderdays', 'mod_peerreviewstudio');

        $mform->addElement('text', 'reviewreassigndays', get_string('reviewreassigndays', 'mod_peerreviewstudio'), ['size' => 4]);
        $mform->setType('reviewreassigndays', PARAM_INT);
        $mform->setDefault('reviewreassigndays', 4);
        $mform->addHelpButton('reviewreassigndays', 'reviewreassigndays', 'mod_peerreviewstudio');
    }

    /**
     * Add the grading settings.
     *
     * @return void
     */
    protected function add_grading_elements(): void {
        global $COURSE;

        $mform = $this->_form;

        $mform->addElement('header', 'gradinghdr', get_string('gradingsettings', 'mod_peerreviewstudio'));

        $mform->addElement('float', 'grademaxsubmission', get_string('grademaxsubmission', 'mod_peerreviewstudio'));
        $mform->setDefault('grademaxsubmission', 100);
        $mform->addHelpButton('grademaxsubmission', 'grademaxsubmission', 'mod_peerreviewstudio');

        $mform->addElement('float', 'grademaxreview', get_string('grademaxreview', 'mod_peerreviewstudio'));
        $mform->setDefault('grademaxreview', 0);
        $mform->addHelpButton('grademaxreview', 'grademaxreview', 'mod_peerreviewstudio');

        // Core stores these two on the grade item of the activity.
        $mform->addElement(
            'select',
            'gradecat',
            get_string('gradecategoryonmodform', 'grades'),
            grade_get_categories_menu($COURSE->id)
        );
        $mform->addHelpButton('gradecat', 'gradecategoryonmodform', 'grades');

        $mform->addElement('float', 'gradepass', get_string('gradepass', 'grades'));
        $mform->addHelpButton('gradepass', 'gradepass', 'grades');
        $mform->setDefault('gradepass', '');
    }

    /**
     * Add the optional AI review settings.
     *
     * @return void
     */
    protected function add_ai_elements(): void {
        $mform = $this->_form;

        $mform->addElement('header', 'aihdr', get_string('aisettings', 'mod_peerreviewstudio'));
        $mform->addElement('static', 'aistatus', '', get_string($this->ai_status_key(), 'mod_peerreviewstudio'));

        $mform->addElement('advcheckbox', 'aireviewenabled', get_string('aireviewenabled', 'mod_peerreviewstudio'));
        $mform->setDefault('aireviewenabled', 0);
        $mform->addHelpButton('aireviewenabled', 'aireviewenabled', 'mod_peerreviewstudio');

        $mform->addElement('advcheckbox', 'aifallbackenabled', get_string('aifallbackenabled', 'mod_peerreviewstudio'));
        $mform->setDefault('aifallbackenabled', 0);
        $mform->addHelpButton('aifallbackenabled', 'aifallbackenabled', 'mod_peerreviewstudio');
        $mform->hideIf('aifallbackenabled', 'aireviewenabled', 'notchecked');

        $mform->addElement('float', 'aigradeweight', get_string('aigradeweight', 'mod_peerreviewstudio'));
        $mform->setDefault('aigradeweight', 30);
        $mform->addHelpButton('aigradeweight', 'aigradeweight', 'mod_peerreviewstudio');
        $mform->hideIf('aigradeweight', 'aireviewenabled', 'notchecked');

        foreach (['aipromptsubmission', 'aipromptreviewer'] as $promptfield) {
            $mform->addElement(
                'textarea',
                $promptfield,
                get_string($promptfield, 'mod_peerreviewstudio'),
                ['rows' => 5, 'cols' => 80]
            );
            $mform->setType($promptfield, PARAM_TEXT);
            $mform->setDefault($promptfield, get_string($promptfield . '_default', 'mod_peerreviewstudio'));
            $mform->addHelpButton($promptfield, $promptfield, 'mod_peerreviewstudio');
            $mform->hideIf($promptfield, 'aireviewenabled', 'notchecked');
        }
    }

    /**
     * Language string that describes whether AI review can be used in this course.
     *
     * @return string String key: available, turned off for the course, or no provider.
     */
    protected function ai_status_key(): string {
        $client = client::instance();
        if (!$client->is_allowed_in_course((int)$this->get_course()->id)) {
            return 'aidisabledincourse';
        }

        return $client->is_available() ? 'aiavailablestatus' : 'ainotconfigured';
    }

    /**
     * Add the rubric editor: up to 5 criteria with up to 4 levels each.
     *
     * @return void
     */
    protected function add_rubric_elements(): void {
        $mform = $this->_form;

        $definition = peerreviewstudio_default_rubric_definition();
        if (!empty($this->current->instance)) {
            $definition = peerreviewstudio_get_activity_rubric((int)$this->current->instance);
        }
        $rubricdefaults = peerreviewstudio_rubric_definition_to_form_defaults($definition);

        $mform->addElement('header', 'rubrichdr', get_string('rubricsettings', 'mod_peerreviewstudio'));
        $mform->addElement('static', 'rubricintro', '', get_string('rubricintro', 'mod_peerreviewstudio'));

        for ($criterionslot = 1; $criterionslot <= 5; $criterionslot++) {
            $criterionkey = 'rubriccriterionlabel_' . $criterionslot;
            $descriptionkey = 'rubriccriteriondescription_' . $criterionslot;
            $weightkey = 'rubriccriterionweight_' . $criterionslot;

            $mform->addElement(
                'header',
                'rubriccriterionhdr_' . $criterionslot,
                get_string('rubriccriterionheader', 'mod_peerreviewstudio', $criterionslot)
            );
            $mform->setExpanded('rubriccriterionhdr_' . $criterionslot, isset($rubricdefaults[$criterionkey]));

            $mform->addElement('text', $criterionkey, get_string('rubriccriterionlabel', 'mod_peerreviewstudio'), ['size' => 64]);
            $mform->setType($criterionkey, PARAM_TEXT);
            $mform->addRule($criterionkey, get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
            $mform->setDefault($criterionkey, $rubricdefaults[$criterionkey] ?? '');

            $mform->addElement(
                'textarea',
                $descriptionkey,
                get_string('rubriccriteriondescription', 'mod_peerreviewstudio'),
                ['rows' => 3, 'cols' => 80]
            );
            $mform->setType($descriptionkey, PARAM_TEXT);
            $mform->setDefault($descriptionkey, $rubricdefaults[$descriptionkey] ?? '');

            $mform->addElement('float', $weightkey, get_string('rubriccriterionweight', 'mod_peerreviewstudio'));
            $mform->setDefault($weightkey, $rubricdefaults[$weightkey] ?? 1);

            for ($levelslot = 1; $levelslot <= 4; $levelslot++) {
                $levelkey = 'rubriclevellabel_' . $criterionslot . '_' . $levelslot;
                $scorekey = 'rubriclevelscore_' . $criterionslot . '_' . $levelslot;
                $levelname = (object)['criterion' => $criterionslot, 'level' => $levelslot];

                $mform->addElement(
                    'text',
                    $levelkey,
                    get_string('rubriclevellabel', 'mod_peerreviewstudio', $levelname),
                    ['size' => 48]
                );
                $mform->setType($levelkey, PARAM_TEXT);
                $mform->addRule($levelkey, get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
                $mform->setDefault($levelkey, $rubricdefaults[$levelkey] ?? '');

                $mform->addElement('float', $scorekey, get_string('rubriclevelscore', 'mod_peerreviewstudio', $levelname));
                $mform->setDefault($scorekey, $rubricdefaults[$scorekey] ?? $levelslot);
            }
        }
    }

    /**
     * Add the custom completion rules.
     *
     * @return string[] Names of the elements added.
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $submit = 'completionsubmit' . $suffix;
        $mform->addElement('checkbox', $submit, '', get_string('completionsubmit', 'mod_peerreviewstudio'));

        $reviews = 'completionreviews' . $suffix;
        $mform->addElement('checkbox', $reviews, '', get_string('completionreviews', 'mod_peerreviewstudio'));

        return [$submit, $reviews];
    }

    /**
     * Whether at least one custom completion rule is enabled.
     *
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        $suffix = $this->get_suffix();

        return !empty($data['completionsubmit' . $suffix]) || !empty($data['completionreviews' . $suffix]);
    }

    /**
     * Store unticked completion rules as disabled.
     *
     * @param stdClass $data Submitted form data.
     * @return void
     */
    public function data_postprocessing($data): void {
        parent::data_postprocessing($data);

        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            foreach (['completionsubmit', 'completionreviews'] as $rule) {
                if (empty($data->{$rule . $suffix})) {
                    $data->{$rule . $suffix} = 0;
                }
            }
        }
    }

    /**
     * Show the default AI prompts when the stored values are empty.
     *
     * An empty stored prompt means "use the plugin default", so the form
     * surfaces that default instead of a blank field the teacher would
     * have to reconstruct.
     *
     * @param array $defaultvalues Form default values (passed by reference).
     * @return void
     */
    public function data_preprocessing(&$defaultvalues): void {
        parent::data_preprocessing($defaultvalues);

        foreach (['aipromptsubmission', 'aipromptreviewer'] as $promptfield) {
            if (trim((string)($defaultvalues[$promptfield] ?? '')) === '') {
                $defaultvalues[$promptfield] = get_string($promptfield . '_default', 'mod_peerreviewstudio');
            }
        }

        // Stored decimals come padded from the database (100.00000).
        foreach (['grademaxsubmission', 'grademaxreview', 'aigradeweight'] as $numberfield) {
            if (isset($defaultvalues[$numberfield])) {
                $defaultvalues[$numberfield] = (float)$defaultvalues[$numberfield];
            }
        }
    }

    /**
     * Validate submitted activity data.
     *
     * @param array $data Submitted form data.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if ((int)($data['reviewcountrequired'] ?? 0) < 1) {
            $errors['reviewcountrequired'] = get_string('reviewcountrequiredmin', 'mod_peerreviewstudio');
        }
        if ((int)($data['reviewsreceivedtarget'] ?? 0) < 1) {
            $errors['reviewsreceivedtarget'] = get_string('reviewsreceivedtargetmin', 'mod_peerreviewstudio');
        }

        $reminderdays = (int)($data['reviewreminderdays'] ?? 0);
        $reassigndays = (int)($data['reviewreassigndays'] ?? 0);
        if ($reminderdays < 0) {
            $errors['reviewreminderdays'] = get_string('followupdaysnonnegative', 'mod_peerreviewstudio');
        }
        if ($reassigndays < 0) {
            $errors['reviewreassigndays'] = get_string('followupdaysnonnegative', 'mod_peerreviewstudio');
        }
        if ($reminderdays > 0 && $reassigndays > 0 && $reassigndays <= $reminderdays) {
            $errors['reviewreassigndays'] = get_string('reassignafterreminder', 'mod_peerreviewstudio');
        }

        $submissionmax = (float)($data['grademaxsubmission'] ?? 0);
        $reviewmax = (float)($data['grademaxreview'] ?? 0);
        if ($submissionmax < 0) {
            $errors['grademaxsubmission'] = get_string('gradecannotbenegative', 'mod_peerreviewstudio');
        }
        if ($reviewmax < 0) {
            $errors['grademaxreview'] = get_string('gradecannotbenegative', 'mod_peerreviewstudio');
        }
        if (empty($errors['gradepass']) && (float)($data['gradepass'] ?? 0) > $submissionmax + $reviewmax) {
            $errors['gradepass'] = get_string('gradepassgreaterthangrade', 'grades', $submissionmax + $reviewmax);
        }

        if (!empty($data['aireviewenabled'])) {
            $aistatus = $this->ai_status_key();
            if ($aistatus !== 'aiavailablestatus') {
                $errors['aireviewenabled'] = get_string($aistatus, 'mod_peerreviewstudio');
            }
            $weight = (float)($data['aigradeweight'] ?? 0);
            if ($weight < 0 || $weight > 100) {
                $errors['aigradeweight'] = get_string('aigradeweightrange', 'mod_peerreviewstudio');
            }
        }

        if (($data['workflowmode'] ?? 'scheduled') === 'scheduled') {
            // Scheduled mode derives the phases from the dates: without a
            // close date the review phase never starts, and without a review
            // due date feedback is never released to authors.
            if (empty($data['timeclose'])) {
                $errors['timeclose'] = get_string('scheduledrequiresclose', 'mod_peerreviewstudio');
            } else if (!empty($data['timeopen']) && (int)$data['timeclose'] <= (int)$data['timeopen']) {
                $errors['timeclose'] = get_string('timecloseafteropen', 'mod_peerreviewstudio');
            }
            if (empty($data['timereviewdue'])) {
                $errors['timereviewdue'] = get_string('scheduledrequiresreviewdue', 'mod_peerreviewstudio');
            } else if (!empty($data['timeclose']) && (int)$data['timereviewdue'] <= (int)$data['timeclose']) {
                $errors['timereviewdue'] = get_string('timereviewdueafterclose', 'mod_peerreviewstudio');
            }
        }

        return $errors + $this->validate_rubric($data);
    }

    /**
     * Validate the rubric part of the form.
     *
     * @param array $data Submitted form data.
     * @return array Errors keyed by form field.
     */
    protected function validate_rubric(array $data): array {
        $errors = [];

        $rubricdefinition = peerreviewstudio_extract_rubric_definition_from_form($data);
        if (!$rubricdefinition) {
            $errors['rubriccriterionlabel_1'] = get_string('rubricmusthavecriterion', 'mod_peerreviewstudio');
            return $errors;
        }

        foreach ($rubricdefinition as $criterion) {
            $field = 'rubriccriterionlabel_' . $criterion['slot'];
            if (count($criterion['levels']) < 2) {
                $errors[$field] = get_string('rubriccriterionminlevels', 'mod_peerreviewstudio');
                continue;
            }
            $scores = array_column($criterion['levels'], 'score');
            if (min($scores) < 0 || max($scores) <= 0) {
                // A criterion nobody can score points on would never count.
                $errors[$field] = get_string('rubriccriterionneedsscore', 'mod_peerreviewstudio');
            }
        }

        // Once peer reviews store rubric answers, the rubric structure is
        // locked: labels and scores may change but criteria and levels
        // cannot be removed without orphaning existing feedback.
        if (!empty($data['instance']) && peerreviewstudio_activity_has_rubric_answers((int)$data['instance'])) {
            $current = peerreviewstudio_get_activity_rubric((int)$data['instance']);
            if (count($rubricdefinition) < count($current)) {
                $errors['rubriccriterionlabel_1'] = get_string('rubriclockedstructure', 'mod_peerreviewstudio');
            } else {
                foreach (array_values($current) as $index => $existingcriterion) {
                    if (count($rubricdefinition[$index]['levels']) < count($existingcriterion['levels'])) {
                        $errors['rubriccriterionlabel_' . $rubricdefinition[$index]['slot']] =
                            get_string('rubriclockedstructure', 'mod_peerreviewstudio');
                    }
                }
            }
        }

        return $errors;
    }
}
