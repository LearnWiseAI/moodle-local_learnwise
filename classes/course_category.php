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
 * Class local_learnwise_course_category
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
if ($CFG->branch < 36) {
    require_once("{$CFG->libdir}/coursecatlib.php");
    class_alias('coursecat', 'course_category_parent_class');
} else {
    class_alias('core_course_category', 'course_category_parent_class');
}

/**
 * Class local_learnwise_course_category
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_learnwise_course_category extends course_category_parent_class {
    /**
     * Get user top category
     */
    public static function user_top() {
        if (method_exists(course_category_parent_class::class, 'user_top')) {
            return course_category_parent_class::user_top();
        }
        if (method_exists(course_category_parent_class::class, 'get_default')) {
            return course_category_parent_class::get_default();
        }
        return 0;
    }
}
