<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade functions for Learnwise
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\util;

/**
 * Sync new defined capabilites to api user
 *
 * @return void
 */
function local_learnwise_upgrade_sync_role_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/accesslib.php');

    update_capabilities(constants::COMPONENT);

    $role = util::get_or_create_role();

    foreach (util::ROLECAPS as $capability) {
        assign_capability(
            $capability,
            CAP_ALLOW,
            $role->id,
            SYSCONTEXTID,
            true
        );
    }
}

/**
 * Replace legacy user OAuth credentials with hashes, preserving all other metadata.
 *
 * The marker is updated with the value so interrupted upgrades can safely resume.
 * Client secrets and Moodle core webservice tokens are deliberately untouched.
 *
 * @return void
 */
function local_learnwise_upgrade_hash_user_tokens() {
    global $DB;
    $tables = [
        'local_learnwise_authcode' => 'code',
        'local_learnwise_accesstoken' => 'token',
        'local_learnwise_refreshtoken' => 'token',
    ];
    foreach ($tables as $table => $field) {
        $lastid = 0;
        while (
            $records = $DB->get_records_select(
                $table,
                'tokenhashed = 0 AND id > :lastid',
                ['lastid' => $lastid],
                'id ASC',
                "id, {$field}",
                0,
                500
            )
        ) {
            foreach ($records as $record) {
                $lastid = $record->id;
                $record->$field = hash('sha256', $record->$field);
                $record->tokenhashed = 1;
                $DB->update_record($table, $record);
            }
            upgrade_set_timeout(300);
        }
    }
}

/**
 * Bring the integration role in line with util::ROLECAPS without replacing tokens.
 *
 * Earlier releases granted the role course editing, token creation and allocation management, which
 * nothing needs. Syncing only ever adds capabilities, so those are revoked explicitly first.
 */
function local_learnwise_upgrade_service_role_capabilities(): void {
    global $DB;
    $role = $DB->get_record('role', ['shortname' => 'learnwise_assistant']);
    if (!$role) {
        return;
    }
    foreach (
        ['moodle/webservice:createtoken', 'moodle/course:update', 'mod/assign:manageallocations'] as $capability
    ) {
        unassign_capability($capability, $role->id);
    }
    local_learnwise_upgrade_sync_role_capabilities();
}
