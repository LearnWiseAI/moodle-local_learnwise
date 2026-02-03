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
 *
 * @package    local_learnwise
 * @category   webservice
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\external\assign\grade;
use local_learnwise\external\assign\submissions;
use local_learnwise\external\assignments;
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
use local_learnwise\local\OAuth2\Response;

defined('MOODLE_INTERNAL') || die();

if (!function_exists('local_learnwise_call_external_function')) {
    /**
     * Summary of local_learnwise_call_external_function
     * @param stdClass $wsfunction
     * @param mixed $params
     * @param Response $response
     * @return void
     */
    function local_learnwise_call_external_function(stdClass $wsfunction, $params, Response $response) {
        global $CFG;
        require_once("{$CFG->libdir}/external/externallib.php");
        $externalfunctioninfo = core_external::external_function_info($wsfunction);
        $params = core_external::clean_returnvalue(
            $externalfunctioninfo->parameters_desc,
            $params
        );
        /* @phpstan-ignore argument.type */
        $data = core_external::call_external_function($wsfunction, $params);

        if ($data['error']) {
            $exception = $data['exception'];
            $response->setError(500, $exception->message, $exception->debuginfo);
        } else {
            $response->setParameters($data['data']);
        }
    }
}

$callbacks = [
    calenderdetails::class => [
        'description' => 'Get calender upcoming events',
    ],
    userdetails::class => [
        'description' => 'Get user details',
    ],
    assignments::class => [
        'description' => 'Get course assignments',
    ],
    courses::class => [
        'description' => 'Get user courses',
    ],
    notifications::class => [
        'description' => 'Get notifications',
    ],
    course_modules::class => [
        'description' => 'Get course modules',
    ],
    forums::class => [
        'description' => 'Get forums',
    ],
    discussions::class => [
        'description' => 'Get forum discussions',
    ],
    scorms::class => [
        'description' => 'Get scorms',
    ],
    submissions::class => [
        'description' => 'Get assign submissions',
    ],
    grade::class => [
        'description' => 'Submit assignment grade',
    ],
    users::class => [
        'description' => 'Get user info',
    ],
    modules::class => [
        'description' => 'Get module details',
    ],
];

foreach ($callbacks as $classname => $info) {
    $info['component'] = 'local_learnwise';
    $info['loginrequired'] = true;
    $info['type'] = 'read';
    $info['methodname'] = 'execute';
    $info['classname'] = $classname;
    $callbacks[$classname] = $info;
}

return $callbacks;
