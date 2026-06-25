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

use core_component;
use core_plugin_manager;
use local_learnwise\util;

/**
 * Generic WS proxy - translates REST-style JSON requests into Moodle WS function calls.
 *
 * Routes like /ws/mod_assign/get_assignments are mapped to function
 * mod_assign_get_assignments. Parameters come from the JSON body (POST/PUT/DELETE)
 * or query string (GET). The function runs as the OAuth2-authenticated user.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ws_proxy {
    /**
     * Dispatch a WS function call from URL segments and return the result.
     *
     * @param array $urlparts Remaining URL segments after /ws/.
     * @param \local_learnwise\local\OAuth2\Response $response OAuth2 response object
     * @return void
     */
    public static function dispatch(array $urlparts, $response): void {
        // Reconstruct function name: /ws/mod_assign/get_assignments -> mod_assign_get_assignments.
        if (count($urlparts) < 2) {
            $response->setError(400, 'Invalid WS path: expected /ws/<component>/<function>');
            return;
        }
        $component = array_shift($urlparts);
        $component = core_component::normalize_componentname($component);

        if (!in_array($component, array_merge(['core'], core_component::get_component_names()))) {
            $response->setError(400, 'Invalid WS component');
            return;
        }

        $action = join('_', $urlparts);
        $functionname = $component . '_' . $action;

        // Validate against whitelist.
        $allowed = self::get_allowed_functions();
        if (!array_key_exists($functionname, $allowed)) {
            $response->setError(
                403,
                "Function '{$functionname}' is not allowed through this proxy."
            );
            return;
        }

        // Validate component enabled in Moodle.
        $typenplugin = core_component::normalize_component($component);
        $plugintype = array_shift($typenplugin);
        if ($plugintype !== 'core') {
            $pluginname = array_shift($typenplugin);
            $pluginman = core_plugin_manager::instance();
            $enabledplugins = $pluginman->get_enabled_plugins($plugintype);

            if (is_array($enabledplugins) && !isset($enabledplugins[$pluginname])) {
                $response->setError(404, "Component disabled: {$component}");
                return;
            }
        }

        // Read parameters from JSON body (POST/PUT/DELETE) or query string (GET).
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            $params = $_GET;
            // Remove internal pagination params before passing to Moodle.
            unset($params['_page'], $params['_per_page']);
        } else {
            $rawbody = file_get_contents('php://input');
            $params = $rawbody ? json_decode($rawbody, true) : [];
            if (!is_array($params)) {
                $response->setError(400, 'Request body must be a JSON object.');
                return;
            }
        }

        // Call the function via Moodle's external API.
        $function = $allowed[$functionname];
        local_learnwise_call_external_function($function, $params, $response);

        if ($response->isClientError() || $response->isServerError()) {
            return;
        }

        // Apply proxy-level pagination for array responses.
        $responsedata = $response->getParameters();
        $data = self::apply_pagination($responsedata, $response);
        $response->setParameters($data);
    }

    /**
     * Apply proxy-level pagination to array responses.
     *
     * Accepts _page and _per_page query params. For array responses, slices the result
     * and adds X-Total-Count and X-Has-More headers.
     *
     * @param mixed $data The function result
     * @param \local_learnwise\local\OAuth2\Response $response Response object (for headers)
     * @return mixed Paginated data (or original if not an array)
     */
    private static function apply_pagination($data, $response) {
        // Only paginate indexed arrays (lists).
        if (!is_array($data) || empty($data) || !util::array_is_list($data)) {
            return $data;
        }

        $page = max(1, optional_param('_page', 1, PARAM_INT));
        $perpage = max(1, min(200, optional_param('_per_page', 50, PARAM_INT)));
        $total = count($data);
        $offset = ($page - 1) * $perpage;

        $response->addHttpHeaders([
            'X-Total-Count' => (string)$total,
            'X-Has-More' => ($offset + $perpage < $total) ? 'true' : 'false',
            'X-Page' => (string)$page,
            'X-Per-Page' => (string)$perpage,
        ]);

        return array_slice($data, $offset, $perpage);
    }

    /**
     * Get the list of allowed WS function names.
     *
     * This is the security whitelist. Only functions listed here can be called
     * through the proxy. The list is intentionally broad; Moodle capabilities
     * provide the real per-user access control.
     *
     * @return object[]
     */
    public static function get_allowed_functions(): array {
        global $DB;
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $cache = [];
        $functionrecords = $DB->get_records_select('external_functions', 'component IS NOT NULL');
        foreach ($functionrecords as $functionrecord) {
            $plugindir = core_component::get_component_directory($functionrecord->component);
            if (file_exists($plugindir . '/db/services.php')) {
                $cache[$functionrecord->name] = $functionrecord;
            }
        }
        return $cache;
    }
}
