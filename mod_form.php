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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once(__DIR__ . '/locallib.php');

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
        $rubricdefaults = peerreviewstudio_rubric_definition_to_form_defaults(peerreviewstudio_default_rubric_definition());

        if (!empty($this->current->instance)) {
            $rubricdefaults = peerreviewstudio_rubric_definition_to_form_defaults(
                peerreviewstudio_get_activity_rubric((int)$this->current->instance)
            );
        }

        $mform->addElement('header', 'generalhdr', get_string('general', 'form'));
        $mform->addElement('text', 'name', get_string('peerreviewstudioname', 'mod_peerreviewstudio'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        $mform->addElement('header', 'workflowhdr', get_string('workflowsettings', 'mod_peerreviewstudio'));
        $mform->addElement(
            'select',
            'submissiontype',
            get_string('submissiontype', 'mod_peerreviewstudio'),
            peerreviewstudio_submission_type_options(),
        );
        $mform->setDefault('submissiontype', 'file');
        $mform->addHelpButton('submissiontype', 'submissiontype', 'mod_peerreviewstudio');

        $mform->addElement('text', 'reviewcountrequired', get_string('reviewcountrequired', 'mod_peerreviewstudio'));
        $mform->setType('reviewcountrequired', PARAM_INT);
        $mform->setDefault('reviewcountrequired', 1);
        $mform->addHelpButton('reviewcountrequired', 'reviewcountrequired', 'mod_peerreviewstudio');

        $mform->addElement('text', 'reviewsreceivedtarget', get_string('reviewsreceivedtarget', 'mod_peerreviewstudio'));
        $mform->setType('reviewsreceivedtarget', PARAM_INT);
        $mform->setDefault('reviewsreceivedtarget', 1);
        $mform->addHelpButton('reviewsreceivedtarget', 'reviewsreceivedtarget', 'mod_peerreviewstudio');

        $mform->addElement('advcheckbox', 'autoassignenabled', get_string('autoassignenabled', 'mod_peerreviewstudio'));
        $mform->setDefault('autoassignenabled', 1);
        $mform->addHelpButton('autoassignenabled', 'autoassignenabled', 'mod_peerreviewstudio');

        $mform->addElement('select', 'reviewpoolmode', get_string('reviewpoolmode', 'mod_peerreviewstudio'), [
            'any' => get_string('reviewpoolmode_any', 'mod_peerreviewstudio'),
            'samegroup' => get_string('reviewpoolmode_samegroup', 'mod_peerreviewstudio'),
            'differentgroup' => get_string('reviewpoolmode_differentgroup', 'mod_peerreviewstudio'),
        ]);
        $mform->setDefault('reviewpoolmode', 'any');
        $mform->addHelpButton('reviewpoolmode', 'reviewpoolmode', 'mod_peerreviewstudio');

        $mform->addElement('advcheckbox', 'avoidreciprocalreviews', get_string('avoidreciprocalreviews', 'mod_peerreviewstudio'));
        $mform->setDefault('avoidreciprocalreviews', 1);
        $mform->addHelpButton('avoidreciprocalreviews', 'avoidreciprocalreviews', 'mod_peerreviewstudio');

        $mform->addElement('text', 'reviewreminderdays', get_string('reviewreminderdays', 'mod_peerreviewstudio'));
        $mform->setType('reviewreminderdays', PARAM_INT);
        $mform->setDefault('reviewreminderdays', 2);
        $mform->addHelpButton('reviewreminderdays', 'reviewreminderdays', 'mod_peerreviewstudio');

        $mform->addElement('text', 'reviewreassigndays', get_string('reviewreassigndays', 'mod_peerreviewstudio'));
        $mform->setType('reviewreassigndays', PARAM_INT);
        $mform->setDefault('reviewreassigndays', 4);
        $mform->addHelpButton('reviewreassigndays', 'reviewreassigndays', 'mod_peerreviewstudio');

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

        $mform->addElement('date_time_selector', 'timeopen', get_string('timeopen', 'mod_peerreviewstudio'), ['optional' => true]);
        $mform->addHelpButton('timeopen', 'timeopen', 'mod_peerreviewstudio');
        $mform->hideIf('timeopen', 'workflowmode', 'eq', 'manual');
        $mform->hideIf('timeopen', 'workflowmode', 'eq', 'open');

        $mform->addElement(
            'date_time_selector', 'timeclose', get_string('timeclose', 'mod_peerreviewstudio'), ['optional' => true]
        );
        $mform->addHelpButton('timeclose', 'timeclose', 'mod_peerreviewstudio');
        $mform->hideIf('timeclose', 'workflowmode', 'eq', 'manual');
        $mform->hideIf('timeclose', 'workflowmode', 'eq', 'open');

        $mform->addElement(
            'date_time_selector', 'timereviewdue', get_string('timereviewdue', 'mod_peerreviewstudio'), ['optional' => true]
        );
        $mform->addHelpButton('timereviewdue', 'timereviewdue', 'mod_peerreviewstudio');
        $mform->hideIf('timereviewdue', 'workflowmode', 'eq', 'open');
        $mform->hideIf('timereviewdue', 'workflowmode', 'eq', 'manual');

        $mform->addElement('header', 'gradinghdr', get_string('gradingsettings', 'mod_peerreviewstudio'));
        $mform->addElement('text', 'grademaxsubmission', get_string('grademaxsubmission', 'mod_peerreviewstudio'));
        $mform->setType('grademaxsubmission', PARAM_FLOAT);
        $mform->setDefault('grademaxsubmission', 100);
        $mform->addHelpButton('grademaxsubmission', 'grademaxsubmission', 'mod_peerreviewstudio');

        $mform->addElement('text', 'grademaxreview', get_string('grademaxreview', 'mod_peerreviewstudio'));
        $mform->setType('grademaxreview', PARAM_FLOAT);
        $mform->setDefault('grademaxreview', 0);
        $mform->addHelpButton('grademaxreview', 'grademaxreview', 'mod_peerreviewstudio');

        $mform->addElement('header', 'aihdr', get_string('aisettings', 'mod_peerreviewstudio'));
        if (\mod_peerreviewstudio\local\ai\client::is_configured()) {
            $mform->addElement('static', 'aistatus', '', get_string('aiconfiguredstatus', 'mod_peerreviewstudio',
                \mod_peerreviewstudio\local\ai\client::model()));
        } else {
            $mform->addElement('static', 'aistatus', '', get_string('ainotconfigured', 'mod_peerreviewstudio'));
        }
        $mform->addElement('advcheckbox', 'aireviewenabled', get_string('aireviewenabled', 'mod_peerreviewstudio'));
        $mform->setDefault('aireviewenabled', 0);
        $mform->addHelpButton('aireviewenabled', 'aireviewenabled', 'mod_peerreviewstudio');

        $mform->addElement('advcheckbox', 'aifallbackenabled', get_string('aifallbackenabled', 'mod_peerreviewstudio'));
        $mform->setDefault('aifallbackenabled', 0);
        $mform->addHelpButton('aifallbackenabled', 'aifallbackenabled', 'mod_peerreviewstudio');
        $mform->hideIf('aifallbackenabled', 'aireviewenabled', 'eq', 0);

        $mform->addElement('text', 'aigradeweight', get_string('aigradeweight', 'mod_peerreviewstudio'));
        $mform->setType('aigradeweight', PARAM_FLOAT);
        $mform->setDefault('aigradeweight', 30);
        $mform->addHelpButton('aigradeweight', 'aigradeweight', 'mod_peerreviewstudio');
        $mform->hideIf('aigradeweight', 'aireviewenabled', 'eq', 0);

        $mform->addElement(
            'textarea',
            'aipromptsubmission',
            get_string('aipromptsubmission', 'mod_peerreviewstudio'),
            ['rows' => 5, 'cols' => 80]
        );
        $mform->setType('aipromptsubmission', PARAM_TEXT);
        $mform->setDefault('aipromptsubmission', get_string('aipromptsubmission_default', 'mod_peerreviewstudio'));
        $mform->addHelpButton('aipromptsubmission', 'aipromptsubmission', 'mod_peerreviewstudio');
        $mform->hideIf('aipromptsubmission', 'aireviewenabled', 'eq', 0);

        $mform->addElement(
            'textarea',
            'aipromptreviewer',
            get_string('aipromptreviewer', 'mod_peerreviewstudio'),
            ['rows' => 5, 'cols' => 80]
        );
        $mform->setType('aipromptreviewer', PARAM_TEXT);
        $mform->setDefault('aipromptreviewer', get_string('aipromptreviewer_default', 'mod_peerreviewstudio'));
        $mform->addHelpButton('aipromptreviewer', 'aipromptreviewer', 'mod_peerreviewstudio');
        $mform->hideIf('aipromptreviewer', 'aireviewenabled', 'eq', 0);

        $mform->addElement('header', 'rubrichdr', get_string('rubricsettings', 'mod_peerreviewstudio'));
        $mform->addElement(
            'static',
            'rubricpreview',
            get_string('rubricpreview', 'mod_peerreviewstudio'),
            nl2br(s(peerreviewstudio_default_rubric_preview()))
        );

        for ($criterionslot = 1; $criterionslot <= 5; $criterionslot++) {
            $criterionkey = 'rubriccriterionlabel_' . $criterionslot;
            $descriptionkey = 'rubriccriteriondescription_' . $criterionslot;
            $weightkey = 'rubriccriterionweight_' . $criterionslot;

            $mform->addElement(
                'header',
                'rubriccriterionhdr_' . $criterionslot,
                get_string('rubriccriterionheader', 'mod_peerreviewstudio', $criterionslot)
            );
            $mform->setExpanded('rubriccriterionhdr_' . $criterionslot, $criterionslot <= 3);

            $mform->addElement('text', $criterionkey, get_string('rubriccriterionlabel', 'mod_peerreviewstudio'));
            $mform->setType($criterionkey, PARAM_TEXT);
            $mform->setDefault($criterionkey, $rubricdefaults[$criterionkey] ?? '');

            $mform->addElement(
                'textarea',
                $descriptionkey,
                get_string('rubriccriteriondescription', 'mod_peerreviewstudio'),
                ['rows' => 3, 'cols' => 80]
            );
            $mform->setType($descriptionkey, PARAM_TEXT);
            $mform->setDefault($descriptionkey, $rubricdefaults[$descriptionkey] ?? '');

            $mform->addElement('text', $weightkey, get_string('rubriccriterionweight', 'mod_peerreviewstudio'));
            $mform->setType($weightkey, PARAM_FLOAT);
            $mform->setDefault($weightkey, $rubricdefaults[$weightkey] ?? 1);

            for ($levelslot = 1; $levelslot <= 4; $levelslot++) {
                $levelkey = 'rubriclevellabel_' . $criterionslot . '_' . $levelslot;
                $scorekey = 'rubriclevelscore_' . $criterionslot . '_' . $levelslot;
                $levelname = (object)['criterion' => $criterionslot, 'level' => $levelslot];

                $mform->addElement('text', $levelkey, get_string('rubriclevellabel', 'mod_peerreviewstudio', $levelname));
                $mform->setType($levelkey, PARAM_TEXT);
                $mform->setDefault($levelkey, $rubricdefaults[$levelkey] ?? '');

                $mform->addElement('text', $scorekey, get_string('rubriclevelscore', 'mod_peerreviewstudio', $levelname));
                $mform->setType($scorekey, PARAM_FLOAT);
                $mform->setDefault($scorekey, $rubricdefaults[$scorekey] ?? $levelslot);
            }
        }

        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
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

        if (isset($data['reviewcountrequired']) && (int)$data['reviewcountrequired'] < 1) {
            $errors['reviewcountrequired'] = get_string('reviewcountrequiredmin', 'mod_peerreviewstudio');
        }

        if (isset($data['reviewsreceivedtarget']) && (int)$data['reviewsreceivedtarget'] < 1) {
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

        if (isset($data['grademaxsubmission']) && (float)$data['grademaxsubmission'] < 0) {
            $errors['grademaxsubmission'] = get_string('gradecannotbenegative', 'mod_peerreviewstudio');
        }

        if (isset($data['grademaxreview']) && (float)$data['grademaxreview'] < 0) {
            $errors['grademaxreview'] = get_string('gradecannotbenegative', 'mod_peerreviewstudio');
        }

        if (!empty($data['aireviewenabled'])) {
            if (!\mod_peerreviewstudio\local\ai\client::is_configured()) {
                $errors['aireviewenabled'] = get_string('ainotconfigured', 'mod_peerreviewstudio');
            }
            $weight = (float)($data['aigradeweight'] ?? 0);
            if ($weight < 0 || $weight > 100) {
                $errors['aigradeweight'] = get_string('aigradeweightrange', 'mod_peerreviewstudio');
            }
        }

        if (!empty($data['timeopen']) && !empty($data['timeclose']) && (int)$data['timeclose'] < (int)$data['timeopen']) {
            $errors['timeclose'] = get_string('timecloseafteropen', 'mod_peerreviewstudio');
        }

        if (($data['workflowmode'] ?? 'scheduled') === 'scheduled') {
            // Scheduled mode derives the phases from the dates: without a
            // close date the review phase never starts, and without a review
            // due date feedback is never released to authors.
            if (empty($data['timeclose'])) {
                $errors['timeclose'] = get_string('scheduledrequiresclose', 'mod_peerreviewstudio');
            }
            if (empty($data['timereviewdue'])) {
                $errors['timereviewdue'] = get_string('scheduledrequiresreviewdue', 'mod_peerreviewstudio');
            }
            if (!empty($data['timeclose']) && !empty($data['timereviewdue'])
                    && (int)$data['timereviewdue'] < (int)$data['timeclose']) {
                $errors['timereviewdue'] = get_string('timereviewdueafterclose', 'mod_peerreviewstudio');
            }
        }

        $rubricdefinition = peerreviewstudio_extract_rubric_definition_from_form($data);
        if (!$rubricdefinition) {
            $errors['rubriccriterionlabel_1'] = get_string('rubricmusthavecriterion', 'mod_peerreviewstudio');
        }

        foreach ($rubricdefinition as $criterion) {
            if (count($criterion['levels']) < 2) {
                $errors['rubriccriterionlabel_' . $criterion['slot']] =
                    get_string('rubriccriterionminlevels', 'mod_peerreviewstudio');
            }
        }

        // Once peer reviews store rubric answers, the rubric structure is
        // locked: labels and scores may change but criteria and levels
        // cannot be removed without orphaning existing feedback.
        if (!empty($data['instance']) && $rubricdefinition
                && peerreviewstudio_activity_has_rubric_answers((int)$data['instance'])) {
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
