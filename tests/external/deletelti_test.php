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
use invalid_parameter_exception;
use local_learnwise\constants;
use required_capability_exception;

/**
 * Tests for the LTI tool removal API.
 *
 * @covers     \local_learnwise\external\deletelti
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class deletelti_test extends advanced_testcase {
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
     * The API is driven by the LTI type id.
     */
    public function test_execute_parameters_take_an_id(): void {
        $params = deletelti::execute_parameters();

        $this->assertSame(['id'], array_keys($params->keys));
        $this->assertSame(PARAM_INT, $params->keys['id']->type);
    }

    /**
     * The API always answers with one verdict rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(deletelti::is_singleoperation());
        $this->assertSame(['success'], array_keys(deletelti::single_structure()->keys));
    }

    /**
     * Deleting a registered tool removes it and reports success.
     */
    public function test_execute_deletes_a_registered_tool(): void {
        $this->setAdminUser();
        $ltitype = upsertlti::execute(0, 'LearnWise', 'assistant123');

        $response = deletelti::execute($ltitype->id);

        $this->assertTrue($response['success']);
        $this->assertEmpty(lti_get_type($ltitype->id));
    }

    /**
     * Deleting the tool forgets it in the plugin configuration too.
     */
    public function test_execute_forgets_the_deleted_tool(): void {
        $this->setAdminUser();
        $ltitype = upsertlti::execute(0, 'LearnWise', 'assistant123');

        deletelti::execute($ltitype->id);

        $this->assertSame('', get_config(constants::COMPONENT, 'ltitypeids'));
        $this->assertEmpty(get_config(constants::COMPONENT, 'ltitypeid'));
    }

    /**
     * Other registered tools survive the deletion.
     */
    public function test_execute_keeps_the_other_registered_tools(): void {
        $this->setAdminUser();
        $first = upsertlti::execute(0, 'LearnWise', 'assistant123');
        $second = upsertlti::execute(0, 'LearnWise Two', 'assistant456');

        deletelti::execute($first->id);

        $this->assertSame((string) $second->id, get_config(constants::COMPONENT, 'ltitypeids'));
    }

    /**
     * Deleting something that is not there is reported rather than thrown.
     */
    public function test_execute_reports_an_unknown_tool(): void {
        $this->setAdminUser();

        $this->expectException(invalid_parameter_exception::class);
        deletelti::execute(-1);
    }

    /**
     * Only a site administrator may remove the tool.
     */
    public function test_execute_requires_site_configuration_rights(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(required_capability_exception::class);
        deletelti::execute(1);
    }
}
