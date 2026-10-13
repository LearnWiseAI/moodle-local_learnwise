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

namespace local_learnwise\external;

defined('MOODLE_INTERNAL') || die();

use context_user;
use core_user;
use core_user_external;
use core_external\external_function_parameters;
use core_external\external_value;

global $CFG;
require_once($CFG->dirroot . '/user/externallib.php');

/**
 * Class getuserdetails
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class userdetails extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'me';

    /**
     * {@inheritdoc}
     */
    public static function description() {
        return 'Get user details';
    }

    /**
     * {@inheritdoc}
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User id', VALUE_DEFAULT, 0, NULL_ALLOWED),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $userid ID of user
     * @return array|null
     */
    public static function execute($userid) {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/user/lib.php');

        $params = static::validate_parameters(
            static::execute_parameters(),
            ['userid' => $userid]
        );
        $userid = $params['userid'];
        if (empty($userid)) {
            $userid = $USER->id;
        }
        $context = context_user::instance($userid);
        self::validate_context($context);
        $user = core_user::get_user($userid, '*', MUST_EXIST);
        if ($userid != $USER->id && !self::user_can_view_profile($user)) {
            require_capability('moodle/user:viewdetails', $context);
        }
        return self::user_get_user_details_courses($user);
    }


    /**
     * {@inheritdoc}
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function single_structure() {
        // phpcs:ignore moodle.Commenting.InlineComment.NotCapital
        // @phpstan-ignore return.type
        return core_user_external::user_description();
    }

    /**
     * {@inheritdoc}
     */
    public static function get_unixtimestamp_fields() {
        return ['firstaccess', 'lastaccess'];
    }
}
