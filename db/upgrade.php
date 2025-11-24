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
 * Upgrade steps for Learnwise
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    local_learnwise
 * @category   upgrade
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\util;

/**
 * Execute the plugin upgrade steps from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_learnwise_upgrade($oldversion) {
    global $CFG, $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025091700) {
        require_once($CFG->dirroot . '/webservice/lib.php');
        $webservicemanager = new webservice();
        $extservice = $webservicemanager->get_external_service_by_shortname('learnwise');
        if (!empty($extservice) && !empty($extservice->restrictedusers)) {
            $admin = get_admin();
            $wsauthorizedusers = $webservicemanager->get_ws_authorised_users($extservice->id);
            $authorizeuserfound = false;
            foreach ($wsauthorizedusers as $user) {
                if ($user->id == $admin->id) {
                    if (empty($user->validuntil) || $user->validuntil > time()) {
                        $authorizeuserfound = true;
                        break;
                    } else {
                        $webservicemanager->remove_ws_authorised_user($user, $extservice->id);
                    }
                }
            }
            if (empty($authorizeuserfound)) {
                $serviceuser = new stdClass();
                $serviceuser->externalserviceid = $extservice->id;
                $serviceuser->userid = $admin->id;
                $webservicemanager->add_ws_authorised_user($serviceuser);
            }
        }

        // Learnwise savepoint reached.
        upgrade_plugin_savepoint(true, 2025091700, 'local', 'learnwise');
    }

    if ($oldversion < 2025091800) {
        // Rename field timeexpiry on table local_learnwise_authcode to timeexpiry.
        $table = new xmldb_table('local_learnwise_authcode');
        $field = new xmldb_field('timexpiry', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'token');

        // Launch rename field timeexpiry.
        $dbman->rename_field($table, $field, 'timeexpiry');

        // Learnwise savepoint reached.
        upgrade_plugin_savepoint(true, 2025091800, 'local', 'learnwise');
    }

    if ($oldversion < 2025112401) {
        $token = util::get_or_generate_token_for_user('learnwise');
        $admin = get_admin();
        $existrecord = $DB->get_record('external_tokens', [
            'externalserviceid' => $token->externalserviceid,
            'tokentype' => $token->tokentype,
            'userid' => $admin->id,
        ]);
        if ($existrecord) {
            $token->token = $existrecord->token;
            $DB->update_record('external_tokens', $token);
            $DB->delete_records('external_tokens', ['id' => $existrecord->id]);
        }
        upgrade_plugin_savepoint(true, 2025112401, 'local', 'learnwise');
    }

    return true;
}
