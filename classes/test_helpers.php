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

namespace local_learnwise;

use local_learnwise\external\baseapi;
use local_learnwise\local\OAuth2\Request;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/webservice/rest/locallib.php');

/**
 * Class test_helpers
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait test_helpers {
    /**
     * Invoke the real server pipeline without run(), which sends output and exits PHP.
     *
     * @param \webservice_base_server $server Server under test.
     * @param string $method Protected pipeline stage.
     * @param array $args Method call arguments.
     * @return mixed
     */
    protected function stage(\webservice_base_server $server, string $method, array $args = []) {
        $reflection = new \ReflectionMethod($server, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($server, $args);
    }

    /**
     * Parse, authenticate and execute a real request, retaining its raw result.
     *
     * @param \webservice_base_server $server Server under test.
     * @return mixed
     */
    protected function execute_request(\webservice_base_server $server) {
        foreach (['parse_request', 'authenticate_user', 'load_function_info', 'execute'] as $stage) {
            $this->stage($server, $stage);
        }

        $property = new \ReflectionProperty($server, 'returns');
        $property->setAccessible(true);
        $returns = $property->getValue($server);

        $property = new \ReflectionProperty($server, 'function');
        $property->setAccessible(true);
        $function = $property->getValue($server);

        if (!isset($function->returns_desc)) {
            return $returns;
        }

        if (method_exists($server, 'clean_returns')) {
            return $this->stage($server, 'clean_returns', [$returns]);
        }

        $returns = baseapi::clean_returnvalue(
            $function->returns_desc,
            $returns
        );

        return $returns;
    }

    /**
     * Construct a request using the permanent token through the real Bearer fallback.
     *
     * @param \stdClass $token Permanent token.
     * @param array $route Route segments.
     * @param array $body Request body parameters.
     * @return api_server
     */
    protected function service_server(\stdClass $token, array $route, array $body = []): api_server {
        global $ME;
        $ME = '/local/learnwise/api/r.php';
        set_config('liveapi', 1, 'local_learnwise');
        set_config('aiops', 1, 'local_learnwise');
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $server = new api_server();
        $server->urlparts = array_map('strval', $route);
        $server->request = new Request([], $body, [], [], [], [], null, ['Authorization' => 'Bearer ' . $token->token]);
        return $server;
    }

    /**
     * Execute a core REST read with the same permanent credential.
     *
     * @param \stdClass $token Permanent token.
     * @param string $function External function name.
     * @param array $params Request parameters.
     * @return mixed
     */
    protected function core_service_request(\stdClass $token, string $function, array $params) {
        $_POST = [];
        $_GET = $params + ['wstoken' => $token->token, 'wsfunction' => $function, 'moodlewsrestformat' => 'json'];
        return $this->execute_request(new \webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN));
    }

    /**
     * Issue a real OAuth bearer token for the plugin request pipeline.
     *
     * @param int $userid Authenticated user.
     * @return api_server
     */
    protected function oauth_server(int $userid): api_server {
        set_config('liveapi', 1, 'local_learnwise');
        set_config('aiops', 1, 'local_learnwise');
        $storage = new storage();
        $client = util::get_or_generate_client();
        $storage->setAccessToken('user-oauth-token', $client->uniqid, $userid, time() + 3600);
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $server = new api_server();
        $server->request = new Request([], [], [], [], [], [], null, ['Authorization' => 'Bearer user-oauth-token']);
        return $server;
    }
}
