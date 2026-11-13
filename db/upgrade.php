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
            'avoidreciprocalreviews',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '1',
            'reviewpoolmode'
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
        $field = new xmldb_field(
            'reviewreminderdays',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '2',
            'avoidreciprocalreviews'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field(
            'reviewreassigndays',
            XMLDB_TYPE_INTEGER,
            '4',
            null,
            XMLDB_NOTNULL,
            null,
            '4',
            'reviewreminderdays'
        );
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

    if ($oldversion < 2026111300) {
        // Activity completion rules and the grade release marker.
        $table = new xmldb_table('peerreviewstudio');
        $field = new xmldb_field('completionsubmit', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'aipromptreviewer');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('completionreviews', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'completionsubmit');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field(
            'timegradesreleased',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'completionreviews'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // When the author was told their feedback and grade are available.
        $table = new xmldb_table('peerreviewstudio_submissions');
        $field = new xmldb_field('timegradenotified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timeescalated');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Authors who already have reviews were notified by the previous
            // per-review message: they must not be notified again.
            $DB->execute(
                "UPDATE {peerreviewstudio_submissions}
                    SET timegradenotified = timemodified
                  WHERE id IN (SELECT a.submissionid
                                 FROM {peerreviewstudio_assignments} a
                                 JOIN {peerreviewstudio_reviews} r ON r.assignmentid = a.id
                                WHERE r.status = 'submitted')"
            );
        }

        // When the reviewer was told about the assignment.
        $table = new xmldb_table('peerreviewstudio_assignments');
        $field = new xmldb_field('timenotified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'timeassigned');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
            // Until now reviewers were always notified at assignment time.
            $DB->execute("UPDATE {peerreviewstudio_assignments} SET timenotified = timeassigned");
        }

        upgrade_mod_savepoint(true, 2026111300, 'peerreviewstudio');
    }

    if ($oldversion < 2026111301) {
        // Replace the low-value single-column status indexes with the
        // composite indexes the queries actually use.
        $indexchanges = [
            'peerreviewstudio_submissions' => [
                'drop' => [['statusix', ['status']]],
                'add' => [['activitystatusix', ['peerreviewstudioid', 'status']], ['userix', ['userid']]],
            ],
            'peerreviewstudio_assignments' => [
                'drop' => [['statusix', ['status']]],
                'add' => [
                    ['activityreviewerix', ['peerreviewstudioid', 'reviewerid', 'status']],
                    ['activitystatusix', ['peerreviewstudioid', 'status']],
                ],
            ],
            'peerreviewstudio_reviews' => [
                'drop' => [['statusix', ['status']]],
                'add' => [['reviewerix', ['reviewerid']]],
            ],
            'peerreviewstudio_queue' => [
                'drop' => [['statusix', ['status']]],
                'add' => [],
            ],
        ];
        foreach ($indexchanges as $tablename => $changes) {
            $table = new xmldb_table($tablename);
            foreach ($changes['drop'] as [$indexname, $indexfields]) {
                $index = new xmldb_index($indexname, XMLDB_INDEX_NOTUNIQUE, $indexfields);
                if ($dbman->index_exists($table, $index)) {
                    $dbman->drop_index($table, $index);
                }
            }
            foreach ($changes['add'] as [$indexname, $indexfields]) {
                $index = new xmldb_index($indexname, XMLDB_INDEX_NOTUNIQUE, $indexfields);
                if (!$dbman->index_exists($table, $index)) {
                    $dbman->add_index($table, $index);
                }
            }
        }

        // Foreign keys on the rubric answers; each one is backed by an index.
        $table = new xmldb_table('peerreviewstudio_r_answers');
        $keys = [
            'criterionfk' => ['criterionid', 'peerreviewstudio_r_criteria'],
            'levelfk' => ['levelid', 'peerreviewstudio_r_levels'],
        ];
        foreach ($keys as $keyname => [$keyfield, $reftable]) {
            $index = new xmldb_index($keyname, XMLDB_INDEX_NOTUNIQUE, [$keyfield]);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_key($table, new xmldb_key($keyname, XMLDB_KEY_FOREIGN, [$keyfield], $reftable, ['id']));
            }
        }

        upgrade_mod_savepoint(true, 2026111301, 'peerreviewstudio');
    }

    if ($oldversion < 2026111302) {
        // The module capabilities used to clone their permissions from core
        // capabilities, which overrode the archetype defaults (for example,
        // guests could see their status and non-editing teachers could not
        // override grades). Bring every archetype role in line with the
        // defaults declared in db/access.php.
        $defaults = [
            'mod/peerreviewstudio:view' => ['student', 'teacher', 'editingteacher', 'manager'],
            'mod/peerreviewstudio:submit' => ['student'],
            'mod/peerreviewstudio:review' => ['student'],
            'mod/peerreviewstudio:viewownstatus' => ['student'],
            'mod/peerreviewstudio:viewreport' => ['teacher', 'editingteacher', 'manager'],
            'mod/peerreviewstudio:manageassignments' => ['teacher', 'editingteacher', 'manager'],
            'mod/peerreviewstudio:grade' => ['teacher', 'editingteacher', 'manager'],
            'mod/peerreviewstudio:manageworkflow' => ['teacher', 'editingteacher', 'manager'],
        ];
        $systemcontext = context_system::instance();
        foreach ($DB->get_records('role') as $role) {
            if ($role->archetype === '') {
                // Custom roles without an archetype were configured by hand.
                continue;
            }
            foreach ($defaults as $capability => $archetypes) {
                if (in_array($role->archetype, $archetypes, true)) {
                    assign_capability($capability, CAP_ALLOW, $role->id, $systemcontext->id, true);
                } else {
                    unassign_capability($capability, $role->id, $systemcontext->id);
                }
            }
        }

        upgrade_mod_savepoint(true, 2026111302, 'peerreviewstudio');
    }

    if ($oldversion < 2026111303) {
        // Submission status now only describes the submission itself; the
        // reviewing its author does for others is read from the assignments.
        // The retired 'reviewed' value is replaced by the status derived
        // from the reviews each submission actually received.
        $DB->execute(
            "UPDATE {peerreviewstudio_submissions}
                SET status = 'partiallyreviewed'
              WHERE status = 'reviewed'
                AND id IN (SELECT a.submissionid
                             FROM {peerreviewstudio_assignments} a
                             JOIN {peerreviewstudio_reviews} r ON r.assignmentid = a.id
                            WHERE r.status = 'submitted')"
        );
        $DB->execute(
            "UPDATE {peerreviewstudio_submissions}
                SET status = 'assigned'
              WHERE status = 'reviewed'
                AND id IN (SELECT a.submissionid
                             FROM {peerreviewstudio_assignments} a
                            WHERE a.status = 'assigned')"
        );
        $DB->set_field('peerreviewstudio_submissions', 'status', 'submitted', ['status' => 'reviewed']);

        upgrade_mod_savepoint(true, 2026111303, 'peerreviewstudio');
    }

    if ($oldversion < 2026111304) {
        // Bring every stored status in line with the new meaning: how far the
        // submission is from having received its reviews. Closed submissions
        // are left alone; the others are rebuilt from the stored facts, from
        // the earliest state to the latest.
        $submissions = '{peerreviewstudio_submissions}';
        $DB->execute("UPDATE $submissions SET status = 'submitted' WHERE status <> 'closed'");
        $DB->execute(
            "UPDATE $submissions
                SET status = 'queued'
              WHERE status = 'submitted'
                AND EXISTS (SELECT 1
                              FROM {peerreviewstudio_queue} q
                             WHERE q.submissionid = $submissions.id
                               AND q.status = 'pending')"
        );
        $DB->execute(
            "UPDATE $submissions
                SET status = 'assigned'
              WHERE status IN ('submitted', 'queued')
                AND EXISTS (SELECT 1
                              FROM {peerreviewstudio_assignments} a
                             WHERE a.submissionid = $submissions.id
                               AND a.status = 'assigned')"
        );
        $DB->execute(
            "UPDATE $submissions
                SET status = 'partiallyreviewed'
              WHERE status <> 'closed'
                AND EXISTS (SELECT 1
                              FROM {peerreviewstudio_assignments} a
                              JOIN {peerreviewstudio_reviews} r ON r.assignmentid = a.id
                             WHERE a.submissionid = $submissions.id
                               AND r.status = 'submitted')"
        );

        upgrade_mod_savepoint(true, 2026111304, 'peerreviewstudio');
    }

    if ($oldversion < 2026111305) {
        // AI review now goes through the Moodle AI subsystem. The plugin's
        // own provider, API key, and model settings are no longer used, and
        // an API key must not be left behind in the database.
        $obsolete = ['aiprovider', 'aiapikey'];
        foreach (['openai', 'anthropic', 'gemini', 'deepseek'] as $provider) {
            $obsolete[] = 'aiapikey_' . $provider;
            $obsolete[] = 'aimodel_' . $provider;
            $obsolete[] = 'aimodelcustom_' . $provider;
        }
        foreach ($obsolete as $name) {
            unset_config($name, 'mod_peerreviewstudio');
        }

        upgrade_mod_savepoint(true, 2026111305, 'peerreviewstudio');
    }

    return true;
}
