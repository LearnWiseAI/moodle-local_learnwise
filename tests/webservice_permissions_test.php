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
use local_learnwise\external\baseapi;
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
     * New installations grant reads and assignment grading, without course or token management powers.
     */
    public function test_service_role_grants_reads_and_grading(): void {
        global $DB;
        $token = $this->setup_service();
        $context = context_system::instance();
        foreach (
            ['moodle/course:update', 'mod/assign:manageallocations', 'moodle/webservice:createtoken'] as $capability
        ) {
            $this->assertFalse(has_capability($capability, $context, $token->userid), $capability);
        }
        foreach (
            ['moodle/course:viewhiddenactivities', 'mod/assign:viewgrades', 'mod/assign:grade',
                'mod/folder:view', 'mod/url:view', 'webservice/rest:use'] as $capability
        ) {
            $this->assertTrue(has_capability($capability, $context, $token->userid), $capability);
        }
        // The authenticated user role also allows these by default, so check the integration role grants them itself.
        $role = util::get_or_create_role();
        foreach (['mod/folder:view', 'mod/url:view'] as $capability) {
            $this->assertEquals(CAP_ALLOW, $DB->get_field('role_capabilities', 'permission', [
                'roleid' => $role->id, 'capability' => $capability, 'contextid' => SYSCONTEXTID,
            ]), $capability);
        }
    }

    /**
     * Upgrading from 1.4.9a revokes the old write grants, adds the new ones, preserves unrelated roles and
     * leaves existing credentials intact.
     */
    public function test_upgrade_from_release_updates_role_without_rotating_token(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/learnwise/db/upgrade.php');
        $token = $this->setup_service();
        // Put the integration role back to what 1.4.9a granted.
        $role = util::get_or_create_role();
        foreach (
            ['moodle/course:update', 'mod/assign:grade', 'mod/assign:manageallocations',
                'moodle/webservice:createtoken'] as $capability
        ) {
            assign_capability($capability, CAP_ALLOW, $role->id, SYSCONTEXTID, true);
        }
        foreach (
            ['mod/assign:viewgrades', 'moodle/course:viewhiddenactivities', 'mod/folder:view', 'mod/url:view'] as $capability
        ) {
            unassign_capability($capability, $role->id);
        }
        $otherrole = $this->getDataGenerator()->create_role();
        assign_capability('moodle/course:update', CAP_ALLOW, $otherrole, SYSCONTEXTID, true);
        $client = util::get_or_generate_client();
        $authorised = $DB->get_records('external_services_users');
        set_config('version', 2026091500, 'local_learnwise');
        set_config('upgraderunning', time() + 3600);
        $this->assertTrue(xmldb_local_learnwise_upgrade(2026091500));
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertEquals($token, $DB->get_record('external_tokens', ['id' => $token->id]));
        $this->assertEquals($client, util::get_or_generate_client());
        $this->assertEquals($authorised, $DB->get_records('external_services_users'));
        $this->assertTrue($DB->record_exists('role_capabilities', [
            'roleid' => $otherrole, 'capability' => 'moodle/course:update',
        ]));
        $this->test_service_role_grants_reads_and_grading();
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
     * Submission attachments remain readable with marking workflow enabled.
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
     * Course content routes used by ingestion stay readable with the permanent token.
     *
     * @dataProvider service_content_route_provider
     * @param array $route Plugin route, with placeholders for the generated ids.
     * @param bool $nonempty Whether the route must return data for the generated course.
     */
    public function test_service_reads_course_content(array $route, bool $nonempty): void {
        $token = $this->setup_service();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 1]);
        $user = $generator->create_and_enrol($course, 'student');
        $forum = $generator->create_module('forum', ['course' => $course->id]);
        $discussion = $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
        ]);
        $quiz = $generator->create_module('quiz', ['course' => $course->id]);
        $scorm = $generator->create_module('scorm', ['course' => $course->id]);
        $page = $generator->create_module('page', ['course' => $course->id]);
        $section = get_fast_modinfo($course)->get_section_info(1);

        $ids = [
            '{course}' => $course->id, '{section}' => $section->id, '{forum}' => $forum->cmid,
            '{discussion}' => $discussion->id, '{quiz}' => $quiz->cmid, '{scorm}' => $scorm->cmid,
            '{module}' => $page->cmid,
        ];
        $route = array_map(function ($segment) use ($ids) {
            return $ids[$segment] ?? $segment;
        }, $route);

        $result = $this->execute_request($this->service_server($token, $route));
        $this->assertIsArray($result);
        if ($nonempty) {
            $this->assertNotEmpty($result);
        }
    }

    /**
     * Read routes the permanent token must keep.
     *
     * @return array
     */
    public static function service_content_route_provider(): array {
        return [
            'sections' => [['courses', '{course}', 'sections'], true],
            'section' => [['courses', '{course}', 'sections', '{section}'], true],
            'course modules' => [['courses', '{course}', 'modules'], true],
            'course module' => [['courses', '{course}', 'modules', '{module}'], true],
            'module' => [['modules', '{module}'], true],
            'forums' => [['courses', '{course}', 'forums'], true],
            'forum' => [['courses', '{course}', 'forums', '{forum}'], true],
            'discussions' => [['courses', '{course}', 'forums', '{forum}', 'discussions'], true],
            'discussion' => [['courses', '{course}', 'forums', '{forum}', 'discussions', '{discussion}'], true],
            'single discussion' => [['discussions', '{discussion}'], true],
            'quizzes' => [['courses', '{course}', 'quizzes'], true],
            'quiz' => [['courses', '{course}', 'quizzes', '{quiz}'], true],
            'scorms' => [['courses', '{course}', 'scorms'], true],
            'scorm' => [['courses', '{course}', 'scorms', '{scorm}'], true],
            'course calendar' => [['courses', '{course}', 'calendar'], false],
            'calendar' => [['calendar'], false],
        ];
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
     * Automated assessment finds the submitted work, reads it and the student, then saves the grade and
     * feedback, all with the service token.
     */
    public function test_service_supports_automated_assessment(): void {
        global $DB;
        $token = $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'grade' => 100, 'assignfeedback_comments_enabled' => 1,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->submit_assignment($assignment, $student);
        $base = ['courses', $course->id, 'assignments', $assignment->cmid, 'submissions'];

        // The scheduler picks up submissions whose workflow_state is "submitted".
        $list = $this->execute_request($this->service_server($token, $base));
        $this->assertEquals($student->id, ((array) reset($list))['user_id']);
        $this->assertSame('submitted', ((array) reset($list))['workflow_state']);
        $single = $this->execute_request($this->service_server($token, array_merge($base, [$student->id])));
        $this->assertEquals($student->id, ((array) $single)['user_id']);
        $user = $this->execute_request($this->service_server($token, ['users', $student->id]));
        $this->assertEquals($student->id, ((array) $user)['id']);

        $result = $this->execute_request($this->service_server(
            $token,
            array_merge($base, [$student->id, 'grade']),
            $this->grade_body($course, $assignment, $student, ['submission_grade' => 75.0, 'general_feedback' => 'Well argued'])
        ));
        $this->assertTrue(((array) $result)['success']);
        $grade = $DB->get_record('assign_grades', ['assignment' => $assignment->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertEqualsWithDelta(75.0, $grade->grade, 0.001);
        $this->assertEquals($token->userid, $grade->grader);
        $feedback = $DB->get_record('assignfeedback_comments', ['grade' => $grade->id], '*', MUST_EXIST);
        $this->assertStringContainsString('Well argued', $feedback->commenttext);

        // Once graded, the scheduler no longer sees it as waiting.
        $list = $this->execute_request($this->service_server($token, $base));
        $this->assertSame('graded', ((array) reset($list))['workflow_state']);
    }

    /**
     * The service token sees the rubric or marking guide on the assignment and can grade against it.
     *
     * @dataProvider advanced_grading_method_provider
     * @param string $method Grading method.
     */
    public function test_service_reads_and_grades_advanced_grading(string $method): void {
        global $DB;
        $token = $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->submit_assignment($assignment, $student);
        $context = \context_module::instance($assignment->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('gradingform_' . $method);
        $criteria = $method === 'rubric' ? ['LW criterion' => ['Poor' => 0, 'Good' => 10]] : [
            'LW criterion' => ['description' => 'LW criterion', 'descriptionmarkers' => 'LW markers', 'maxscore' => 10],
        ];
        $this->setAdminUser();
        $definition = $generator->create_instance($context, 'mod_assign', 'submissions', 'LW form', '', $criteria)
            ->get_definition();

        // The assignment route only includes the criteria for callers holding mod/assign:grade.
        $info = (array) $this->execute_request(
            $this->service_server($token, ['courses', $course->id, 'assignments', $assignment->cmid])
        );
        $this->assertArrayHasKey($method, $info);
        $this->assertStringContainsString('LW criterion', json_encode($info[$method]));

        $assessment = ['submission_grade' => 0.0, 'general_feedback' => 'Criteria applied'];
        if ($method === 'rubric') {
            $criterion = reset($definition->rubric_criteria);
            $level = end($criterion['levels']);
            $assessment['rubric_assessments']['rubric_feedback_array'] = [[
                'rubric_section_id' => $criterion['id'], 'graded_lms_rubric_rating_id' => $level['id'], 'content' => 'Good',
            ]];
        } else {
            $criterion = reset($definition->guide_criteria);
            $assessment['rubric_assessments']['guide_feedback_array'] = [[
                'rubric_section_id' => $criterion['id'], 'graded_score' => 10, 'content' => 'Good',
            ]];
        }
        $result = $this->execute_request($this->service_server(
            $token,
            ['courses', $course->id, 'assignments', $assignment->cmid, 'submissions', $student->id, 'grade'],
            $this->grade_body($course, $assignment, $student, $assessment)
        ));
        $this->assertTrue(((array) $result)['success']);
        $grade = $DB->get_record('assign_grades', ['assignment' => $assignment->id, 'userid' => $student->id], '*', MUST_EXIST);
        $this->assertEquals(100, $grade->grade);
        $instance = $DB->get_record('grading_instances', ['itemid' => $grade->id, 'definitionid' => $definition->id]);
        $this->assertTrue($DB->record_exists('gradingform_' . $method . '_fillings', ['instanceid' => $instance->id]));
    }

    /**
     * Grading methods automated assessment supports.
     *
     * @return array
     */
    public static function advanced_grading_method_provider(): array {
        return [['rubric'], ['guide']];
    }

    /**
     * Without feedback comments, the service token posts general feedback as a tracked submission comment
     * and updates that same comment when it grades again.
     */
    public function test_service_posts_general_feedback_as_submission_comment(): void {
        global $DB;
        $token = $this->setup_service();
        $course = $this->getDataGenerator()->create_course();
        $assignment = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'grade' => 100, 'assignfeedback_comments_enabled' => 0,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $submission = $this->submit_assignment($assignment, $student);
        $route = ['courses', $course->id, 'assignments', $assignment->cmid, 'submissions', $student->id, 'grade'];
        $conditions = [
            'userid' => $token->userid, 'contextid' => \context_module::instance($assignment->cmid)->id,
            'commentarea' => 'submission_comments', 'itemid' => $submission->id,
        ];

        $this->execute_request($this->service_server($token, $route, $this->grade_body($course, $assignment, $student, [
            'submission_grade' => 60.0, 'general_feedback' => 'First pass',
        ])));
        $comment = $DB->get_record('comments', $conditions, '*', MUST_EXIST);
        $this->assertStringContainsString('First pass', $comment->content);
        $this->assertTrue($DB->record_exists('local_learnwise_comnt_tracks', ['commentid' => $comment->id]));

        $this->execute_request($this->service_server($token, $route, $this->grade_body($course, $assignment, $student, [
            'submission_grade' => 70.0, 'general_feedback' => 'Second pass',
        ])));
        $comments = $DB->get_records('comments', $conditions);
        $this->assertCount(1, $comments);
        $this->assertStringContainsString('Second pass', reset($comments)->content);
    }

    /**
     * Every content type the knowledge loader ingests comes back from the core functions it calls.
     */
    public function test_service_reads_every_content_type_through_core_rest(): void {
        $token = $this->setup_service();
        [$course, $modules] = $this->create_course_with_every_content_type();
        $json = function ($result): string {
            return json_encode($result, JSON_UNESCAPED_SLASHES);
        };

        $contents = $this->core_service_request($token, 'core_course_get_contents', ['courseid' => $course->id]);
        $cmids = [];
        foreach ($contents as $section) {
            foreach ($section['modules'] as $module) {
                $cmids[$module['modname']] = $module['id'];
            }
        }
        foreach ($modules as $modname => $module) {
            $this->assertEquals($module->cmid, $cmids[$modname] ?? null, "core_course_get_contents lists the {$modname}");
        }
        $resourcefiles = get_file_storage()->get_area_files(
            \context_module::instance($modules['resource']->cmid)->id,
            'mod_resource',
            'content',
            0,
            'id',
            false
        );
        $this->assertStringContainsString(reset($resourcefiles)->get_filename(), $json($contents));
        $this->assertStringContainsString('lw-folder.txt', $json($contents));
        $this->assertStringContainsString('https://example.com/lw-link', $json($contents));
        $this->assertStringContainsString('LW label text', $json($contents));

        $byid = ['courseids' => [$course->id]];
        $this->assertStringContainsString(
            'LW page body',
            $json($this->core_service_request($token, 'mod_page_get_pages_by_courses', $byid))
        );
        $this->assertStringContainsString(
            'LW resource',
            $json($this->core_service_request($token, 'mod_resource_get_resources_by_courses', $byid))
        );
        $this->assertStringContainsString(
            'LW assignment',
            // The service account is not enrolled, so this needs includenotenrolledcourses, as the loader passes.
            $json($this->core_service_request($token, 'mod_assign_get_assignments', $byid + ['includenotenrolledcourses' => 1]))
        );
        $this->assertStringContainsString(
            'LW forum',
            $json($this->core_service_request($token, 'mod_forum_get_forums_by_courses', $byid))
        );
        $this->assertStringContainsString(
            'LW discussion',
            $json($this->core_service_request($token, 'mod_forum_get_forum_discussions', ['forumid' => $modules['forum']->id]))
        );
        $scorms = $json($this->core_service_request($token, 'mod_scorm_get_scorms_by_courses', $byid));
        $this->assertStringContainsString('LW scorm', $scorms);
        $this->assertStringContainsString('webservice/pluginfile.php', $scorms);
        $h5p = $json($this->core_service_request($token, 'mod_h5pactivity_get_h5pactivities_by_courses', $byid));
        $this->assertStringContainsString('LW h5p', $h5p);
        // The package fixture differs between Moodle versions, so check for its download URL, not its name.
        $this->assertStringContainsString('webservice/pluginfile.php', $h5p);
        $this->assertStringContainsString('/mod_h5pactivity/package/', $h5p);
        $this->assertStringContainsString(
            'LW book',
            $json($this->core_service_request($token, 'local_learnwise_get_books', ['courseid' => $course->id]))
        );
        $cm = $this->core_service_request($token, 'core_course_get_course_module', ['cmid' => $modules['page']->cmid]);
        $this->assertEquals($modules['page']->cmid, ((array) $cm['cm'])['id']);
        $this->assertStringContainsString('lw-folder.txt', $json($this->core_service_request($token, 'core_files_get_files', [
            'contextid' => \context_module::instance($modules['folder']->cmid)->id, 'component' => 'mod_folder',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => '',
        ])));
    }

    /**
     * With the authenticated user role stripped of module access, the service account still passes the checks
     * webservice/pluginfile.php makes before serving a module's files, on hidden activities too.
     *
     * @dataProvider module_file_access_provider
     * @param string $modname Module type.
     * @param string|null $capability The view capability the module's file serving checks, if any.
     */
    public function test_service_reaches_module_files_on_a_locked_down_site(string $modname, ?string $capability): void {
        global $CFG, $DB, $PAGE;
        $token = $this->setup_service();
        foreach (array_filter(array_column(self::module_file_access_provider(), 1)) as $viewcapability) {
            unassign_capability($viewcapability, $CFG->defaultuserroleid);
        }
        accesslib_clear_all_caches_for_unit_testing();
        [$course, $modules] = $this->create_course_with_every_content_type(true);
        $cm = get_coursemodule_from_id($modname, $modules[$modname]->cmid, 0, false, MUST_EXIST);

        $this->setUser($DB->get_record('user', ['id' => $token->userid], '*', MUST_EXIST));
        // Building the setup form already set up page output; reset it as external_api::validate_context() does.
        $PAGE->reset_theme_and_output();
        require_login($course, true, $cm, false, true);
        if ($capability) {
            $this->assertTrue(has_capability($capability, \context_module::instance($cm->id)), $capability);
        }
    }

    /**
     * Module types the loader ingests and the capability their file serving checks after login.
     *
     * @return array
     */
    public static function module_file_access_provider(): array {
        return [
            'page' => ['page', 'mod/page:view'],
            'resource' => ['resource', 'mod/resource:view'],
            'folder' => ['folder', 'mod/folder:view'],
            'url' => ['url', 'mod/url:view'],
            'label' => ['label', null],
            'book' => ['book', 'mod/book:read'],
            'assignment' => ['assign', 'mod/assign:view'],
            'forum' => ['forum', 'mod/forum:viewdiscussion'],
            'quiz' => ['quiz', 'mod/quiz:view'],
            'scorm' => ['scorm', null],
            'h5p' => ['h5pactivity', 'mod/h5pactivity:view'],
        ];
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
            [['ws', 'core_user', 'get_users']],
            [['me']],
            [['notifications']],
            [['courses', 1, 'quizzes', 1, 'attempts']],
            [['courses', 1, 'quizzes', 1, 'attempts', 1, 'review']],
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
     * @param array $body Request body; a non-empty body makes it a POST.
     * @return api_server
     */
    protected function service_server(\stdClass $token, array $route, array $body = []): api_server {
        global $ME;
        $ME = '/local/learnwise/api/r.php';
        set_config('liveapi', 1, 'local_learnwise');
        set_config('aiops', 1, 'local_learnwise');
        // Routes record single-operation ids in static state, so start every request from a clean slate.
        baseapi::$ids = [];
        baseapi::$my = null;
        $_POST = [];
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = $body ? 'POST' : 'GET';
        $_SERVER['SERVER_SOFTWARE'] = 'Apache';
        $server = new api_server();
        $server->urlparts = array_map('strval', $route);
        $server->request = new Request([], $body, [], [], [], [], null, ['Authorization' => 'Bearer ' . $token->token]);
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
        global $ME;
        // Plugin functions such as local_learnwise_get_books shape their output for the native endpoint.
        $ME = '/webservice/rest/server.php';
        $_POST = [];
        $_GET = $params + ['wstoken' => $token->token, 'wsfunction' => $function, 'moodlewsrestformat' => 'json'];
        return $this->execute_request(new \webservice_rest_server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN));
    }

    /**
     * Create a submitted submission for a student.
     *
     * @param \stdClass $assignment Assignment module record.
     * @param \stdClass $student Student.
     * @return \stdClass The submission.
     */
    protected function submit_assignment(\stdClass $assignment, \stdClass $student): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assign = new \assign(\context_module::instance($assignment->cmid), null, null);
        $submission = $assign->get_user_submission($student->id, true);
        $submission->status = ASSIGN_SUBMISSION_STATUS_SUBMITTED;
        $DB->update_record('assign_submission', $submission);
        return $submission;
    }

    /**
     * The request body automated assessment posts to the grade route.
     *
     * @param \stdClass $course Course.
     * @param \stdClass $assignment Assignment module record.
     * @param \stdClass $student Student being graded.
     * @param array $assessment The rubric_assessment payload.
     * @return array
     */
    protected function grade_body(\stdClass $course, \stdClass $assignment, \stdClass $student, array $assessment): array {
        return [
            'course_id' => $course->id, 'assignment_id' => $assignment->cmid, 'user_id' => $student->id,
            'rubric_assessment' => $assessment,
        ];
    }

    /**
     * A course holding one of every module type the knowledge loader ingests, with content in each.
     *
     * @param bool $hidden Whether to hide every activity from students.
     * @return array The course and the module records keyed by module name.
     */
    protected function create_course_with_every_content_type(bool $hidden = false): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $author = $generator->create_and_enrol($course, 'editingteacher');
        $records = [
            'page' => ['name' => 'LW page', 'content' => 'LW page body'],
            'resource' => ['name' => 'LW resource'],
            'folder' => ['name' => 'LW folder'],
            'url' => ['name' => 'LW url', 'externalurl' => 'https://example.com/lw-link'],
            'label' => ['intro' => 'LW label text'],
            'book' => ['name' => 'LW book'],
            'assign' => ['name' => 'LW assignment'],
            'forum' => ['name' => 'LW forum'],
            'quiz' => ['name' => 'LW quiz'],
            'scorm' => ['name' => 'LW scorm'],
            'h5pactivity' => ['name' => 'LW h5p'],
        ];
        $modules = [];
        foreach ($records as $modname => $record) {
            $record += ['course' => $course->id, 'visible' => $hidden ? 0 : 1];
            $modules[$modname] = $generator->create_module($modname, $record);
        }
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($modules['folder']->cmid)->id, 'component' => 'mod_folder',
            'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'lw-folder.txt',
        ], 'Folder file');
        $generator->get_plugin_generator('mod_book')->create_chapter([
            'bookid' => $modules['book']->id, 'content' => 'LW chapter',
        ]);
        $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $modules['forum']->id, 'userid' => $author->id, 'name' => 'LW discussion',
        ]);
        return [$course, $modules];
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
