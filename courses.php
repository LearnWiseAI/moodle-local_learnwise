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
 * Manage LearnWise course assignments page.
 *
 * Renders the interface used to allow or deny courses for LearnWise
 * integration and handles the selection widgets for current and
 * potential courses.
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\local\courseselector\current_course_selector;
use local_learnwise\local\courseselector\potential_course_selector;

require('../../config.php');

require_login();

$url = new moodle_url('/local/learnwise/courses.php', []);
$context = context_system::instance();
$title = get_string('course:title', 'local_learnwise');

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title($title);
$PAGE->set_heading($title);

$potentialcourses = new potential_course_selector(
    'potential_courses',
    [
        'selectlabel' => get_string('denycourses', 'local_learnwise'),
    ]
);
$currentcourses = new current_course_selector(
    'current_courses',
    [
        'selectlabel' => get_string('allowecourses', 'local_learnwise'),
    ]
);

$PAGE->requires->js_call_amd('local_learnwise/courses', 'init');

echo $OUTPUT->header();

// Capture selector HTML so we can pass it into the Mustache template unescaped.
ob_start();
$currentcourses->display();
$currenthtml = ob_get_clean();

ob_start();
$potentialcourses->display();
$potentialhtml = ob_get_clean();

$context = [
    'sesskey' => sesskey(),
    'pageurl' => $url->out(false),
    'currenthtml' => $currenthtml,
    'potentialhtml' => $potentialhtml,
    'larrow' => $OUTPUT->larrow(),
    'rarrow' => $OUTPUT->rarrow(),
    'add_label' => s(get_string('add')),
    'remove_label' => s(get_string('remove')),
];

echo $OUTPUT->render_from_template('local_learnwise/courses', $context);

echo $OUTPUT->footer();
