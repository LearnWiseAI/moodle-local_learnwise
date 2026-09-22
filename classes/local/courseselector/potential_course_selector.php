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

namespace local_learnwise\local\courseselector;

/**
 * Class potential_course_selector
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class potential_course_selector extends course_selector_base {
    /** @var bool */
    protected $current = false;

    /**
     * potential_course_selector constructor.
     * @param string $search
     * @return array $options
     */
    public function find_courses($search) {
        global $DB;

        $fields = "SELECT c.*";
        $countfields = "SELECT COUNT(1)";
        $sql = " FROM {course} c WHERE c.id > 1";
        $params = [];

        $sql .= ' AND c.visible = 1 ';
        $order = " ORDER BY c.fullname ASC";
        if ($search) {
            $sql .= ' AND ' . $DB->sql_like('c.fullname', ':fullname', false);
            $params['fullname'] = '%' . $search . '%';
        }

        $existingcourses = $this->get_configured_courseids();
        if (!empty($existingcourses)) {
            // The ids come out of plugin config, so they are bound as parameters rather than pasted
            // into the query. That keeps the SQL safe whatever ends up in the stored value.
            [$insql, $inparams] = $DB->get_in_or_equal($existingcourses, SQL_PARAMS_NAMED, 'courseid', $this->current);
            $sql .= " AND c.id $insql";
            $params += $inparams;
        } else if ($this->current) {
            return [];
        }

        if (!$this->is_validating()) {
            $potentialmemberscount = $DB->count_records_sql($countfields . $sql, $params);
            if ($potentialmemberscount > 100) {
                return $this->too_many_results($search, $potentialmemberscount);
            }
        }
        $availableusers = $DB->get_records_sql($fields . $sql . $order, $params);

        foreach ($availableusers as $availableuser) {
            $availableuser->fullname = format_string($availableuser->fullname);
            $availableuser->shortname = format_string($availableuser->shortname);
        }

        if (empty($availableusers)) {
            return [];
        }

        if ($search) {
            $identifier = ($this->current) ? "allowecoursesmatching" : "denycoursesmatching";
            $groupname = get_string($identifier, 'local_learnwise', $search);
        } else {
            $identifier = ($this->current) ? "allowecourses" : "denycourses";
            $groupname = get_string($identifier, 'local_learnwise');
        }

        return [$groupname => $availableusers];
    }

    /**
     * The configured course ids, as integers.
     *
     * The setting is a comma separated string written by the course selector page. Anything that is
     * not a positive integer is dropped, so a value that was stored by some other route cannot reach
     * the query.
     *
     * @return int[]
     */
    protected function get_configured_courseids() {
        $stored = get_config('local_learnwise', 'courseids');
        if (empty($stored)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $stored)), function ($courseid) {
            return $courseid > 0;
        }));
    }
}
