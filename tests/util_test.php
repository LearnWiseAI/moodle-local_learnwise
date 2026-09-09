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

use advanced_testcase;
use context_module;
use context_system;

/**
 * Tests for the plugin utility helpers.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\util
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class util_test extends advanced_testcase {
    /**
     * The component name is read from the COMPONENT constant when the subclass defines one.
     */
    public function test_component_uses_subclass_constant(): void {
        $this->assertSame('local_learnwise', constants::component());
    }

    /**
     * An unset environment falls back to the last configured environment.
     */
    public function test_get_env_defaults_to_last_environment(): void {
        $this->resetAfterTest();

        $envs = constants::ENVIRONMENTS;
        $this->assertSame(end($envs), util::get_env());
    }

    /**
     * A garbage environment value is not trusted and falls back to the default.
     */
    public function test_get_env_rejects_unknown_value(): void {
        $this->resetAfterTest();
        set_config('environment', 'not-a-real-env', 'local_learnwise');

        $envs = constants::ENVIRONMENTS;
        $this->assertSame(end($envs), util::get_env());
    }

    /**
     * A valid configured environment is returned verbatim.
     */
    public function test_get_env_returns_configured_value(): void {
        $this->resetAfterTest();
        set_config('environment', 'production', 'local_learnwise');

        $this->assertSame('production', util::get_env());
    }

    /**
     * Only the three known environments validate.
     */
    public function test_valid_env(): void {
        $this->assertTrue(util::valid_env('production'));
        $this->assertTrue(util::valid_env('development'));
        $this->assertTrue(util::valid_env('sandbox'));
        $this->assertFalse(util::valid_env('staging'));
        $this->assertFalse(util::valid_env(''));
        $this->assertFalse(util::valid_env(null));
    }

    /**
     * Data provider mapping each environment to its expected host URLs.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function env_url_provider(): array {
        return [
            'production' => [
                'production',
                'https://chat.learnwise.ai',
                'https://aiden.learnwise.ai',
                'https://feedback.learnwise.ai',
            ],
            'development' => [
                'development',
                'https://chat.learnwise.dev',
                'https://aiden.learnwise.dev',
                'https://feedback.learnwise.dev',
            ],
            'sandbox' => [
                'sandbox',
                'https://chat.sandbox.learnwise.dev',
                'https://aiden-sbx.learnwise.dev',
                'https://feedback.sandbox.learnwise.dev',
            ],
        ];
    }

    /**
     * Each environment maps to the documented LTI, remote and assessment hosts.
     *
     * @dataProvider env_url_provider
     * @param string $env Environment name
     * @param string $ltitool Expected LTI tool URL
     * @param string $remotehost Expected remote host URL
     * @param string $assessmenthost Expected assessment host URL
     */
    public function test_urls_per_environment($env, $ltitool, $remotehost, $assessmenthost): void {
        $this->resetAfterTest();

        $this->assertSame($ltitool, util::get_ltitoolurl($env));
        $this->assertSame($remotehost, util::get_remotehosturl($env));
        $this->assertSame($assessmenthost, util::get_assessmenthosturl($env));
    }

    /**
     * An invalid environment argument falls back to the configured environment.
     */
    public function test_get_ltitoolurl_falls_back_to_configured_env(): void {
        $this->resetAfterTest();
        set_config('environment', 'production', 'local_learnwise');

        $this->assertSame('https://chat.learnwise.ai', util::get_ltitoolurl('bogus'));
        $this->assertSame('https://chat.learnwise.ai', util::get_ltitoolurl(null));
    }

    /**
     * The sandbox remote host collapses "aiden.sandbox" into the "aiden-sbx" short form.
     *
     * Regression guard for the sandbox host fix.
     */
    public function test_sandbox_remote_host_uses_short_form(): void {
        $this->resetAfterTest();

        $this->assertSame('https://aiden-sbx.learnwise.dev', util::get_remotehosturl('sandbox'));
        $this->assertStringNotContainsString('aiden.sandbox', util::get_remotehosturl('sandbox'));
    }

    /**
     * The LTI prefix URL swaps the chat subdomain and shortens the sandbox host.
     */
    public function test_get_ltiprefixurl_per_environment(): void {
        $this->resetAfterTest();

        $this->assertSame('https://lti.learnwise.ai', util::get_ltiprefixurl('production'));
        $this->assertSame('https://lti.learnwise.dev', util::get_ltiprefixurl('development'));
        $this->assertSame('https://lti-sbx.learnwise.dev', util::get_ltiprefixurl('sandbox'));
    }

    /**
     * Regions in the prefixed list get a region-specific LTI host, but only on production.
     */
    public function test_get_ltiprefixurl_region_prefixing(): void {
        $this->resetAfterTest();

        set_config('region', 'ca', 'local_learnwise');
        $this->assertSame('https://lti-ca.learnwise.ai', util::get_ltiprefixurl('production'));

        set_config('region', 'au', 'local_learnwise');
        $this->assertSame('https://lti-au.learnwise.ai', util::get_ltiprefixurl('production'));

        // Sandbox is never region prefixed.
        $this->assertSame('https://lti-sbx.learnwise.dev', util::get_ltiprefixurl('sandbox'));
    }

    /**
     * Regions outside the prefixed list leave the LTI host untouched.
     */
    public function test_get_ltiprefixurl_ignores_unprefixed_regions(): void {
        $this->resetAfterTest();

        foreach (['eu', 'uk', 'us'] as $region) {
            set_config('region', $region, 'local_learnwise');
            $this->assertSame(
                'https://lti.learnwise.ai',
                util::get_ltiprefixurl('production'),
                "Region {$region} should not be prefixed"
            );
        }
    }

    /**
     * With no region configured the default region applies and adds no prefix.
     */
    public function test_get_ltiprefixurl_defaults_to_default_region(): void {
        $this->resetAfterTest();

        $this->assertSame(constants::REGION, 'eu');
        $this->assertSame('https://lti.learnwise.ai', util::get_ltiprefixurl('production'));
    }

    /**
     * The assessment host override only applies while dev mode is on.
     */
    public function test_get_assessmenthosturl_override_requires_devmode(): void {
        global $CFG;
        $this->resetAfterTest();

        set_config('assessmenthost', 'https://local.test', 'local_learnwise');

        // No dev mode: the override is ignored.
        unset($CFG->learnwisedevmode);
        $this->assertSame('https://feedback.learnwise.ai', util::get_assessmenthosturl('production'));

        // Dev mode on: the override wins.
        $CFG->learnwisedevmode = true;
        $this->assertSame('https://local.test', util::get_assessmenthosturl('production'));
    }

    /**
     * Dev mode without a configured host still falls back to the derived host.
     */
    public function test_get_assessmenthosturl_devmode_without_override(): void {
        global $CFG;
        $this->resetAfterTest();

        $CFG->learnwisedevmode = true;
        $this->assertSame('https://feedback.learnwise.ai', util::get_assessmenthosturl('production'));
    }

    /**
     * The first call creates a client, and later calls reuse the same one.
     */
    public function test_get_or_generate_client_is_stable(): void {
        global $DB;
        $this->resetAfterTest();

        // The plugin installer already creates a client, so there is exactly one to start.
        $this->assertSame(1, $DB->count_records('local_learnwise_clients'));

        $client = util::get_or_generate_client();
        $this->assertNotEmpty($client->uniqid);
        $this->assertNotEmpty($client->secret);
        $this->assertSame(1, $DB->count_records('local_learnwise_clients'));

        $again = util::get_or_generate_client();
        $this->assertEquals($client->id, $again->id);
        $this->assertSame($client->uniqid, $again->uniqid);
        $this->assertSame(1, $DB->count_records('local_learnwise_clients'), 'No duplicate client should be created');
    }

    /**
     * The generated client id and secret use the documented lengths.
     */
    public function test_get_or_generate_client_secret_length(): void {
        $this->resetAfterTest();

        $client = util::get_or_generate_client();
        $this->assertSame(15, strlen($client->uniqid));
        $this->assertSame(100, strlen($client->secret));
    }

    /**
     * The API role is created once with every capability the plugin needs.
     */
    public function test_get_or_create_role_creates_and_reuses(): void {
        global $DB;
        $this->resetAfterTest();

        $role = util::get_or_create_role();
        $this->assertSame('learnwise_assistant', $role->shortname);

        $systemcontext = context_system::instance();
        foreach (util::ROLECAPS as $capability) {
            $this->assertSame(
                CAP_ALLOW,
                (int) $DB->get_field('role_capabilities', 'permission', [
                    'roleid' => $role->id,
                    'capability' => $capability,
                    'contextid' => $systemcontext->id,
                ]),
                "Capability {$capability} should be allowed on the assistant role"
            );
        }

        $again = util::get_or_create_role();
        $this->assertEquals($role->id, $again->id);
        $this->assertSame(1, $DB->count_records('role', ['shortname' => 'learnwise_assistant']));
    }

    /**
     * The API user is created once and assigned the assistant role at system level.
     */
    public function test_get_or_create_apiuser_creates_and_reuses(): void {
        global $DB;
        $this->resetAfterTest();

        $user = util::get_or_create_apiuser();
        $this->assertNotEmpty($user->id);

        $role = $DB->get_record('role', ['shortname' => 'learnwise_assistant'], '*', MUST_EXIST);
        $systemcontext = context_system::instance();
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $role->id,
            'userid' => $user->id,
            'contextid' => $systemcontext->id,
        ]));

        $again = util::get_or_create_apiuser();
        $this->assertEquals($user->id, $again->id, 'The API user should be reused, not duplicated');
    }

    /**
     * The plugin version info is resolved for the plugin's own component by default.
     */
    public function test_get_plugin_versioninfo(): void {
        $this->resetAfterTest();

        $info = constants::get_plugin_versioninfo();
        $this->assertSame('local_learnwise', $info->component);
        $this->assertNotEmpty($info->release);
    }

    /**
     * Responses are built with the plugin release stamped into a version header.
     */
    public function test_make_response_adds_version_header(): void {
        $this->resetAfterTest();

        $release = constants::get_plugin_versioninfo()->release;
        $response = constants::make_response(['X-Custom' => 'kept']);

        $this->assertInstanceOf(api_response::class, $response);
        $this->assertSame($release, $response->getHttpHeader('X-version'));
        $this->assertSame('kept', $response->getHttpHeader('X-Custom'));
    }

    /**
     * Course ids are appended to the stored list without duplicates.
     */
    public function test_add_courses_accumulates_without_duplicates(): void {
        $this->resetAfterTest();

        util::add_courses('3,1');
        $this->assertSame('3,1', get_config('local_learnwise', 'courseids'));

        util::add_courses('2,3');
        $stored = explode(',', get_config('local_learnwise', 'courseids'));
        sort($stored);
        $this->assertSame(['1', '2', '3'], $stored);
    }

    /**
     * Adding an empty list leaves the stored courses untouched.
     */
    public function test_add_courses_with_empty_input(): void {
        $this->resetAfterTest();

        util::add_courses('5');
        util::add_courses('');
        $this->assertSame('5', get_config('local_learnwise', 'courseids'));
    }

    /**
     * Removing course ids drops exactly those ids and leaves the rest.
     */
    public function test_remove_courses(): void {
        $this->resetAfterTest();

        util::add_courses('1,2,3,4');
        util::remove_courses('2,4');

        $stored = array_values(array_filter(explode(',', get_config('local_learnwise', 'courseids'))));
        $this->assertSame(['1', '3'], $stored);
    }

    /**
     * Removing an id that was never stored is a no-op rather than an error.
     */
    public function test_remove_courses_ignores_unknown_ids(): void {
        $this->resetAfterTest();

        util::add_courses('1,2');
        util::remove_courses('99');

        $stored = array_values(array_filter(explode(',', get_config('local_learnwise', 'courseids'))));
        $this->assertSame(['1', '2'], $stored);
    }

    /**
     * Removing courses when nothing is stored does not create the setting.
     */
    public function test_remove_courses_with_no_stored_courses(): void {
        $this->resetAfterTest();

        util::remove_courses('1');
        $this->assertFalse(get_config('local_learnwise', 'courseids'));
    }

    /**
     * Sequential integer-keyed arrays are recognised as lists.
     */
    public function test_array_is_list_for_populated_arrays(): void {
        $this->assertTrue(util::array_is_list(['a', 'b', 'c']));
        $this->assertTrue(util::array_is_list([0 => 'a', 1 => 'b']));
        $this->assertFalse(util::array_is_list(['x' => 'a']));
        $this->assertFalse(util::array_is_list([1 => 'a', 0 => 'b']));
        $this->assertFalse(util::array_is_list([0 => 'a', 2 => 'b']));
    }

    /**
     * The empty array is NOT reported as a list, unlike PHP's native array_is_list().
     *
     * This documents current behaviour: range(0, -1) evaluates to [0, -1] rather than [],
     * so the comparison against array_keys([]) fails. The helper is currently unused, but
     * the divergence from the native function it replaces is deliberate to record here.
     */
    public function test_array_is_list_diverges_from_native_for_empty_array(): void {
        $this->assertFalse(util::array_is_list([]));
        $this->assertTrue(array_is_list([]), 'Native PHP disagrees - see MDLSITE note in util::array_is_list()');
    }

    /**
     * Pluginfile URLs embedded in text are extracted and normalised.
     */
    public function test_extract_pluginfile_urls_from_text(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = context_module::instance($page->cmid);

        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'attachment.txt',
        ];
        $fs->create_file_from_string($filerecord, 'file contents');

        $text = 'See <a href="@@PLUGINFILE@@/attachment.txt">the file</a>.';
        $urls = util::extract_pluginfile_urls_from_text($text, $context->id, 'mod_page', 'intro', null);

        $this->assertCount(1, $urls);
        $url = reset($urls);
        $this->assertStringContainsString('attachment.txt', $url);
        $this->assertStringNotContainsString('/webservice/pluginfile.php/', $url);
    }

    /**
     * Text that references no files yields no URLs.
     */
    public function test_extract_pluginfile_urls_from_text_without_files(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = context_module::instance($page->cmid);

        $urls = util::extract_pluginfile_urls_from_text('No files here.', $context->id, 'mod_page', 'intro', null);
        $this->assertSame([], array_values($urls));
    }

    /**
     * A stored file that is not referenced in the text is filtered out.
     */
    public function test_extract_pluginfile_urls_skips_unreferenced_files(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = context_module::instance($page->cmid);

        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'orphan.txt',
        ], 'unused');

        $urls = util::extract_pluginfile_urls_from_text('Nothing links here.', $context->id, 'mod_page', 'intro', null);
        $this->assertSame([], array_values($urls));
    }

    /**
     * A filearea addressed with a real item id also resolves, without the intro shortcut.
     */
    public function test_extract_pluginfile_urls_with_itemid(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = context_module::instance($page->cmid);

        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'body.txt',
        ], 'body');

        $text = 'Read <a href="@@PLUGINFILE@@/body.txt">this</a>.';
        $urls = util::extract_pluginfile_urls_from_text($text, $context->id, 'mod_page', 'content', 0);

        $this->assertCount(1, $urls);
        $this->assertStringContainsString('/mod_page/content/0/body.txt', reset($urls));
    }

    /**
     * A missing LTI type id yields null rather than an error.
     */
    public function test_get_lti_data_returns_null_for_missing_type(): void {
        $this->resetAfterTest();

        $this->assertNull(util::get_lti_data(0));
        $this->assertNull(util::get_lti_data(null));
        $this->assertNull(util::get_lti_data(-1));
    }
}
