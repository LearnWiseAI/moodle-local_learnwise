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
final class webservice_permissions_test extends advanced_testcase {
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
     * New installations grant read access without course, grading or token management powers.
     */
    public function test_service_role_is_read_only(): void {
        $token = $this->setup_service();
        $context = context_system::instance();
        foreach (
            ['moodle/course:update', 'mod/assign:manageallocations',
                'moodle/webservice:createtoken'] as $capability
        ) {
            $this->assertFalse(has_capability($capability, $context, $token->userid), $capability);
        }
        foreach (['moodle/course:viewhiddenactivities', 'mod/assign:viewgrades', 'webservice/rest:use'] as $capability) {
            $this->assertTrue(has_capability($capability, $context, $token->userid), $capability);
        }
    }

    /**
     * Upgrades revoke old grants, preserve unrelated roles and leave existing credentials intact.
     */
    public function test_upgrade_restricts_existing_role_without_rotating_token(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/learnwise/db/upgrade.php');
        $token = $this->setup_service();
        $role = util::get_or_create_role();
        foreach (
            ['moodle/course:update', 'mod/assign:grade', 'mod/assign:manageallocations',
                'moodle/webservice:createtoken'] as $capability
        ) {
            assign_capability($capability, CAP_ALLOW, $role->id, SYSCONTEXTID, true);
        }
        unassign_capability('mod/assign:viewgrades', $role->id);
        unassign_capability('moodle/course:viewhiddenactivities', $role->id);
        $otherrole = $this->getDataGenerator()->create_role();
        assign_capability('mod/assign:grade', CAP_ALLOW, $otherrole, SYSCONTEXTID, true);
        $client = util::get_or_generate_client();
        $authorised = $DB->get_records('external_services_users');
        set_config('version', 2026091100, 'local_learnwise');
        set_config('upgraderunning', time() + 3600);
        $this->assertTrue(xmldb_local_learnwise_upgrade(2026091100));
        local_learnwise_upgrade_restrict_service_role();
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertEquals($token, $DB->get_record('external_tokens', ['id' => $token->id]));
        $this->assertEquals($client, util::get_or_generate_client());
        $this->assertEquals($authorised, $DB->get_records('external_services_users'));
        $this->assertTrue($DB->record_exists('role_capabilities', ['roleid' => $otherrole, 'capability' => 'mod/assign:grade']));
        $this->test_service_role_is_read_only();
        $result = $this->execute_request($this->service_server($token, ['plugininfo']));
        $this->assertIsArray($result);
    }

    /**
     * Removing course editing preserves raw restriction metadata and admin course/enrolment reads.
     */
    public function test_service_preserves_course_reads_and_availability(): void {
        $token = $this->setup_service();
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $availability = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => time() + 86400]],
            'showc' => [true]]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'availability' => $availability]);
        $contents = $this->core_service_request($token, 'core_course_get_contents', ['courseid' => $course->id]);
        $found = false;
        foreach ($contents as $section) {
            foreach ($section['modules'] as $module) {
                if ($module['id'] == $page->cmid) {
                    $this->assertSame($availability, $module['availability']);
                    $found = true;
                }
            }
        }
        $this->assertTrue($found);
        $metadata = $this->core_service_request($token, 'core_course_get_courses', ['options' => ['ids' => [$course->id]]]);
        $this->assertEquals($course->fullname, reset($metadata)['fullname']);
        $catalog = $this->core_service_request($token, 'core_course_search_courses', [
            'criterianame' => 'search', 'criteriavalue' => $course->fullname,
        ]);
        $this->assertEquals($course->id, reset($catalog['courses'])['id']);
        $enrolments = $this->core_service_request($token, 'core_enrol_get_users_courses', ['userid' => $student->id]);
        $this->assertEquals($course->id, reset($enrolments)['id']);
    }

    /**
     * Submission attachments remain readable with marking workflow enabled and without grading power.
     */
    public function test_service_reads_submission_files_and_assignment_listing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $token = $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'markingworkflow' => 1, 'markingallocation' => 1,
            'assignsubmission_file_enabled' => 1, 'assignsubmission_file_maxfiles' => 1,
            'assignsubmission_file_maxsizebytes' => 0,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $context = \context_module::instance($assignment->cmid);
        $assign = new \assign($context, null, null);
        $submission = $assign->get_user_submission($student->id, true);
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);
        $DB->insert_record('assignsubmission_file', (object) [
            'assignment' => $assignment->id, 'submission' => $submission->id, 'numfiles' => 1,
        ]);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
            'itemid' => $submission->id, 'filepath' => '/', 'filename' => 'submission.txt', 'userid' => $student->id,
        ], 'Student work');
        $result = $this->core_service_request($token, 'mod_assign_get_submissions', ['assignmentids' => [$assignment->id]]);
        $this->assertEmpty($result['warnings']);
        $this->assertEquals($student->id, $result['assignments'][0]['submissions'][0]['userid']);
        $this->assertStringContainsString($file->get_filename(), json_encode($result));
        $this->assertTrue($assign->can_view_submission($student->id));
        $this->assertTrue($assign->can_grade());
        $result = $this->execute_request($this->service_server($token, ['courses', $course->id, 'assignments']));
        $this->assertNotEmpty($result);
    }

    /**
     * Books still expose chapter content through the Bearer-authenticated plugin API.
     */
    public function test_service_reads_book_chapters(): void {
        global $DB;
        $token = $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $this->getDataGenerator()->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $book->id, 'content' => 'Chapter for ingestion',
        ]);
        $result = $this->execute_request($this->service_server($token, ['courses', $course->id, 'books', $book->cmid]));
        $this->assertStringContainsString('Chapter for ingestion', json_encode($result));
        $this->assertEquals(1, $DB->get_field('external_services', 'downloadfiles', ['id' => $token->externalserviceid]));
        $this->assertEquals(0, $DB->get_field('external_services', 'uploadfiles', ['id' => $token->externalserviceid]));
    }

    /**
     * Allowlisted core reads also work through the plugin WS proxy.
     */
    public function test_service_proxy_reads_still_work(): void {
        $token = $this->setup_service();
        $result = $this->execute_request($this->service_server($token, ['ws', 'core_webservice', 'get_site_info']));
        $this->assertEquals($token->userid, $result['userid']);
    }

    /**
     * Core REST cannot grade with the reduced role even if a site adds a write function to the service.
     */
    public function test_service_cannot_grade_through_core_rest(): void {
        global $DB;
        $token = $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $token->externalserviceid, 'functionname' => 'mod_assign_save_grade',
        ]);
        $returns = $this->core_service_request($token, 'mod_assign_save_grade', [
            'assignmentid' => $assignment->id, 'userid' => $student->id, 'grade' => 75,
            'attemptnumber' => -1, 'addattempt' => 0, 'workflowstate' => '', 'applytoall' => 0,
        ]);
        $this->assertNull($returns);
    }

    /**
     * Even additional site grants cannot turn the service token into an AI Ops write credential.
     *
     * @dataProvider service_denied_route_provider
     * @param array $route Plugin route.
     */
    public function test_service_rejects_unapproved_operations(array $route): void {
        global $DB;
        $token = $this->setup_service();
        $role = util::get_or_create_role();
        assign_capability('mod/assign:grade', CAP_ALLOW, $role->id, SYSCONTEXTID, true);
        accesslib_clear_all_caches_for_unit_testing();
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $token->externalserviceid, 'functionname' => 'mod_assign_save_grade',
        ]);
        $server = $this->service_server($token, $route);
        $this->stage($server, 'parse_request');
        $this->stage($server, 'authenticate_user');
        $this->expectException(\webservice_access_exception::class);
        $this->expectExceptionMessage('only permits integration read operations');
        $this->stage($server, 'load_function_info');
    }

    /**
     * Writes and unrelated reads must be rejected regardless of HTTP verb.
     *
     * @return array
     */
    public static function service_denied_route_provider(): array {
        return [
            [['ws', 'mod_assign', 'save_grade']],
            [['courses', 1, 'assignments', 1, 'submissions', 1, 'grade']],
            [['ws', 'core_user', 'get_users']],
            [['users', 1]],
        ];
    }

    /**
     * Disabled services and removed authorised users cannot authenticate through the Bearer fallback.
     *
     * @dataProvider disabled_service_provider
     * @param bool $disable Whether to disable the service or remove its authorised user.
     */
    public function test_service_authorisation_is_enforced(bool $disable): void {
        global $DB;
        $token = $this->setup_service();
        if ($disable) {
            $DB->set_field('external_services', 'enabled', 0, ['id' => $token->externalserviceid]);
        } else {
            $DB->delete_records('external_services_users', ['externalserviceid' => $token->externalserviceid]);
        }
        $server = $this->service_server($token, ['plugininfo']);
        $this->expectException(\webservice_access_exception::class);
        $this->stage($server, 'authenticate_user');
    }

    /**
     * Service restrictions to exercise.
     *
     * @return array
     */
    public static function disabled_service_provider(): array {
        return [[true], [false]];
    }

    /**
     * Construct a request using the permanent token through the real Bearer fallback.
     *
     * @param \stdClass $token Permanent token.
     * @param array $route Route segments.
     * @return api_server
     */
    protected function service_server(\stdClass $token, array $route): api_server {
        global $ME;
        $ME = '/local/learnwise/api/r.php';
        set_config('liveapi', 1, 'local_learnwise');
        set_config('aiops', 1, 'local_learnwise');
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $server = new api_server();
        $server->urlparts = array_map('strval', $route);
        $server->request = new Request([], [], [], [], [], [], null, ['Authorization' => 'Bearer ' . $token->token]);
        return $server;
    }

    /**
     * Execute a core REST read with the same permanent credential.
     *
     * @param \stdClass $token Permanent token.
     * @param string $function External function name.
     * @param array $params Request parameters.
     * @return mixed
     */
    protected function core_service_request(\stdClass $token, string $function, array $params) {
        $_POST = [];
        $_GET = $params + ['wstoken' => $token->token, 'wsfunction' => $function, 'moodlewsrestformat' => 'json'];
        return $this->execute_request(new \webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN));
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
