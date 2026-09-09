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

use advanced_testcase;
use external_value;

/**
 * Tests for the timestamp flavoured external value.
 *
 * @covers     \local_learnwise\external\timestampvalue
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class timestampvalue_test extends advanced_testcase {
    /**
     * Make the legacy external API class aliases available.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->libdir . '/externallib.php');
    }
    /**
     * A timestamp value carries the marker the response cleaner looks for.
     */
    public function test_it_is_flagged_as_a_unix_stamp(): void {
        $value = timestampvalue::make(new external_value(PARAM_INT, 'created'));

        $this->assertInstanceOf(external_value::class, $value);
        $this->assertTrue($value->isunixstamp);
    }

    /**
     * The converted value is emitted as text, since ISO 8601 is no longer an integer.
     */
    public function test_make_switches_the_type_to_text(): void {
        $value = timestampvalue::make(new external_value(PARAM_INT, 'created'));

        $this->assertSame(PARAM_TEXT, $value->type);
    }

    /**
     * make() carries over the description and the optionality of the source value.
     */
    public function test_make_preserves_the_source_description(): void {
        $source = new external_value(PARAM_INT, 'time the post was created', VALUE_OPTIONAL, 12345, NULL_ALLOWED);

        $value = timestampvalue::make($source);

        $this->assertSame('time the post was created', $value->desc);
        $this->assertSame(VALUE_OPTIONAL, $value->required);
        $this->assertSame(12345, $value->default);
        $this->assertSame(NULL_ALLOWED, $value->allownull);
    }

    /**
     * A required source value stays required.
     */
    public function test_make_preserves_a_required_source(): void {
        $value = timestampvalue::make(new external_value(PARAM_INT, 'created', VALUE_REQUIRED, null, NULL_NOT_ALLOWED));

        $this->assertSame(VALUE_REQUIRED, $value->required);
        $this->assertSame(NULL_NOT_ALLOWED, $value->allownull);
    }
}
