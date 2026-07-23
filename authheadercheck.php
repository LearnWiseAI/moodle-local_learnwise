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
 * Check for the presence of an HTTP Authorization header and return a
 * JSON response indicating success (true) when present or failure (false)
 * when missing. Used by LearnWise integration to verify auth header support.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\local\OAuth2\Request;
use local_learnwise\local\OAuth2\Response;

define('NO_MOODLE_COOKIES', true);
define('NO_UPGRADE_CHECK', true);
define('NO_DEBUG_DISPLAY', true);
define('ABORT_AFTER_CONFIG', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing
require(dirname(__FILE__, 3) . '/config.php');

spl_autoload_register('core_component::classloader');

$request = Request::createFromGlobals();
$response = new Response();

if (!$request->headers('Authorization')) {
    $response->setStatusCode(400);
    $response->setParameter('success', false);
} else {
    $response->setParameter('success', true);
}

$response->send();
