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
 * External functions and service declaration for Learnwise
 *
 * Documentation: {@link https://moodledev.io/docs/apis/subsystems/external/description}
 *
 * @package    local_learnwise
 * @category   webservice
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_learnwise_assign_get_submission' => [
        'classname'     => 'local_learnwise\external\assign\get_status',
        'methodname'    => 'execute',
        'classpath'     => 'mod/assign/externallib.php',
        'description'   => 'Returns information about an assignment submission status',
        'type'          => 'read',
        'capabilities'  => 'mod/assign:view, mod/assign:grade',
        'ajax'          => true,
    ],
];

$services = [
    'Learnwise Service' => [
        'functions' => [
            'core_webservice_get_site_info',
            'core_course_get_courses',
            'core_course_get_contents',
            'core_course_get_course_module',
            'core_files_get_files',
            'core_enrol_get_users_courses',
            // Note: only on 3.4, got deprecated in later versions.
            'mod_forum_get_forum_discussions_paginated',
            'mod_page_get_pages_by_courses',
            'mod_forum_get_forums_by_courses',
            'mod_forum_get_forum_discussions',
            'mod_resource_get_resources_by_courses',
            'mod_assign_get_assignments',
        ],
        'enabled' => 1,
        'restrictedusers' => 1,
        'shortname' => 'learnwise',
        'downloadfiles' => 1,
        'uploadfiles' => 0,
    ],
];
