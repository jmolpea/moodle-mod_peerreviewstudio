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

namespace mod_peerreviewstudio\local\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Review form for assigned peer feedback.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class review_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $customdata = $this->_customdata;

        if (!empty($customdata['rubric']) && is_array($customdata['rubric'])) {
            foreach ($customdata['rubric'] as $criterion) {
                // Rubric labels and descriptions are teacher-entered content
                // (and may arrive via course restore), so escape them before
                // they reach form elements that render raw HTML.
                $mform->addElement('header', 'criterionhdr_' . $criterion['id'], s((string)$criterion['label']));
                if (!empty($criterion['description'])) {
                    $mform->addElement('static', 'criteriondesc_' . $criterion['id'], '', s((string)$criterion['description']));
                }
                $options = [0 => get_string('choosedots')];
                foreach ($criterion['levels'] as $level) {
                    $options[(int)$level['id']] = s((string)$level['label']) . ' (' . format_float((float)$level['score'], 2) . ')';
                }
                $fieldname = 'criterion_' . $criterion['id'];
                $mform->addElement('select', $fieldname, get_string('rubriccriteriongrade', 'mod_peerreviewstudio'), $options);
                $mform->setType($fieldname, PARAM_INT);
                $mform->addRule($fieldname, null, 'required', null, 'client');

                $commentfield = 'criterioncomment_' . $criterion['id'];
                $mform->addElement('textarea', $commentfield, get_string('rubriccriterioncomment', 'mod_peerreviewstudio'), [
                    'rows' => 3,
                    'cols' => 80,
                ]);
                $mform->setType($commentfield, PARAM_TEXT);
            }
        }

        $mform->addElement('textarea', 'generalcomment', get_string('reviewgeneralcomment', 'mod_peerreviewstudio'), [
            'rows' => 10,
            'cols' => 80,
        ]);
        $mform->setType('generalcomment', PARAM_TEXT);
        $mform->addRule('generalcomment', null, 'required', null, 'client');

        $mform->addElement(
            'static',
            'rubricgradeinfo',
            get_string('reviewgrade', 'mod_peerreviewstudio'),
            get_string('rubricgradecalculated', 'mod_peerreviewstudio')
        );

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'assignmentid', $customdata['assignmentid']);
        $mform->setType('assignmentid', PARAM_INT);

        $this->add_action_buttons(false, get_string('submitreview', 'mod_peerreviewstudio'));
    }

    /**
     * Ensure every rubric criterion has a level selected that belongs to it.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        foreach (($this->_customdata['rubric'] ?? []) as $criterion) {
            $field = 'criterion_' . $criterion['id'];
            $selected = (int)($data[$field] ?? 0);
            $validlevelids = array_map(static fn($level): int => (int)$level['id'], $criterion['levels']);
            if (!in_array($selected, $validlevelids, true)) {
                $errors[$field] = get_string('reviewformselectlevel', 'mod_peerreviewstudio');
            }
        }

        return $errors;
    }
}
