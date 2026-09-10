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

use context_system;
use local_learnwise\form\webservicesetup;
use local_learnwise\local\OAuth2\Request;

/**
 * Verify setup and API access without a site-wide REST permission grant.
 *
 * @runTestsInSeparateProcesses
 * @covers \local_learnwise\form\webservicesetup
 * @covers \local_learnwise\api_server
 * @package local_learnwise
 * @copyright 2026 LearnWise <help@learnwise.ai>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webservice_permissions_test extends \advanced_testcase {
    /**
     * Start without the default role's REST permission.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->libdir . '/externallib.php');
        require_once($CFG->dirroot . '/webservice/rest/locallib.php');
        unassign_capability('webservice/rest:use', $CFG->defaultuserroleid, context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setAdminUser();
    }

    /**
     * Exercise the real setup form and return its service token.
     *
     * @return \stdClass
     */
    protected function setup_service(): \stdClass {
        $this->setAdminUser();
        $form = new webservicesetup();
        $this->assertTrue($form->update_from_formdata((object) ['setupwebservicesetup' => 1]));
        return util::get_or_generate_token_for_user(constants::COMPONENT, false);
    }

    /**
     * Invoke the real server pipeline without run(), which sends output and exits PHP.
     *
     * @param \webservice_base_server $server Server under test.
     * @param string $method Protected pipeline stage.
     * @return mixed
     */
    protected function stage(\webservice_base_server $server, string $method) {
        $reflection = new \ReflectionMethod($server, $method);
        $reflection->setAccessible(true);
        return $reflection->invoke($server);
    }

    /**
     * Parse, authenticate and execute a real request, retaining its raw result.
     *
     * @param \webservice_base_server $server Server under test.
     * @return mixed
     */
    protected function execute_request(\webservice_base_server $server) {
        foreach (['parse_request', 'authenticate_user', 'load_function_info', 'execute'] as $stage) {
            $this->stage($server, $stage);
        }
        $property = new \ReflectionProperty($server, 'returns');
        $property->setAccessible(true);
        return $property->getValue($server);
    }

    /**
     * The token-created event includes the complete database row on every Moodle version.
     */
    public function test_token_created_event_has_complete_snapshot(): void {
        global $DB;
        $sink = $this->redirectEvents();
        $token = $this->setup_service();
        $events = array_values(array_filter($sink->get_events(), function ($event) {
            return $event instanceof \core\event\webservice_token_created;
        }));
        $this->assertCount(1, $events);
        $this->assertEquals(
            $DB->get_record('external_tokens', ['id' => $token->id], '*', MUST_EXIST),
            $events[0]->get_record_snapshot('external_tokens', $token->id)
        );
        $sink->close();
    }

    /**
     * Setup grants REST only to the dedicated service identity and preserves existing tokens on rerun.
     */
    public function test_setup_keeps_default_role_unchanged_and_service_rest_works(): void {
        global $CFG, $DB, $USER;
        $token = $this->setup_service();
        $this->assertFalse($DB->record_exists('role_capabilities', [
            'roleid' => $CFG->defaultuserroleid, 'contextid' => context_system::instance()->id,
            'capability' => 'webservice/rest:use',
        ]));
        $this->assertTrue(has_capability('webservice/rest:use', context_system::instance(), $token->userid));
        $this->assertSame($token->token, $this->setup_service()->token);
        $_POST = [];
        $_GET = ['wstoken' => $token->token, 'wsfunction' => 'core_webservice_get_site_info', 'moodlewsrestformat' => 'json'];
        $result = $this->execute_request(new \webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN));
        $this->assertEquals($token->userid, $USER->id);
        $this->assertEquals($token->userid, $result['userid']);
    }

    /**
     * An existing site decision to grant REST permission is left intact.
     */
    public function test_setup_preserves_an_existing_admin_grant(): void {
        global $CFG, $DB;
        assign_capability('webservice/rest:use', CAP_ALLOW, $CFG->defaultuserroleid, context_system::instance()->id, true);
        $before = $DB->get_record('role_capabilities', [
            'roleid' => $CFG->defaultuserroleid, 'contextid' => context_system::instance()->id,
            'capability' => 'webservice/rest:use',
        ]);
        $this->setup_service();
        $this->assertEquals($before, $DB->get_record('role_capabilities', ['id' => $before->id]));
    }

    /**
     * A non-service user still needs explicit permission even with a valid core REST token.
     */
    public function test_core_rest_rejects_a_user_without_protocol_permission(): void {
        global $DB;
        $token = clone $this->setup_service();
        $user = $this->getDataGenerator()->create_user();
        unset($token->id);
        $token->userid = $user->id;
        $token->token = 'ordinary-user-token';
        $DB->insert_record('external_tokens', $token);
        $DB->insert_record('external_services_users', (object) [
            'externalserviceid' => $token->externalserviceid, 'userid' => $user->id, 'timecreated' => time(),
        ]);
        $this->assertFalse(has_capability('webservice/rest:use', context_system::instance(), $user->id));
        $_POST = [];
        $_GET = ['wstoken' => $token->token, 'wsfunction' => 'core_webservice_get_site_info'];
        $server = new \webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);
        $this->stage($server, 'parse_request');
        $this->expectException(\webservice_access_exception::class);
        $this->expectExceptionMessage('webservice/rest:use');
        $this->stage($server, 'authenticate_user');
    }

    /**
     * Student/teacher Live API and AI Ops reads authenticate and execute without core REST permission.
     *
     * @dataProvider oauth_read_provider
     * @param string $role Enrolled role.
     * @param bool $proxy Whether to use AI Ops.
     */
    public function test_oauth_reads_work_without_rest_permission(string $role, bool $proxy): void {
        global $USER;
        $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, $role);
        $this->assertFalse(has_capability('webservice/rest:use', context_system::instance(), $user->id));
        $server = $this->oauth_server($user->id);
        if ($proxy) {
            $server->urlparts = ['ws', 'core_enrol', 'get_users_courses'];
            $_GET = ['userid' => $user->id];
        } else {
            $server->urlparts = ['me'];
        }
        $result = $this->execute_request($server);
        $this->assertEquals($user->id, $USER->id);
        if ($proxy) {
            $this->assertEquals($course->id, reset($result)['id']);
        } else {
            $this->assertEquals($user->id, $result['id']);
        }
        // The file proxy uses this same authentication entry point.
        $this->setGuestUser();
        $server->authenticate();
        restore_exception_handler();
        $this->assertEquals($user->id, $USER->id);
    }

    /**
     * Roles and API paths to verify.
     *
     * @return array
     */
    public static function oauth_read_provider(): array {
        return [['student', false], ['editingteacher', false], ['student', true], ['editingteacher', true]];
    }

    /**
     * AI Ops writes preserve the target function's teacher/student authorization checks.
     *
     * @dataProvider oauth_write_provider
     * @param string $role Enrolled role.
     */
    public function test_aiops_write_uses_assignment_capabilities(string $role): void {
        global $DB;
        $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $user = $this->getDataGenerator()->create_and_enrol($course, $role);
        $this->assertFalse(has_capability('webservice/rest:use', context_system::instance(), $user->id));
        $server = $this->oauth_server($user->id);
        $server->urlparts = ['ws', 'mod_assign', 'save_grade'];
        $_GET = ['assignmentid' => $assignment->id, 'userid' => $student->id, 'grade' => 75,
            'attemptnumber' => -1, 'addattempt' => 0, 'workflowstate' => '', 'applytoall' => 0];
        if ($role === 'student') {
            $this->expectException(\required_capability_exception::class);
        }
        $this->execute_request($server);
        $this->assertEquals(75, $DB->get_field('assign_grades', 'grade', [
            'assignment' => $assignment->id, 'userid' => $student->id,
        ]));
    }

    /**
     * Roles exercising allowed and forbidden writes.
     *
     * @return array
     */
    public static function oauth_write_provider(): array {
        return [['editingteacher'], ['student']];
    }

    /**
     * Issue a real OAuth bearer token for the plugin request pipeline.
     *
     * @param int $userid Authenticated user.
     * @return api_server
     */
    protected function oauth_server(int $userid): api_server {
        set_config('liveapi', 1, 'local_learnwise');
        set_config('aiops', 1, 'local_learnwise');
        $storage = new storage();
        $client = util::get_or_generate_client();
        $storage->setAccessToken('user-oauth-token', $client->uniqid, $userid, time() + 3600);
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $server = new api_server();
        $server->request = new Request([], [], [], [], [], [], null, ['Authorization' => 'Bearer user-oauth-token']);
        return $server;
    }
}
