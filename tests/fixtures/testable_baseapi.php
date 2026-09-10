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
 * Concrete baseapi subclass used to exercise the shared base behaviour.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnwise\external;

use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Concrete baseapi subclass used to exercise the shared base behaviour.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class testable_baseapi extends baseapi {
    /** @var string */
    public static $route = 'testable';

    /**
     * {@inheritdoc}
     */
    public static function description() {
        return 'Endpoint used by unit tests';
    }

    /**
     * {@inheritdoc}
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'id' => new external_value(PARAM_INT, 'Record id'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public static function single_structure() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Record id'),
            'name' => new external_value(PARAM_TEXT, 'Record name'),
            'description' => new external_value(PARAM_RAW, 'Record description'),
            'timecreated' => new external_value(PARAM_INT, 'Created time'),
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public static function get_unixtimestamp_fields() {
        return ['timecreated'];
    }
}
