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
 * TODO describe file setup
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\output\setup;

require('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('local_learnwise_setup');

$widget = new setup;
$renderer = $PAGE->get_renderer('local_learnwise');

$postdata = data_submitted();
if ($postdata && confirm_sesskey()) {
    $widget->save($postdata);
    redirect($PAGE->url);
}

echo $renderer->header();

echo $renderer->render($widget);

echo $renderer->footer();
