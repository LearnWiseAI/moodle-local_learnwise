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
use context_module;
use external_value;
use mod_scorm_external;

/**
 * Class scorms
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorms extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'scorms';

    /**
     * Returns the parameters for the execute function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $courseid ID of course
     * @return external_description
     */
    public static function execute($courseid) {
        global $USER;
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        if (static::is_singleoperation()) {
            $context = context_module::instance(static::get_id());
        }
        static::validate_context($context);

        $response = mod_scorm_external::get_scorms_by_courses([$params['courseid']]);

        if (!empty($response['scorms'])) {
            $returnscorms = [];
            foreach ($response['scorms'] as $scorm) {
                $scormdata = [
                    'id' => $scorm['coursemodule'],
                    'name' => $scorm['name'],
                    'type' => $scorm['scormtype'],
                ];
                if (static::is_singleoperation($scormdata['id'])) {
                    $scormdata['packageurl'] = $scorm['packageurl'];
                    $scormdata['sha1hash'] = $scorm['sha1hash'];
                    return $scormdata;
                }
                $returnscorms[] = $scormdata;
            }
            return $returnscorms;
        }

        return [];
    }

    /**
     * Returns the structure of a single scorm.
     *
     * @return \external_single_structure
     */
    public static function single_structure() {
        $structure = mod_scorm_external::get_scorms_by_courses_returns()->keys['scorms']->content;
        $structure->keys['type'] = $structure->keys['scormtype'];
        $structure->keys = array_filter($structure->keys, function ($key) {
            return in_array($key, ['id', 'name', 'type', 'packageurl', 'sha1hash']);
        }, ARRAY_FILTER_USE_KEY);
        if (!static::is_singleoperation()) {
            unset(
                $structure->keys['packageurl'],
                $structure->keys['sha1hash']
            );
        }
        return $structure;
    }
}
