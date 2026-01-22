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
 * TODO describe file login
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\form\permission;
use local_learnwise\local\OAuth2;
use local_learnwise\server;
use local_learnwise\util;

//phpcs:ignore moodle.Files.RequireLogin.Missing
require('../../config.php');

$clientid = required_param('client_id', PARAM_TEXT);
$responsetype = required_param('response_type', PARAM_TEXT);
$redirecturi = required_param('redirect_uri', PARAM_URL);
$scope = optional_param('scope', false, PARAM_TEXT);
$state = optional_param('state', false, PARAM_TEXT);

$url = new moodle_url('/local/learnwise/auth.php', []);
$params = [
    'client_id' => $clientid,
    'response_type' => $responsetype,
];
if ($scope) {
    $params['scope'] = $scope;
}
if ($state) {
    $params['state'] = $state;
}
if ($redirecturi) {
    $params['redirect_uri'] = $redirecturi;
}
$url->params($params);

$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('login');

$PAGE->set_heading($SITE->fullname);

if (isloggedin() && !isguestuser()) {
    $server = server::get_instance();

    $request = OAuth2\Request::createFromGlobals();
    $response = util::make_response();

    if (!get_config('local_learnwise', 'liveapi')) {
        $response->setError(500, get_string('toolnotconfigured', constants::COMPONENT));
        $response->send();
        die;
    }

    if (!$server->validateAuthorizeRequest($request, $response)) {
        $response->send();
        die();
    }

    $form = new permission($url);
    $permissiongranted = $form->process_form_submission();
    if (is_null($permissiongranted)) {
        echo $OUTPUT->header();
        echo $form->render();
        echo $OUTPUT->footer();
        die;
    }

    $server->handleAuthorizeRequest($request, $response, $permissiongranted, $USER->id);
    $response->send();
    die;
}

$SESSION->wantsurl = $url;
redirect(get_login_url());
