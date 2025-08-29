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

/**
 * Class external_value
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class timestampvalue extends \external_value {
    /**
     * Summary of isunixstamp
     * @var bool
     */
    public $isunixstamp = true;

    /**
     * Create a new instance of timestampvalue.
     *
     * @param \external_value $extenalvalue The external value to base this on.
     * @return static
     */
    public static function make(\external_value $extenalvalue) {
        return new static(
            PARAM_TEXT, $extenalvalue->desc,
            $extenalvalue->required, $extenalvalue->default,
            $extenalvalue->allownull
        );
    }

}
