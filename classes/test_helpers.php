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
     * @param array $body Request body parameters.
     * @param array $query Query string parameters.
     * @return api_server
     */
    protected function oauth_server(int $userid, array $body = [], array $query = []): api_server {
        set_config('liveapi', 1, 'local_learnwise');
        set_config('aiops', 1, 'local_learnwise');
        $storage = new storage();
        $client = util::get_or_generate_client();
        // One token per user: setting an existing token again only renews it, keeping its first user.
        $token = 'user-oauth-token-' . $userid;
        $storage->setAccessToken($token, $client->uniqid, $userid, time() + 3600);
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $server = new api_server();
        $server->request = new Request($query, $body, [], [], [], [], null, ['Authorization' => 'Bearer ' . $token]);
        return $server;
    }

    /**
     * Build the URL of an API's route, relative to api/r.php.
     *
     * The route is the test class's url_regex(), a regex like
     * /^(me\/)?courses\/(?P<courseid>\d+)\/assignments(?:\/(?P<id>\d+))?$/: (me\/)? is the prefix for the
     * current user's own data, (?P<id>...) the API's own record id and any other named group a value the API
     * reads from the path. Only literals, escaped characters and groups may sit outside the named groups.
     *
     * Values are keyed by group name: with that regex, build_url(['courseid' => 2, 'id' => 7]) gives
     * 'courses/2/assignments/7'. An optional part is left out when its values are missing, 'me' => true
     * adds the me/ prefix, and values that are not groups go in the query string.
     *
     * @param array $params Values keyed by group name.
     * @param string|null $regex Route of another API, instead of this test class's url_regex().
     * @return string
     */
    protected static function build_url(array $params = [], ?string $regex = null): string {
        if ($regex === null && method_exists(static::class, 'url_regex')) {
            $regex = static::url_regex();
        }
        if ($regex === null || !preg_match('/^\/\^(.*)\$\/$/s', $regex, $matches)) {
            throw new \coding_exception('No route to build: ' . json_encode($regex));
        }
        $names = [];
        $path = static::expand_url_pattern($matches[1], $params, $names);
        if ($path === null || !preg_match($regex, $path)) {
            throw new \coding_exception("Missing or invalid route values for {$regex}: " . json_encode($params));
        }
        $query = array_diff_key($params, array_flip($names));
        return $query ? $path . '?' . http_build_query($query, '', '&') : $path;
    }

    /**
     * Write out a route pattern with the given values.
     *
     * @param string $pattern Regex without delimiters and anchors.
     * @param array $params Values keyed by group name.
     * @param array $names Receives the name of every group in the pattern that takes a value.
     * @return string|null The path, or null when a required value is missing.
     */
    private static function expand_url_pattern(string $pattern, array $params, array &$names): ?string {
        $path = '';
        $missing = false;
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '\\') {
                $path .= $pattern[++$i];
                continue;
            }
            if ($char !== '(') {
                if (strpos('[]{}.*+?|^$)', $char) !== false) {
                    throw new \coding_exception("Unsupported '{$char}' outside a named group in route {$pattern}");
                }
                $path .= $char;
                continue;
            }

            // Find the group's closing parenthesis.
            $depth = 0;
            for ($end = $i; $end < $length; $end++) {
                if ($pattern[$end] === '\\') {
                    $end++;
                } else if ($pattern[$end] === '(') {
                    $depth++;
                } else if ($pattern[$end] === ')' && --$depth === 0) {
                    break;
                }
            }
            $group = substr($pattern, $i + 1, $end - $i - 1);
            $optional = ($pattern[$end + 1] ?? '') === '?';
            $i = $optional ? $end + 1 : $end;

            $text = stripslashes($group);
            if (preg_match('/^\?P<(\w+)>(.*)$/s', $group, $matches)) {
                $names[] = $matches[1];
                $value = $params[$matches[1]] ?? null;
                $value = ($value === null || $value === '') ? null : (string) $value;
            } else if (preg_quote($text, '/') === $group) {
                // A group of fixed text, such as (me\/), is a flag named after the text: a truthy value writes it.
                $names[] = trim($text, '/');
                $value = empty($params[trim($text, '/')]) ? null : $text;
            } else {
                $value = static::expand_url_pattern(preg_replace('/^\?:/', '', $group), $params, $names);
            }

            if ($value !== null) {
                $path .= $value;
            } else if (!$optional) {
                $missing = true;
            }
        }
        return $missing ? null : $path;
    }

    /**
     * Call an API URL as api/r.php does, with an OAuth token for the user.
     *
     * @param string $url URL after api/r.php, from build_url(), e.g. static::build_url(['courseid' => 2]).
     * @param array $body Request body parameters.
     * @param int|null $userid Authenticated user, the current user when null.
     * @return mixed The cleaned response.
     */
    protected function oauth_server_execute_request(string $url, array $body = [], ?int $userid = null) {
        global $USER;
        // Every api/r.php request starts in a fresh PHP process, without the route state of an earlier one.
        baseapi::$ids = [];
        baseapi::$my = null;
        external\course_modules::$withcompletion = false;
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $server = $this->oauth_server($userid ?? (int) $USER->id, $body, $query);
        $server->urlparts = explode('/', (string) parse_url($url, PHP_URL_PATH));
        return $this->execute_request($server);
    }
}
