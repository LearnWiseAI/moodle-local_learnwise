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

use local_learnwise\util;

/**
 * Tests for the plugin info endpoint.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     \local_learnwise\external\plugininfo
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class plugininfo_test extends \advanced_testcase {
    /**
     * Reset shared state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        baseapi::$ids = [];
    }

    /**
     * An admin can read the plugin setup information.
     */
    public function test_admin_can_read_plugin_info(): void {
        $this->setAdminUser();

        $result = plugininfo::execute();

        $this->assertArrayHasKey('aiops', $result);
        $this->assertArrayHasKey('clientid', $result);
        $this->assertArrayHasKey('redirecturl', $result);
        $this->assertArrayHasKey('version', $result);
        $this->assertSame(util::get_plugin_versioninfo()->release, $result['version']);
    }

    /**
     * The reported client id is the plugin's OAuth2 client.
     */
    public function test_reports_the_oauth_client_id(): void {
        $this->setAdminUser();

        $client = util::get_or_generate_client();
        $this->assertSame($client->uniqid, plugininfo::execute()['clientid']);
    }

    /**
     * Newline-separated redirect URLs are flattened to a comma separated list.
     */
    public function test_redirecturl_newlines_become_commas(): void {
        $this->setAdminUser();
        set_config('redirecturl', "https://a.test/cb\nhttps://b.test/cb", 'local_learnwise');

        $this->assertSame('https://a.test/cb,https://b.test/cb', plugininfo::execute()['redirecturl']);
    }

    /**
     * An unconfigured redirect URL is reported as an empty string, not null.
     */
    public function test_redirecturl_defaults_to_empty_string(): void {
        $this->setAdminUser();

        $this->assertSame('', plugininfo::execute()['redirecturl']);
    }

    /**
     * The aiops flag reflects the stored setting.
     */
    public function test_aiops_flag(): void {
        $this->setAdminUser();

        $this->assertFalse(plugininfo::execute()['aiops']);

        set_config('aiops', 1, 'local_learnwise');
        $this->assertTrue(plugininfo::execute()['aiops']);
    }

    /**
     * A user without the plugininfo capability is refused.
     */
    public function test_requires_plugininfo_capability(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\required_capability_exception::class);

        plugininfo::execute();
    }

    /**
     * The endpoint always reports itself as a single operation.
     */
    public function test_is_always_a_single_operation(): void {
        $this->assertTrue(plugininfo::is_singleoperation());
        $this->assertInstanceOf(\external_single_structure::class, plugininfo::execute_returns());
    }

    /**
     * The response validates against the declared structure.
     */
    public function test_response_matches_declared_structure(): void {
        $this->setAdminUser();

        $cleaned = plugininfo::clean_returnvalue(plugininfo::execute_returns(), plugininfo::execute());

        $this->assertArrayHasKey('clientid', $cleaned);
        $this->assertArrayHasKey('version', $cleaned);
    }
}
