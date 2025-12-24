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

namespace local_learnwise\external\assign;

use core_user;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;
use mod_assign_external;
use required_capability_exception;

/**
 * Class get_status
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_status extends baseapi {
    /**
     * Summary of execute_parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'assignid' => new external_value(PARAM_INT, 'assignment id'),
            'userid' => new external_value(PARAM_INT, 'user id'),
        ]);
    }

    /**
     * ws function to get assign submission
     *
     * @param int $assignid
     * @param int $userid
     * @return array
     */
    public static function execute($assignid, $userid) {
        $params = self::validate_parameters(
            self::execute_parameters(),
            [
                'assignid' => $assignid,
                'userid' => $userid,
            ]
        );

        $filteredparams = grade::validate_assignment($params['assignid']);
        $assign = $filteredparams[0];
        $context = $filteredparams[3];

        require_capability('mod/assign:grade', $context);

        $user = core_user::get_user($params['userid'], '*', MUST_EXIST);
        core_user::require_active_user($user);

        if (!$assign->can_view_submission($user->id)) {
            throw new required_capability_exception($context, 'mod/assign:viewgrades', 'nopermission', '');
        }

        $response = [];
        $submission = $assign->get_user_submission($user->id, false);
        if ($submission) {
            $response['submission'] = $submission;
        }
        return $response;
    }

    /**
     * Indicate function response is singular
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * Returns the structure of the response for the execute function.
     *
     * @return external_single_structure
     */
    public static function single_structure() {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/externallib.php');
        $structure = mod_assign_external::get_submission_status_returns();
        $responsestructure = $structure->keys['lastattempt'];
        foreach ($responsestructure->keys as $atom) {
            $atom->required = VALUE_OPTIONAL;
        }
        return $responsestructure;
    }
}
