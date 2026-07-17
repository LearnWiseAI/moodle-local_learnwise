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

use context_system;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_learnwise\constants;
use local_learnwise\util;

/**
 * Class plugininfo
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class plugininfo extends baseapi {
    /**
     * The route for the plugininfo API.
     *
     * @var string
     */
    public static $route = 'plugininfo';

    #[\Override]
    public static function description() {
        return 'Returns information about a plugin setup';
    }

    /**
     * Summary of execute_parameters
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([]);
    }

    /**
     * Summary of execute
     *
     * @return array
     */
    public static function execute() {
        $systemcontext = context_system::instance();
        self::validate_context($systemcontext);

        require_capability('local/learnwise:plugininfo', $systemcontext);

        $config = get_config(constants::COMPONENT);
        $clientcreds = util::get_or_generate_client();

        $response['aiops'] = !empty($config->aiops);
        $response['clientid'] = $clientcreds->uniqid;
        $response['redirecturl'] = !empty($config->redirecturl) ? $config->redirecturl : '';
        $response['redirecturl'] = preg_replace('/\R/', ',', $response['redirecturl']);
        $response['version'] = util::get_plugin_versioninfo()->release;

        return $response;
    }

    /**
     * Define the API is being called for a single operation.
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * Returns the structure for API.
     *
     * @return external_single_structure The structure for the API response.
     */
    public static function single_structure() {
        return new external_single_structure([
            'aiops' => new external_value(PARAM_BOOL, 'Indicates aiops is enabled or not'),
            'clientid' => new external_value(PARAM_ALPHANUMEXT, 'A client id'),
            'redirecturl' => new external_value(PARAM_RAW, 'A client id'),
            'version' => new external_value(PARAM_RAW, 'A plugin release number'),
        ]);
    }
}
