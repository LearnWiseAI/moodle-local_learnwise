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
 * Class implementing WS local_learnwise_deletelti
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnwise\external;

use context_system;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_learnwise\constants;
use local_learnwise\util;

/**
 * Implementation of web service local_learnwise_deletelti
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class deletelti extends baseapi {
    /**
     * Describes the parameters for local_learnwise_deletelti
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'id'),
        ]);
    }

    /**
     * Implementation of web service local_learnwise_deletelti
     *
     * @param int $id
     */
    public static function execute($id) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['id' => $id]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $type = lti_get_type($params['id']);

        if (!empty($type)) {
            lti_delete_type($type->id);
            set_config('ltitypeid', null, constants::COMPONENT);

            $types = lti_get_lti_types_from_proxy_id($type->toolproxyid);
            if (empty($types)) {
                lti_delete_tool_proxy($type->toolproxyid);
            }

            $typeids = get_config(constants::COMPONENT, 'ltitypeids');
            if (empty($typeids)) {
                $typeids = '';
            }
            $typeids = array_filter(explode(',', $typeids));
            $typeids = array_combine($typeids, $typeids);
            foreach ($typeids as $typeid) {
                if (!util::get_lti_data($typeid)) {
                    unset($typeids[$typeid]);
                }
            }
            set_config('ltitypeids', join(',', $typeids), constants::COMPONENT);

            return ['success' => true];
        }

        return ['success' => false];
    }

    /**
     * Define api supports single operation
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * Returns the structure for a single operation.
     *
     * @return external_single_structure The structure for the API response.
     */
    public static function single_structure() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'success'),
        ]);
    }
}
