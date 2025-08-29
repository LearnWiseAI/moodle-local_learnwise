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

use context_system;
use message_popup_external;

global $CFG;
require_once($CFG->dirroot.'/message/output/popup/externallib.php');

/**
 * Class getnotifications
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifications extends baseapi {

    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'notifications';

    /**
     * Returns the parameters for the execute function.
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        $stucture = message_popup_external::get_popup_notifications_parameters();
        $stucture->keys['useridto']->required = VALUE_DEFAULT;
        $stucture->keys['useridto']->default = 0;
        return $stucture;
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $useridto ID of user
     * @param bool $newestfirst Sorting preference
     * @param int $limit Record limit
     * @param int $offset Record offset
     * @return \external_description
     */
    public static function execute($useridto, $newestfirst, $limit, $offset) {
        $params = static::validate_parameters(
            static::execute_parameters(),
            [
                'useridto' => $useridto,
                'newestfirst' => $newestfirst,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );

        $context = context_system::instance();
        static::validate_context($context);

        $data = message_popup_external::get_popup_notifications(
            $params['useridto'], $params['newestfirst'], $params['limit'], $params['offset']
        );

        return $data['notifications'];
    }

    /**
     * Returns the structure of a single notification.
     *
     * @return \external_single_structure
     */
    public static function single_structure() {
        $stucture = message_popup_external::get_popup_notifications_returns();
        return $stucture->keys['notifications']->content;
    }

    /**
     * Returns the fields that should be returned as Unix timestamps.
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return ['timecreated', 'timeread'];
    }

}
