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
 * Code to search for users in response to an ajax call from a user selector.
 *
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

$PAGE->set_context(context_system::instance());
$PAGE->set_url('/local/learnwise/courseselector/search.php');

echo $OUTPUT->header();

// Check access.
require_login();
require_sesskey();

// Get the search parameter.
$search = required_param('search', PARAM_RAW);

// Get and validate the selectorid parameter.
$selectorhash = required_param('selectorid', PARAM_ALPHANUM);
if (!isset($USER->courseselectors[$selectorhash])) {
    new moodle_exception('unknowncourseselector', 'local_learnwise');
}

// Get the options.
$options = $USER->courseselectors[$selectorhash];

// Create the appropriate courseselector.
$classname = $options['class'];
unset($options['class']);
$name = $options['name'];
unset($options['name']);
if (isset($options['file'])) {
    require_once($CFG->dirroot . '/' . $options['file']);
    unset($options['file']);
}
$courseselector = new $classname($name, $options);

// Do the search and output the results.
$results = $courseselector->find_courses($search);
$jsonresults = [];
foreach ($results as $groupname => $courses) {
    $groupdata = ['name' => $groupname, 'courses' => []];
    foreach ($courses as $course) {
        $output = new stdClass();
        $output->id = $course->id;
        $output->name = $courseselector->output_course($course);
        $groupdata['courses'][] = $output;
    }
    $jsonresults[] = $groupdata;
}

$json = ['results' => $jsonresults];

echo json_encode($json);
