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
 * TODO describe file service
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\external\assign\grade;
use local_learnwise\external\assign\submissions;
use local_learnwise\external\assignments;
use local_learnwise\external\baseapi;
use local_learnwise\external\calenderdetails;
use local_learnwise\external\courses;
use local_learnwise\external\course_modules;
use local_learnwise\external\forum\discussions;
use local_learnwise\external\forums;
use local_learnwise\external\modules;
use local_learnwise\external\notifications;
use local_learnwise\external\scorms;
use local_learnwise\external\userdetails;
use local_learnwise\external\users;
use local_learnwise\local\OAuth2\Request;
use local_learnwise\server;
use local_learnwise\util;

define('WS_SERVER', true);
define('AJAX_SCRIPT', true);
//phpcs:ignore moodle.Files.RequireLogin.Missing
require('../../../config.php');
require_once($CFG->libdir . '/externallib.php');

$url = new moodle_url('/local/learnwise/api/r.php', []);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_heading($SITE->fullname);

$urlparts = explode('/', trim((string) get_file_argument(), '/'));
$callbacks = require('callbacks.php');

try {
    $server = server::get_instance();
    $request = Request::createFromGlobals();
    $response = util::make_response([
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);

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
    $user->ignoresesskey = true;

    core_user::require_active_user($user, true, true);

    // Emulate normal session.
    enrol_check_plugins($user);
    core\session\manager::set_user($user);

    $callback = null;
    $params = [];
    $nextroute = array_shift($urlparts);
    if (is_null($callback)) {
        if ($nextroute === userdetails::$route) {
            baseapi::$my = true;
            if (!$urlparts) {
                $callback = userdetails::class;
            } else {
                $nextroute = array_shift($urlparts);
            }
        } else if ($nextroute === users::$route) {
            $nextroute = array_shift($urlparts);
            if (is_numeric($nextroute)) {
                $params['userid'] = $nextroute;
                $callback = users::class;
            }
        }
        if ($nextroute === notifications::$route) {
            $callback = notifications::class;
        } else if ($nextroute === calenderdetails::$route) {
            $callback = calenderdetails::class;
        } else if ($nextroute === courses::$route) {
            $nextroute = array_shift($urlparts);
            if (is_null($nextroute)) {
                $callback = courses::class;
            } else if (is_numeric($nextroute)) {
                courses::set_id($nextroute);
                $nextroute = array_shift($urlparts);
                if (is_null($nextroute)) {
                    $callback = courses::class;
                } else {
                    $params['courseid'] = courses::get_id();
                    if ($nextroute === assignments::$route) {
                        $nextroute = array_shift($urlparts);
                        if (is_null($nextroute)) {
                            $callback = assignments::class;
                        } else if (is_number($nextroute)) {
                            assignments::set_id($nextroute);
                            $params['assignmentid'] = assignments::get_id();
                            $nextroute = array_shift($urlparts);
                            if (is_null($nextroute)) {
                                $callback = assignments::class;
                            } else if ($nextroute == submissions::$route) {
                                $nextroute = array_shift($urlparts);
                                if (is_null($nextroute)) {
                                    $callback = submissions::class;
                                } else if (is_number($nextroute)) {
                                    submissions::set_id((int) $nextroute);
                                    $nextroute = array_shift($urlparts);
                                    if (is_null($nextroute)) {
                                        $callback = submissions::class;
                                    } else if ($nextroute == grade::$route) {
                                        $callback = grade::class;
                                        $params += $request->request;
                                    }
                                }
                            }
                        }
                    } else if ($nextroute === course_modules::$route) {
                        course_modules::$withcompletion = !empty(baseapi::$my);
                        $nextroute = array_shift($urlparts);
                        if (is_null($nextroute)) {
                            $callback = course_modules::class;
                        } else if (is_number($nextroute)) {
                            course_modules::set_id($nextroute);
                            $callback = course_modules::class;
                        }
                    } else if ($nextroute === calenderdetails::$route) {
                        $callback = calenderdetails::class;
                    } else if ($nextroute === forums::$route) {
                        $nextroute = array_shift($urlparts);
                        if (is_null($nextroute)) {
                            $callback = forums::class;
                        } else if (is_number($nextroute)) {
                            forums::set_id($nextroute);
                            $params['forumid'] = forums::get_id();
                            $nextroute = array_shift($urlparts);
                            if (is_null($nextroute)) {
                                $callback = forums::class;
                            } else if ($nextroute == discussions::$route) {
                                $nextroute = array_shift($urlparts);
                                if (is_null($nextroute)) {
                                    $callback = discussions::class;
                                } else if (is_number($nextroute)) {
                                    discussions::set_id($nextroute);
                                    $callback = discussions::class;
                                }
                            }
                        }
                    } else if ($nextroute === scorms::$route) {
                        $nextroute = array_shift($urlparts);
                        if (is_null($nextroute)) {
                            $callback = scorms::class;
                        } else if (is_number($nextroute)) {
                            scorms::set_id($nextroute);
                            $nextroute = array_shift($urlparts);
                            if (is_null($nextroute)) {
                                $callback = scorms::class;
                            }
                        }
                    }
                }
            }
        } else if ($nextroute === modules::$route) {
            modules::$withcompletion = !empty(baseapi::$my);
            $nextroute = array_shift($urlparts);
            if (is_number($nextroute)) {
                modules::set_id($nextroute);
                $callback = modules::class;
            }
        }
    }
    if (isset($callbacks[$callback])) {
        local_learnwise_call_external_function((object) $callbacks[$callback], $params, $response);
        $response->send();
        die;
    } else {
        throw new Exception('Invalid data requested');
    }
} catch (Exception $e) {
    if (debugging('', DEBUG_DEVELOPER)) {
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('OAuth error: ' . $e->getMessage());
    }

    $response->setError(500, 'An unexpected error occurred: ' . $e->getMessage());
    $response->send();
}
