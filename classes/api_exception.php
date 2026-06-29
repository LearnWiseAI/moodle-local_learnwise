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

namespace local_learnwise;

use moodle_exception;

/**
 * Class api_exception
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends moodle_exception {
    /**
     * Constructor
     * @param string $errorcode The name of the string
     * @param string $module name of module
     * @param string $link The url where the user will be prompted to continue.
     * @param mixed $a Extra words and phrases that might be required in the error string
     * @param string $debuginfo optional debugging information
     */
    public function __construct(// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
        $errorcode = 'error',
        $module = constants::COMPONENT,
        $link = '',
        $a = null,
        $debuginfo = null
    ) {
        parent::__construct($errorcode, $module, $link, $a, $debuginfo);
    }
}
