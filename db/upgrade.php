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
 * Upgrade script for mod_peerreviewstudio.
 *
 * @package     mod_peerreviewstudio
 * @copyright   2026 Julio Molpeceres
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade function.
 *
 * @param int $oldversion Old version.
 * @return bool
 */
function xmldb_peerreviewstudio_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026051400) {
        $table = new xmldb_table('peerreviewstudio');

        $field = new xmldb_field('workflowmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'scheduled', 'timereviewdue');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('manualphase', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'submission', 'workflowmode');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026051400, 'peerreviewstudio');
    }

    if ($oldversion < 2026051401) {
        $table = new xmldb_table('peerreviewstudio_submissions');

        $field = new xmldb_field('submissiongrade', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'timemodified');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('reviewgrade', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'submissiongrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('finalgrade', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'reviewgrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field('teachergradeoverride', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'finalgrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026051401, 'peerreviewstudio');
    }

    if ($oldversion < 2026051402) {
        $table = new xmldb_table('peerreviewstudio');

        $field = new xmldb_field('reviewpoolmode', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'any', 'autoassignenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $field = new xmldb_field(
            'avoidreciprocalreviews', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'reviewpoolmode'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026051402, 'peerreviewstudio');
    }

    if ($oldversion < 2026051700) {
        upgrade_mod_savepoint(true, 2026051700, 'peerreviewstudio');
    }

    if ($oldversion < 2026051701) {
        upgrade_mod_savepoint(true, 2026051701, 'peerreviewstudio');
    }

    if ($oldversion < 2026070201) {
        $table = new xmldb_table('peerreviewstudio_queue');

        $index = new xmldb_index('activitystatusix', XMLDB_INDEX_NOTUNIQUE, ['peerreviewstudioid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('submissionstatusix', XMLDB_INDEX_NOTUNIQUE, ['submissionid', 'status']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_mod_savepoint(true, 2026070201, 'peerreviewstudio');
    }

    if ($oldversion < 2026070402) {
        // Earlier releases shipped without student-facing capabilities
        // granted by default. Moodle only auto-assigns archetype defaults
        // the first time a capability is introduced, so existing installs
        // never retroactively received the corrected access.php defaults.
        // Re-assign here, but only for roles that do not already have an
        // explicit override, so a site administrator's own choices are
        // never clobbered.
        $studentcapabilities = [
            'mod/peerreviewstudio:view',
            'mod/peerreviewstudio:submit',
            'mod/peerreviewstudio:review',
            'mod/peerreviewstudio:viewownstatus',
        ];
        $systemcontext = \context_system::instance();
        $studentroles = $DB->get_records('role', ['archetype' => 'student']);
        foreach ($studentroles as $role) {
            foreach ($studentcapabilities as $capability) {
                $hasoverride = $DB->record_exists('role_capabilities', [
                    'roleid' => $role->id,
                    'capability' => $capability,
                ]);
                if (!$hasoverride) {
                    assign_capability($capability, CAP_ALLOW, $role->id, $systemcontext->id);
                }
            }
        }
        $systemcontext->mark_dirty();

        upgrade_mod_savepoint(true, 2026070402, 'peerreviewstudio');
    }

    if ($oldversion < 2026070405) {
        // Optional AI second-review support.
        $table = new xmldb_table('peerreviewstudio');
        $field = new xmldb_field('aireviewenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'manualphase');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aigradeweight', XMLDB_TYPE_NUMBER, '10,5', null, XMLDB_NOTNULL, null, '30', 'aireviewenabled');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('peerreviewstudio_submissions');
        $field = new xmldb_field('aigrade', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'teachergradeoverride');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aifeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aigrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aitimegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'aifeedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('peerreviewstudio_reviews');
        $field = new xmldb_field('aireviewergrade', XMLDB_TYPE_NUMBER, '10,5', null, null, null, null, 'grade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aireviewerfeedback', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aireviewergrade');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aitimegraded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'aireviewerfeedback');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070405, 'peerreviewstudio');
    }

    if ($oldversion < 2026070406) {
        // Per-activity AI prompts: the grading instructions move from the
        // site-wide plugin settings into each activity so teachers control
        // them. Empty means "use the plugin default prompt".
        $table = new xmldb_table('peerreviewstudio');
        $field = new xmldb_field('aipromptsubmission', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aigradeweight');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aipromptreviewer', XMLDB_TYPE_TEXT, null, null, null, null, null, 'aipromptsubmission');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Carry over any site-wide prompts an admin had customised, so the
        // behaviour of existing activities does not silently change.
        $sitesubmissionprompt = trim((string)get_config('mod_peerreviewstudio', 'aipromptsubmission'));
        $sitereviewerprompt = trim((string)get_config('mod_peerreviewstudio', 'aipromptreviewer'));
        if ($sitesubmissionprompt !== '') {
            $DB->set_field('peerreviewstudio', 'aipromptsubmission', $sitesubmissionprompt);
        }
        if ($sitereviewerprompt !== '') {
            $DB->set_field('peerreviewstudio', 'aipromptreviewer', $sitereviewerprompt);
        }
        unset_config('aipromptsubmission', 'mod_peerreviewstudio');
        unset_config('aipromptreviewer', 'mod_peerreviewstudio');

        upgrade_mod_savepoint(true, 2026070406, 'peerreviewstudio');
    }

    if ($oldversion < 2026070407) {
        // Workflow notifications: the new message providers in db/messages.php
        // (submission receipt, review assigned, grade received) are installed
        // by core during this upgrade; no schema change is needed.
        upgrade_mod_savepoint(true, 2026070407, 'peerreviewstudio');
    }

    if ($oldversion < 2026070408) {
        // Automatic follow-up of unresolved reviews: per-activity reminder
        // and reassignment day thresholds, optional AI fallback review,
        // per-assignment reminder timestamp, and per-submission escalation
        // timestamp.
        $table = new xmldb_table('peerreviewstudio');
        $field = new xmldb_field('reviewreminderdays', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'avoidreciprocalreviews');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('reviewreassigndays', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '4', 'reviewreminderdays');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('aifallbackenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'aigradeweight');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('peerreviewstudio_assignments');
        $field = new xmldb_field('timereminded', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timeassigned');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('peerreviewstudio_submissions');
        $field = new xmldb_field('timeescalated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'aitimegraded');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070408, 'peerreviewstudio');
    }

    if ($oldversion < 2026111200) {
        // Up to 0.9.11 mod/peerreviewstudio:view cloned its permissions from
        // moodle/course:view, which only managers hold, so the archetype defaults
        // were never applied and students and teachers could not open the activity.
        // Default permissions are only set at install time: grant the missing ones
        // here, leaving alone any role an administrator has already configured.
        $systemcontext = context_system::instance();
        foreach (['student', 'teacher', 'editingteacher'] as $archetype) {
            foreach (get_archetype_roles($archetype) as $role) {
                $configured = $DB->record_exists('role_capabilities', [
                    'roleid' => $role->id,
                    'capability' => 'mod/peerreviewstudio:view',
                    'contextid' => $systemcontext->id,
                ]);
                if (!$configured) {
                    assign_capability('mod/peerreviewstudio:view', CAP_ALLOW, $role->id, $systemcontext->id);
                }
            }
        }

        upgrade_mod_savepoint(true, 2026111200, 'peerreviewstudio');
    }

    return true;
}
