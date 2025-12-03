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
 * TODO describe file refresh
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\local\OAuth2\Request;
use local_learnwise\local\OAuth2\Response;
use local_learnwise\server;
use local_learnwise\util;

define('NO_MOODLE_COOKIES', true);

require('../../config.php');

$url = new moodle_url('/local/learnwise/token.php', []);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());

try {
    $server = server::get_instance();

    $request = Request::createFromGlobals();
    $response = util::make_response();

    if (!get_config('local_learnwise', 'liveapi')) {
        $response->setError(500, get_string('toolnotconfigured', constants::COMPONENT));
        $response->send();
        die;
    }
    /* @phpstan-ignore method.notFound */
    $server->handleTokenRequest($request, $response)->send();
} catch (Exception $e) {
    // phpcs:ignore moodle.security.outputnotprotected.exception -- This is an API endpoint, we need to return the error.
    // Log the error to Moodle error log if debugging is enabled.
    if (debugging('', DEBUG_DEVELOPER)) {
        debugging('OAuth error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }

    $response = new Response();
    $response->setError(500, 'An unexpected error occurred: ' . $e->getMessage());
    $response->send();
}
