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
use context_module;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\assign\grade;
use local_learnwise\external\assign\submissions;

/**
 * Tests for the shared external API base class.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\external\baseapi
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class baseapi_test extends advanced_testcase {
    /**
     * The value of $ME before a test replaced it.
     *
     * @var string|null
     */
    protected $originalme = null;

    /**
     * Single-operation ids live in shared static state, so clear them between tests.
     */
    protected function setUp(): void {
        global $CFG, $ME;

        // Loaded here rather than at file scope: baseapi.php pulls in the deprecated
        // lib/externallib.php, which refuses to load outside an isolated process.
        require_once($CFG->dirroot . '/local/learnwise/tests/fixtures/testable_baseapi.php');
        require_once($CFG->dirroot . '/local/learnwise/tests/fixtures/other_testable_baseapi.php');

        parent::setUp();

        $this->originalme = $ME;
        baseapi::$my = null;
        baseapi::$ids = [];
    }

    /**
     * Leave no single-operation state behind for other tests.
     */
    protected function tearDown(): void {
        global $ME;
        $ME = $this->originalme;
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * Without an id set, the endpoint is in list mode.
     */
    public function test_is_singleoperation_defaults_to_false(): void {
        $this->assertFalse(testable_baseapi::is_singleoperation());
        $this->assertNull(testable_baseapi::get_id());
    }

    /**
     * Setting an id switches the endpoint into single-operation mode.
     */
    public function test_set_id_enables_singleoperation(): void {
        testable_baseapi::set_id(42);

        $this->assertTrue(testable_baseapi::is_singleoperation());
        $this->assertSame(42, testable_baseapi::get_id());
    }

    /**
     * Single-operation state is tracked per class, not globally.
     */
    public function test_singleoperation_state_is_per_class(): void {
        testable_baseapi::set_id(42);

        $this->assertTrue(testable_baseapi::is_singleoperation());
        $this->assertFalse(other_testable_baseapi::is_singleoperation());
        $this->assertNull(other_testable_baseapi::get_id());
    }

    /**
     * In list mode no record is skipped.
     */
    public function test_skip_record_in_list_mode(): void {
        $this->assertFalse(testable_baseapi::skip_record(1));
        $this->assertFalse(testable_baseapi::skip_record(999));
    }

    /**
     * In single-operation mode only the requested record survives.
     */
    public function test_skip_record_in_single_mode(): void {
        testable_baseapi::set_id(7);

        $this->assertFalse(testable_baseapi::skip_record(7));
        $this->assertTrue(testable_baseapi::skip_record(8));
    }

    /**
     * List mode wraps the single structure in a multiple structure.
     */
    public function test_execute_returns_is_a_list_by_default(): void {
        $returns = testable_baseapi::execute_returns();

        $this->assertInstanceOf(external_multiple_structure::class, $returns);
        $this->assertInstanceOf(external_single_structure::class, $returns->content);
    }

    /**
     * Single-operation mode returns the bare single structure.
     */
    public function test_execute_returns_is_a_single_structure_in_single_mode(): void {
        testable_baseapi::set_id(1);
        $returns = testable_baseapi::execute_returns();

        $this->assertInstanceOf(external_single_structure::class, $returns);
    }

    /**
     * Declared timestamp fields are upgraded to the timestamp value type.
     */
    public function test_execute_returns_wraps_timestamp_fields(): void {
        testable_baseapi::set_id(1);
        $returns = testable_baseapi::execute_returns();

        $this->assertInstanceOf(timestampvalue::class, $returns->keys['timecreated']);
        $this->assertTrue($returns->keys['timecreated']->isunixstamp);

        // Non-timestamp fields keep their original type.
        $this->assertNotInstanceOf(timestampvalue::class, $returns->keys['id']);
        $this->assertInstanceOf(external_value::class, $returns->keys['id']);
    }

    /**
     * A timestamp value preserves the description of the value it wraps.
     */
    public function test_timestampvalue_make_preserves_description(): void {
        $original = new external_value(PARAM_INT, 'Created time');
        $wrapped = timestampvalue::make($original);

        $this->assertSame('Created time', $wrapped->desc);
        $this->assertSame(PARAM_TEXT, $wrapped->type);
        $this->assertTrue($wrapped->isunixstamp);
    }

    /**
     * Unix timestamps are converted to ISO 8601 in the server timezone.
     */
    public function test_converttimestamp_produces_iso8601(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');

        $this->assertSame('1970-01-01T00:00:00+00:00', baseapi::converttimestamp(0));
        $this->assertSame('2021-01-01T00:00:00+00:00', baseapi::converttimestamp(1609459200));
        // Numeric strings are accepted too.
        $this->assertSame('2021-01-01T00:00:00+00:00', baseapi::converttimestamp('1609459200'));
    }

    /**
     * The conversion follows the configured server timezone.
     */
    public function test_converttimestamp_uses_server_timezone(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Amsterdam');

        // 2021-01-01T00:00:00Z is 01:00 in Amsterdam (UTC+1 in January).
        $this->assertSame('2021-01-01T01:00:00+01:00', baseapi::converttimestamp(1609459200));
    }

    /**
     * Values that are not plausible unix timestamps are passed through untouched.
     */
    public function test_converttimestamp_passes_through_non_timestamps(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');

        $this->assertSame('hello', baseapi::converttimestamp('hello'));
        $this->assertSame('', baseapi::converttimestamp(''));
        $this->assertNull(baseapi::converttimestamp(null));
        $this->assertSame(-1, baseapi::converttimestamp(-1));
        $this->assertSame('1.5', baseapi::converttimestamp('1.5'));
    }

    /**
     * Pluginfile URLs in string responses are rewritten to the plugin's proxy.
     */
    public function test_clean_returnvalue_rewrites_pluginfile_urls(): void {
        global $CFG;
        $this->resetAfterTest();

        $proxy = "{$CFG->wwwroot}/local/learnwise/api/file.php";
        $description = new external_value(PARAM_RAW, 'Body');

        foreach (['/webservice/pluginfile.php', '/tokenpluginfile.php', '/pluginfile.php'] as $path) {
            $input = "before {$CFG->wwwroot}{$path}/1/mod_page/intro/file.txt after";
            $cleaned = baseapi::clean_returnvalue($description, $input);

            $this->assertSame(
                "before {$proxy}/1/mod_page/intro/file.txt after",
                $cleaned,
                "URL path {$path} should be proxied"
            );
        }
    }

    /**
     * Strings that contain no pluginfile URL are left alone.
     */
    public function test_clean_returnvalue_leaves_other_strings_alone(): void {
        $this->resetAfterTest();

        $description = new external_value(PARAM_RAW, 'Body');
        $this->assertSame('plain text', baseapi::clean_returnvalue($description, 'plain text'));
    }

    /**
     * Rewriting happens inside nested structures, not just top level strings.
     */
    public function test_clean_returnvalue_rewrites_within_structures(): void {
        global $CFG;
        $this->resetAfterTest();

        $description = new external_single_structure([
            'body' => new external_value(PARAM_RAW, 'Body'),
        ]);
        $cleaned = baseapi::clean_returnvalue($description, [
            'body' => "{$CFG->wwwroot}/pluginfile.php/1/mod_page/intro/f.txt",
        ]);

        $this->assertSame(
            "{$CFG->wwwroot}/local/learnwise/api/file.php/1/mod_page/intro/f.txt",
            $cleaned['body']
        );
    }

    /**
     * A timestamp-typed field is converted rather than string-cleaned.
     */
    public function test_clean_returnvalue_converts_timestamp_descriptions(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');

        $description = timestampvalue::make(new external_value(PARAM_INT, 'Created'));
        $this->assertSame('2021-01-01T00:00:00+00:00', baseapi::clean_returnvalue($description, 1609459200));
    }

    /**
     * The external function name is derived from the class namespace, dropping "external".
     */
    public function test_function_name(): void {
        $this->assertSame('local_learnwise_testable_baseapi', testable_baseapi::function_name());
    }

    /**
     * Namespaced endpoints keep their subnamespace in the function name.
     */
    public function test_function_name_for_nested_namespace(): void {
        $this->assertSame('local_learnwise_assign_grade', grade::function_name());
        $this->assertSame('local_learnwise_assign_submissions', submissions::function_name());
    }

    /**
     * Read endpoints declare the read CRUD type; write endpoints override it.
     */
    public function test_crudtype(): void {
        $this->assertSame('read', testable_baseapi::crudtype());
        $this->assertSame('write', grade::crudtype());
    }

    /**
     * The generated function info matches what Moodle's external API registry expects.
     */
    public function test_function_info(): void {
        $info = testable_baseapi::function_info();

        $this->assertSame('local_learnwise_testable_baseapi', $info['name']);
        $this->assertSame(testable_baseapi::class, $info['classname']);
        $this->assertSame('execute', $info['methodname']);
        $this->assertSame('local_learnwise', $info['component']);
        $this->assertSame('Endpoint used by unit tests', $info['descripion']);
        $this->assertTrue($info['loginrequired']);
        $this->assertSame('read', $info['type']);
    }

    /**
     * Every concrete endpoint in the plugin is discoverable as a callback.
     */
    public function test_exernal_callbacks_discovers_real_endpoints(): void {
        $callbacks = baseapi::exernal_callbacks();

        $this->assertArrayHasKey('local_learnwise_courses', $callbacks);
        $this->assertArrayHasKey('local_learnwise_assign_grade', $callbacks);
        $this->assertArrayHasKey('local_learnwise_assign_submissions', $callbacks);

        foreach ($callbacks as $name => $info) {
            $this->assertSame($name, $info['name']);
            $this->assertTrue(
                method_exists($info['classname'], $info['methodname']),
                "{$info['classname']}::{$info['methodname']}() must exist"
            );
            $this->assertContains($info['type'], ['read', 'write'], "{$name} must declare a valid CRUD type");
            $this->assertNotEmpty($info['descripion'], "{$name} must have a description");
        }
    }

    /**
     * Abstract classes are not exposed as callable endpoints.
     */
    public function test_exernal_callbacks_excludes_abstract_classes(): void {
        $callbacks = baseapi::exernal_callbacks();

        foreach (array_keys($callbacks) as $name) {
            $this->assertNotSame('local_learnwise_baseapi', $name);
        }
    }

    /**
     * A stored file is turned into a pluginfile URL that points at the file itself.
     */
    public function test_file_url_from_stored_file(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = context_module::instance($page->cmid);

        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'doc.txt',
        ], 'body');

        $url = baseapi::file_url_from_stored_file($file)->out(false);

        $this->assertContains('/pluginfile.php/', $url);
        $this->assertContains("/{$context->id}/mod_page/intro/", $url);
        $this->assertStringEndsWith('doc.txt', $url);
    }

    /**
     * The native endpoint check keys off the webservice entry point.
     */
    public function test_called_native_endpoint(): void {
        global $ME;
        $this->resetAfterTest();

        $original = $ME;
        try {
            $ME = '/webservice/rest/server.php';
            $this->assertTrue(baseapi::called_native_endpoint());

            $ME = '/local/learnwise/api/index.php';
            $this->assertFalse(baseapi::called_native_endpoint());
        } finally {
            $ME = $original;
        }
    }

    /**
     * Input parameters are cleaned against the declared parameter definition.
     */
    public function test_prepare_input_params(): void {
        $this->resetAfterTest();

        $params = testable_baseapi::prepare_input_params(['id' => '15']);
        $this->assertSame(15, $params['id']);
    }

    /**
     * base_parameters() merges the caller's fields into an external_function_parameters.
     */
    public function test_base_parameters_merges_additional_fields(): void {
        $params = baseapi::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);

        $this->assertInstanceOf(external_function_parameters::class, $params);
        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * base_parameters() called without arguments still returns a usable structure.
     */
    public function test_base_parameters_without_additional_fields(): void {
        $params = baseapi::base_parameters();

        $this->assertInstanceOf(external_function_parameters::class, $params);
        $this->assertSame([], $params->keys);
    }

    /**
     * An API is a list operation until an id is pinned onto it.
     */
    public function test_set_id_switches_the_api_to_a_single_operation(): void {
        $this->assertFalse(courses::is_singleoperation());
        $this->assertNull(courses::get_id());

        courses::set_id(42);

        $this->assertTrue(courses::is_singleoperation());
        $this->assertSame(42, courses::get_id());
    }

    /**
     * Pinning an id on one API must not leak into a sibling API.
     */
    public function test_single_operation_state_is_per_class(): void {
        courses::set_id(42);

        $this->assertTrue(courses::is_singleoperation());
        $this->assertFalse(sections::is_singleoperation());
        $this->assertNull(sections::get_id());
    }

    /**
     * A list operation never skips records.
     */
    public function test_skip_record_keeps_everything_for_a_list_operation(): void {
        $this->assertFalse(courses::skip_record(1));
        $this->assertFalse(courses::skip_record(999));
    }

    /**
     * A single operation keeps only the pinned record.
     */
    public function test_skip_record_keeps_only_the_pinned_record(): void {
        courses::set_id(7);

        $this->assertFalse(courses::skip_record(7));
        $this->assertFalse(courses::skip_record('7'));
        $this->assertTrue(courses::skip_record(8));
    }

    /**
     * A list operation returns a multiple structure, a single operation the bare structure.
     */
    public function test_execute_returns_follows_the_operation_mode(): void {
        $this->assertInstanceOf(external_multiple_structure::class, sections::execute_returns());

        sections::set_id(3);

        $this->assertInstanceOf(external_single_structure::class, sections::execute_returns());
    }

    /**
     * Fields named by get_unixtimestamp_fields() are rewritten as timestamp values.
     */
    public function test_execute_returns_wraps_declared_timestamp_fields(): void {
        $structure = courses::execute_returns()->content;

        $this->assertInstanceOf(timestampvalue::class, $structure->keys['startdate']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['enddate']);
        $this->assertNotInstanceOf(timestampvalue::class, $structure->keys['id']);
    }

    /**
     * Unix timestamps are rendered as ISO 8601 in the server timezone.
     *
     * @param mixed $input Value handed to the converter
     * @param mixed $expected Value the converter should produce
     * @dataProvider converttimestamp_provider
     */
    public function test_converttimestamp($input, $expected): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');

        $this->assertSame($expected, baseapi::converttimestamp($input));
    }

    /**
     * Data provider for {@see test_converttimestamp}.
     *
     * @return array[]
     */
    public static function converttimestamp_provider(): array {
        return [
            'epoch' => [0, '1970-01-01T00:00:00+00:00'],
            'integer timestamp' => [1735689600, '2025-01-01T00:00:00+00:00'],
            'numeric string timestamp' => ['1735689600', '2025-01-01T00:00:00+00:00'],
            'negative timestamp is left alone' => [-1, -1],
            'fractional value is left alone' => [1.5, 1.5],
            'non numeric string is left alone' => ['tomorrow', 'tomorrow'],
            'null is left alone' => [null, null],
            'empty string is left alone' => ['', ''],
        ];
    }

    /**
     * Rewriting reaches file URLs embedded in a larger HTML fragment.
     */
    public function test_clean_returnvalue_rewrites_urls_embedded_in_html(): void {
        global $CFG;

        $html = '<p>See <a href="' . $CFG->wwwroot . '/pluginfile.php/1/mod_assign/intro/0/a.pdf">a</a> and '
            . '<a href="' . $CFG->wwwroot . '/tokenpluginfile.php/1/mod_assign/intro/0/b.pdf">b</a>.</p>';

        $cleaned = baseapi::clean_returnvalue(new external_value(PARAM_RAW, 'html'), $html);

        $this->assertNotContains($CFG->wwwroot . '/pluginfile.php', $cleaned);
        $this->assertNotContains($CFG->wwwroot . '/tokenpluginfile.php', $cleaned);
        $this->assertSame(2, substr_count($cleaned, '/local/learnwise/api/file.php'));
    }

    /**
     * Values without a file URL survive cleaning untouched.
     */
    public function test_clean_returnvalue_leaves_unrelated_values_alone(): void {
        $this->assertSame('plain text', baseapi::clean_returnvalue(new external_value(PARAM_RAW, 'x'), 'plain text'));
        $this->assertSame(5, baseapi::clean_returnvalue(new external_value(PARAM_INT, 'x'), 5));
    }

    /**
     * The default list of timestamp fields is empty.
     */
    public function test_get_unixtimestamp_fields_defaults_to_empty(): void {
        $this->assertSame([], baseapi::get_unixtimestamp_fields());
        $this->assertSame([], sections::get_unixtimestamp_fields());
    }

    /**
     * Every concrete API in the plugin is advertised as a callback, and no abstract one is.
     */
    public function test_exernal_callbacks_lists_every_concrete_api(): void {
        $callbacks = baseapi::exernal_callbacks();

        $this->assertArrayHasKey('local_learnwise_courses', $callbacks);
        $this->assertArrayHasKey('local_learnwise_plugininfo', $callbacks);
        $this->assertArrayHasKey('local_learnwise_assign_grade', $callbacks);
        $this->assertArrayNotHasKey('local_learnwise_baseapi', $callbacks);

        foreach ($callbacks as $name => $info) {
            $this->assertSame($name, $info['name']);
            $this->assertSame('execute', $info['methodname']);
            $this->assertSame('local_learnwise', $info['component']);
            $this->assertTrue(class_exists($info['classname']));
            $this->assertContains($info['type'], ['read', 'write']);
            $this->assertNotEmpty($info['descripion']);
        }
    }

    /**
     * ws_proxy is not an API route and must stay out of the callback list.
     */
    public function test_exernal_callbacks_excludes_non_routes(): void {
        $callbacks = baseapi::exernal_callbacks();

        $this->assertArrayNotHasKey('local_learnwise_ws_proxy', $callbacks);
        $this->assertArrayNotHasKey('local_learnwise_timestampvalue', $callbacks);
    }
}
