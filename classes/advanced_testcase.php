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

defined('MOODLE_INTERNAL') || die();

use Yoast\PHPUnitPolyfills\Helpers;
use Yoast\PHPUnitPolyfills\Polyfills;

require_once($CFG->dirroot . '/local/learnwise/tests/vendor/polyfill-php81/bootstrap.php');
require_once($CFG->dirroot . '/local/learnwise/tests/vendor/polyfill-php81/Php81.php');
require_once($CFG->dirroot . '/local/learnwise/tests/vendor/phpunit-polyfills/phpunitpolyfills-autoload.php');

/**
 * Class advanced_testcase
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class advanced_testcase extends \advanced_testcase {
    use Helpers\AssertAttributeHelper;
	use Polyfills\AssertClosedResource;
	use Polyfills\AssertEqualsSpecializations;
	use Polyfills\AssertFileEqualsSpecializations;
	use Polyfills\AssertIgnoringLineEndings;
	use Polyfills\AssertionRenames;
	use Polyfills\AssertIsList;
	use Polyfills\AssertIsType;
	use Polyfills\AssertObjectEquals;
	use Polyfills\AssertObjectProperty;
	use Polyfills\AssertStringContains;
	use Polyfills\EqualToSpecializations;
	use Polyfills\ExpectExceptionMessageMatches;
	use Polyfills\ExpectExceptionObject;
}
