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

namespace local_learnwise\external;

use context_course;
use external_format_value;
use external_single_structure;
use external_value;

/**
 * Class sections
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sections extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'sections';

    #[\Override]
    public static function description() {
        return 'Get sections';
    }

    #[\Override]
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $courseid ID of course
     * @return array
     */
    public static function execute($courseid) {
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        static::validate_context($context);

        $course = get_course($params['courseid']);
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();

        $sectioncontents = [];
        foreach ($sections as $section) {
            if (self::skip_record($section->id)) {
                continue;
            }
            if (!$section->visible && !has_capability('moodle/course:viewhiddensections', $context)) {
                continue;
            }
            $sectionvalues = [];
            $summaryvalues = external_format_text(
                $section->summary,
                $section->summaryformat,
                $context->id,
                'course',
                'section',
                $section->id
            );
            $sectionvalues['id'] = $section->id;
            $sectionvalues['name'] = get_section_name($course, $section);
            $sectionvalues['visible'] = $section->visible;
            $sectionvalues['summary'] = $summaryvalues[0];
            $sectionvalues['summaryformat'] = $summaryvalues[1];
            /* @phpstan-ignore property.notFound */
            $sectionvalues['section'] = $section->section;
            $sectionvalues['uservisible'] = $section->uservisible;
            $sectionvalues['useravailable'] = $section->available;
            if (!empty($section->availableinfo)) {
                $sectionvalues['availabilityinfo'] = \core_availability\info::format_info($section->availableinfo, $course);
            }
            if (self::is_singleoperation()) {
                return $sectionvalues;
            }
            $sectioncontents[] = $sectionvalues;
        }

        return $sectioncontents;
    }

    #[\Override]
    public static function single_structure() {
        $sectionstructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id of secion'),
            'name' => new external_value(PARAM_TEXT, 'name of section'),
            'visible' => new external_value(PARAM_INT, 'is the section visible', VALUE_OPTIONAL),
            'summary' => new external_value(PARAM_RAW, 'Section description'),
            'summaryformat' => new external_format_value('summary'),
            'section' => new external_value(PARAM_INT, 'Section number inside the course', VALUE_OPTIONAL),
            'uservisible' => new external_value(PARAM_BOOL, 'Is the section visible for the user?', VALUE_OPTIONAL),
            'useravailable' => new external_value(PARAM_BOOL, 'Is the section available for the user?', VALUE_OPTIONAL),
            'availabilityinfo' => new external_value(PARAM_RAW, 'Availability information.', VALUE_OPTIONAL),
        ]);
        return $sectionstructure;
    }
}
