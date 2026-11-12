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
 * Submission form for learner deliveries.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submission_form extends \moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    public function definition(): void {
        $mform = $this->_form;
        $customdata = $this->_customdata;
        $submissiontype = $customdata['submissiontype'] ?? 'onlinetext';

        if ($submissiontype === 'onlinetext' || $submissiontype === 'both') {
            $mform->addElement(
                'editor',
                'submissiontext_editor',
                get_string('submissiontext', 'mod_peerreviewstudio'),
                ['rows' => 12],
                ['maxfiles' => 0, 'context' => $customdata['context']]
            );
            $mform->setType('submissiontext_editor', PARAM_RAW);
            if ($submissiontype === 'onlinetext') {
                $mform->addRule('submissiontext_editor', null, 'required', null, 'client');
            }
        }

        if ($submissiontype === 'file' || $submissiontype === 'both') {
            $options = [
                'subdirs' => 0,
                'maxfiles' => PEERREVIEWSTUDIO_MAXFILES,
                'maxbytes' => $customdata['maxbytes'] ?? 0,
                'accepted_types' => '*',
            ];
            $mform->addElement(
                'filemanager',
                'submissionfiles_filemanager',
                get_string('submissionfiles', 'mod_peerreviewstudio'),
                null,
                $options
            );
            if ($submissiontype === 'file') {
                $mform->addRule('submissionfiles_filemanager', null, 'required', null, 'client');
            }
            $mform->addHelpButton('submissionfiles_filemanager', 'submissionfiles', 'mod_peerreviewstudio');
        }

        $mform->addElement('hidden', 'id', $customdata['cmid']);
        $mform->setType('id', PARAM_INT);

        $submitlabel = !empty($customdata['editing'])
            ? get_string('updatemywork', 'mod_peerreviewstudio')
            : get_string('submitmywork', 'mod_peerreviewstudio');
        $this->add_action_buttons(false, $submitlabel);
    }

    /**
     * Validate submitted data.
     *
     * @param array $data Submitted data.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $submissiontype = $this->_customdata['submissiontype'] ?? 'onlinetext';

        $draftitemid = (int)($data['submissionfiles_filemanager'] ?? 0);
        $draftinfo = $draftitemid > 0 ? file_get_draft_area_info($draftitemid) : ['filecount' => 0];
        $hasfiles = !empty($draftinfo['filecount']);
        $hastext = trim(strip_tags((string)($data['submissiontext_editor']['text'] ?? ''), '<img>')) !== '';

        if ($submissiontype === 'file' && !$hasfiles) {
            $errors['submissionfiles_filemanager'] = get_string('submissionrequirefile', 'mod_peerreviewstudio');
        }

        if ($submissiontype === 'onlinetext' && !$hastext) {
            $errors['submissiontext_editor'] = get_string('submissionrequiretext', 'mod_peerreviewstudio');
        }

        if ($submissiontype === 'both' && !$hastext && !$hasfiles) {
            $errors['submissiontext_editor'] = get_string('submissionrequiretextorfile', 'mod_peerreviewstudio');
        }

        return $errors;
    }
}
