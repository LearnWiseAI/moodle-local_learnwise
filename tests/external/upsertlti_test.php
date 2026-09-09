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
use external_single_structure;
use local_learnwise\constants;
use local_learnwise\util;
use required_capability_exception;

/**
 * Tests for the LTI tool registration API.
 *
 * @covers     \local_learnwise\external\upsertlti
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class upsertlti_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/locallib.php');

        parent::setUp();
        $this->resetAfterTest();
        baseapi::$my = null;
        baseapi::$ids = [];
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * Everything is optional, so the API can both create and update.
     */
    public function test_execute_parameters_are_all_optional(): void {
        $params = upsertlti::execute_parameters();

        $this->assertSame(VALUE_DEFAULT, $params->keys['id']->required);
        $this->assertSame(0, $params->keys['id']->default);
        $this->assertSame(VALUE_DEFAULT, $params->keys['name']->required);
        $this->assertSame(VALUE_DEFAULT, $params->keys['assistantid']->required);
    }

    /**
     * The API always answers with one tool rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(upsertlti::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, upsertlti::execute_returns());
    }

    /**
     * The response carries everything the LearnWise side needs to complete the LTI handshake.
     */
    public function test_single_structure_describes_the_lti_handshake(): void {
        $structure = upsertlti::single_structure();

        $this->assertSame(['id', 'assistantid', 'data'], array_keys($structure->keys));
        $this->assertSame(
            ['platformid', 'clientid', 'deploymentid', 'publickeyseturl', 'accesstokenurl', 'authrequesturl'],
            array_keys($structure->keys['data']->keys)
        );
    }

    /**
     * Passing a zero id creates a new tool registration.
     */
    public function test_execute_creates_a_new_lti_tool(): void {
        $this->setAdminUser();

        $ltitype = upsertlti::execute(0, 'LearnWise', 'assistant123');

        $this->assertGreaterThan(0, $ltitype->id);
        $this->assertSame('LearnWise', $ltitype->name);
        $this->assertSame('assistant123', $ltitype->assistantid);
        $this->assertSame(util::get_ltitoolurl(), $ltitype->baseurl);
    }

    /**
     * A newly created tool is remembered in the plugin configuration.
     */
    public function test_execute_records_the_new_tool_id(): void {
        $this->setAdminUser();

        $ltitype = upsertlti::execute(0, 'LearnWise', 'assistant123');

        $this->assertSame((string) $ltitype->id, get_config(constants::COMPONENT, 'ltitypeids'));
    }

    /**
     * Passing an existing id renames that tool instead of creating a second one.
     */
    public function test_execute_updates_an_existing_lti_tool(): void {
        $this->setAdminUser();
        $created = upsertlti::execute(0, 'LearnWise', 'assistant123');

        $updated = upsertlti::execute($created->id, 'LearnWise EU', 'assistant456');

        $this->assertSame((int) $created->id, (int) $updated->id);
        $this->assertSame('LearnWise EU', $updated->name);
        $this->assertSame('assistant456', $updated->assistantid);
        $this->assertSame((string) $created->id, get_config(constants::COMPONENT, 'ltitypeids'));
    }

    /**
     * Updating without naming an assistant leaves the configured assistant alone.
     */
    public function test_execute_keeps_the_assistant_when_it_is_omitted(): void {
        $this->setAdminUser();
        $created = upsertlti::execute(0, 'LearnWise', 'assistant123');

        $updated = upsertlti::execute($created->id, 'LearnWise EU', '');

        $this->assertSame('assistant123', $updated->assistantid);
    }

    /**
     * The tool is wired up against the environment's LearnWise endpoints.
     */
    public function test_execute_points_the_tool_at_the_configured_environment(): void {
        $this->setAdminUser();
        set_config('environment', 'production', constants::COMPONENT);

        $ltitype = upsertlti::execute(0, 'LearnWise', 'assistant123');
        $config = $ltitype->config;

        $this->assertSame('https://chat.learnwise.ai', $ltitype->baseurl);
        $this->assertSame('https://lti.learnwise.ai/lti/jwks', $config['publickeyset']);
        $this->assertSame('https://lti.learnwise.ai/lti', $config['initiatelogin']);
        $this->assertSame(LTI_VERSION_1P3, $ltitype->ltiversion);
    }

    /**
     * The response validates against the API's own declared structure.
     */
    public function test_execute_matches_the_declared_structure(): void {
        $this->setAdminUser();

        $cleaned = upsertlti::clean_returnvalue(
            upsertlti::execute_returns(),
            upsertlti::execute(0, 'LearnWise', 'assistant123')
        );

        $this->assertSame(['id', 'assistantid', 'data'], array_keys($cleaned));
        $this->assertNotEmpty($cleaned['data']['clientid']);
    }

    /**
     * Only a site administrator may register the tool.
     */
    public function test_execute_requires_site_configuration_rights(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(required_capability_exception::class);
        upsertlti::execute(0, 'LearnWise', 'assistant123');
    }
}
