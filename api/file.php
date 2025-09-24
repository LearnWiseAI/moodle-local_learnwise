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
 * TODO describe file file
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\local\OAuth2\Request;
use local_learnwise\local\OAuth2\Response;
use local_learnwise\server;

define('NO_DEBUG_DISPLAY', true);
define('WS_SERVER', true);

//phpcs:ignore moodle.Files.RequireLogin.Missing
require('../../../config.php');
require_once($CFG->libdir . '/externallib.php');

$server = server::get_instance();
$request = Request::createFromGlobals();
$response = new Response();
$response->addHttpHeaders(['Cache-Control' => 'no-store, no-cache, must-revalidate']);

if (!get_config('local_learnwise', 'liveapi')) {
    $response->setError(500, get_string('apidisabled', constants::COMPONENT));
    $response->send();
    die;
}

if (!$server->verifyResourceRequest($request, $response)) {
    $response->send();
    die;
}

// Authenticate user.
$token = $server->getAccessTokenData($request);
$user = get_complete_user_data('id', $token['user_id'], null, true);

core_user::require_active_user($user, true, true);

// Emulate normal session.
enrol_check_plugins($user);
core\session\manager::set_user($user);

require_once($CFG->dirroot . '/pluginfile.php');
