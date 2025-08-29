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

namespace local_learnwise\privacy;

use context_system;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;

/**
 * Privacy provider for local_learnwise.
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {
    /**
     * Returns metadata about this plugin's data storage.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_learnwise_userauth', [
            'clientid' => 'privacy:metadata:local_learnwise_userauth:clientid',
            'userid' => 'privacy:metadata:local_learnwise_userauth:userid',
        ], 'privacy:metadata:local_learnwise_userauth');

        $collection->add_database_table('local_learnwise_authcode', [
            'code' => 'privacy:metadata:local_learnwise_authcode:code',
            'token' => 'privacy:metadata:local_learnwise_authcode:token',
            'timexpiry' => 'privacy:metadata:local_learnwise_authcode:timexpiry',
        ], 'privacy:metadata:local_learnwise_authcode');

        $collection->add_database_table('local_learnwise_accesstoken', [
            'token' => 'privacy:metadata:local_learnwise_accesstoken:token',
            'timeexpiry' => 'privacy:metadata:local_learnwise_accesstoken:timeexpiry',
        ], 'privacy:metadata:local_learnwise_accesstoken');

        $collection->add_database_table('local_learnwise_refreshtoken', [
            'token' => 'privacy:metadata:local_learnwise_refreshtoken:token',
            'timeexpiry' => 'privacy:metadata:local_learnwise_refreshtoken:timeexpiry',
        ], 'privacy:metadata:local_learnwise_refreshtoken');

        return $collection;
    }

    /**
     * Get the list of users within a context.
     *
     * @param \core_privacy\local\request\userlist $userlist
     * @return void
     */
    public static function get_users_in_context(\core_privacy\local\request\userlist $userlist): void {
        $context = $userlist->get_context();

        if (!$context instanceof context_system) {
            return;
        }

        $userlist->add_from_sql('user_id', "SELECT DISTINCT user_id FROM {local_learnwise_userauth}", []);
    }

    /**
     * Delete all user data for users in the approved userlist in a context.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist
     * @return void
     */
    public static function delete_data_for_users(\core_privacy\local\request\approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if ($context->contextlevel == CONTEXT_USER) {
            $userids = $userlist->get_userids();
            if (!empty($userids)) {
                list($sql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'userid');
                self::delete_user_data($sql, $params);
            }
        }
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {local_learnwise_userauth} t
                  JOIN {context} ctx ON ctx.instanceid = t.userid AND ctx.contextlevel = :ctxlevel
                 WHERE t.userid = :userid";
        $params = [
            'ctxlevel' => CONTEXT_USER,
            'userid' => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);
        return $contextlist;
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (!$contextlist) {
            return;
        }
        $subcontext = [get_string('pluginname', 'local_learnwise')];
        $notexportedstr = get_string('privacy:request:notexportedsecurity', 'local_learnwise');
        foreach ($contextlist as $context) {
            if ($context->contextlevel == CONTEXT_USER) {
                $userauths = $DB->get_records('local_learnwise_userauth', ['userid' => $context->instanceid]);
                foreach ($userauths as $userauth) {
                    $userauth->clientid = $notexportedstr;
                    foreach ([
                        'authcodes' => 'local_learnwise_authcode',
                        'accesstokens' => 'local_learnwise_accesstoken',
                        'refreshtokens' => 'local_learnwise_refreshtoken',
                    ] as $prop => $table) {
                        $userauth->$prop = $DB->get_records($table, ['authid' => $userauth->id]);
                        foreach ($userauth->$prop as $item) {
                            if (isset($item->timexpiry)) {
                                $item->timexpiry = transform::datetime($item->timexpiry);
                            }
                            if (isset($item->timeexpiry)) {
                                $item->timeexpiry = transform::datetime($item->timeexpiry);
                            }
                        }
                    }
                    writer::with_context($context)->export_data($subcontext, $userauth);
                }
            }
        }
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = $contextlist->get_user()->id ?? null;
        if ($userid) {
            self::delete_user_data('= :userid', ['userid' => $userid]);
        }
    }

    /**
     * Delete all user data for all users in the specified context.
     *
     * @param \context $context
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        self::delete_user_data('IS NOT NULL', []);
    }

    /**
     * Helper to delete user data.
     *
     * @param string $where
     * @param array $params
     * @return void
     */
    protected static function delete_user_data(string $where, array $params): void {
        global $DB;
        $userauths = $DB->get_records_select('local_learnwise_userauth', "userid {$where}", $params);
        foreach ($userauths as $userauth) {
            $DB->delete_records('local_learnwise_authcode', ['authid' => $userauth->id]);
            $DB->delete_records('local_learnwise_accesstoken', ['authid' => $userauth->id]);
            $DB->delete_records('local_learnwise_refreshtoken', ['authid' => $userauth->id]);
        }
        $DB->delete_records_select('local_learnwise_userauth', "userid {$where}", $params);
    }
}
