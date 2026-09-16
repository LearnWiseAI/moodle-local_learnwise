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
use core\event\url_blocked;
use core\files\curl_security_helper;
use curl;
use Exception;
use external_single_structure;
use invalid_parameter_exception;

/**
 * Tests for the file accessibility API.
 *
 * @covers     \local_learnwise\external\files
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class files_test extends advanced_testcase {
    /** @var resource|null Web server process serving the test site. */
    private $siteserver = null;

    /** @var string File the web server logs each request it receives to. */
    private $requestlog = '';

    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        baseapi::$my = null;
        baseapi::$ids = [];
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        if (is_resource($this->siteserver)) {
            proc_terminate($this->siteserver);
            proc_close($this->siteserver);
        }
        $this->siteserver = null;
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * The API takes the path of the file to probe.
     */
    public function test_execute_parameters_take_a_path(): void {
        $params = files::execute_parameters();

        $this->assertArrayHasKey('path', $params->keys);
        $this->assertSame(PARAM_PATH, $params->keys['path']->type);
    }

    /**
     * The API always answers with one verdict rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(files::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, files::execute_returns());
    }

    /**
     * The verdict is a single boolean.
     */
    public function test_single_structure_is_a_single_verdict(): void {
        $structure = files::single_structure();

        $this->assertSame(['accessible'], array_keys($structure->keys));
        $this->assertSame(PARAM_BOOL, $structure->keys['accessible']->type);
    }

    /**
     * A path that is not a path at all is rejected before any request is made.
     */
    public function test_execute_rejects_an_invalid_path(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(invalid_parameter_exception::class);
        files::execute('../../etc/passwd');
    }

    /**
     * The short lived key minted for the probe is always cleaned up again.
     */
    public function test_execute_does_not_leave_a_user_key_behind(): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $path = '/1/mod_assign/intro/0/notes.pdf';

        try {
            files::execute($path);
        } catch (Exception $e) {
            // The probe itself needs a reachable web server, which a unit test does not have.
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertFalse($DB->record_exists('user_private_key', [
            'script' => 'local_learnwise_' . sha1($path),
            'userid' => $user->id,
        ]));
    }

    /**
     * Point the site at a closed loopback port, so the probe fails fast without touching the network.
     *
     * @param string $wwwroot Site address to use for the probe.
     * @return string The address the probe is sent to.
     */
    private function use_unreachable_site(string $wwwroot): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $CFG->wwwroot = $wwwroot;
        return $wwwroot . '/local/learnwise/api/file.php';
    }

    /**
     * Events recorded for URLs that curl security refused.
     *
     * @param \phpunit_event_sink $sink Sink the events were redirected to.
     * @return array
     */
    private function get_url_blocked_events($sink): array {
        return array_filter($sink->get_events(), function ($event) {
            return $event instanceof url_blocked;
        });
    }

    /**
     * Blocklist configurations that cover the site's own address.
     *
     * @return array
     */
    public static function blocked_site_provider(): array {
        return [
            'Exact IPv4 address' => [
                'wwwroot' => 'http://127.0.0.1:1/moodle',
                'blockedhosts' => '127.0.0.1',
                'allowedports' => '',
            ],
            'IPv4 CIDR range' => [
                'wwwroot' => 'http://127.0.0.1:1/moodle',
                'blockedhosts' => '127.0.0.0/8',
                'allowedports' => '',
            ],
            'IPv4 address range' => [
                'wwwroot' => 'http://127.0.0.1:1/moodle',
                'blockedhosts' => '127.0.0.1-10',
                'allowedports' => '',
            ],
            'Domain name' => [
                'wwwroot' => 'http://localhost:1/moodle',
                'blockedhosts' => 'localhost',
                'allowedports' => '',
            ],
            'One of several entries' => [
                'wwwroot' => 'http://127.0.0.1:1/moodle',
                'blockedhosts' => "192.168.0.0/16\n10.0.0.0/8\n127.0.0.1",
                'allowedports' => '',
            ],
            'Port missing from the allowed ports' => [
                'wwwroot' => 'http://127.0.0.1:1/moodle',
                'blockedhosts' => '',
                'allowedports' => "443\n80",
            ],
            'Moodle default settings' => [
                'wwwroot' => 'http://127.0.0.1:1/moodle',
                'blockedhosts' => "127.0.0.0/8\n192.168.0.0/16\n10.0.0.0/8\n172.16.0.0/12\n0.0.0.0\nlocalhost\n" .
                    "169.254.169.254\n0000::1",
                'allowedports' => "443\n80",
            ],
        ];
    }

    /**
     * Core curl refuses a site that curlsecurityblockedhosts covers, which is why the probe must bypass it.
     *
     * @dataProvider blocked_site_provider
     * @param string $wwwroot Site address.
     * @param string $blockedhosts Value for curlsecurityblockedhosts.
     * @param string $allowedports Value for curlsecurityallowedport.
     */
    public function test_curl_security_blocks_the_site_without_the_bypass(
        string $wwwroot,
        string $blockedhosts,
        string $allowedports
    ): void {
        $url = $this->use_unreachable_site($wwwroot);
        set_config('curlsecurityblockedhosts', $blockedhosts);
        set_config('curlsecurityallowedport', $allowedports);
        $sink = $this->redirectEvents();

        $this->assertTrue((new curl_security_helper())->url_is_blocked($url));

        $curl = new curl();
        $this->assertSame($curl->get_security()->get_blocked_url_string(), $curl->head($url));

        // Only newer Moodle versions also report the blocked URL with debugging() and a url_blocked event.
        $this->resetDebugging();
        if (class_exists(url_blocked::class)) {
            $this->assertCount(1, $this->get_url_blocked_events($sink));
        }
    }

    /**
     * The probe of the site's own files is never refused by curlsecurityblockedhosts or curlsecurityallowedport.
     *
     * @dataProvider blocked_site_provider
     * @param string $wwwroot Site address.
     * @param string $blockedhosts Value for curlsecurityblockedhosts.
     * @param string $allowedports Value for curlsecurityallowedport.
     */
    public function test_execute_is_not_blocked_by_curl_security_settings(
        string $wwwroot,
        string $blockedhosts,
        string $allowedports
    ): void {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->use_unreachable_site($wwwroot);
        set_config('curlsecurityblockedhosts', $blockedhosts);
        set_config('curlsecurityallowedport', $allowedports);
        $path = '/1/mod_assign/intro/0/notes.pdf';
        $sink = $this->redirectEvents();

        $result = files::execute($path);

        // Nothing listens on the port, so the file is unreachable, but the request was attempted rather than blocked.
        $this->assertSame(['accessible' => false], $result);
        $this->assertEmpty($this->get_url_blocked_events($sink));
        $this->assertFalse($DB->record_exists('user_private_key', [
            'script' => 'local_learnwise_' . sha1($path),
            'userid' => $user->id,
        ]));
    }

    /**
     * With the site outside curlsecurityblockedhosts, the probe runs with the security checks still in place.
     */
    public function test_execute_is_not_blocked_when_the_site_is_not_listed(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $url = $this->use_unreachable_site('http://127.0.0.1:1/moodle');
        set_config('curlsecurityblockedhosts', "192.168.0.0/16\n10.0.0.0/8\nexample.com");
        set_config('curlsecurityallowedport', '');
        $sink = $this->redirectEvents();

        $this->assertFalse((new curl_security_helper())->url_is_blocked($url));

        $this->assertSame(['accessible' => false], files::execute('/1/mod_assign/intro/0/notes.pdf'));
        $this->assertEmpty($this->get_url_blocked_events($sink));
    }

    /**
     * With curlsecurityblockedhosts and curlsecurityallowedport empty, curl security is off and nothing is blocked.
     */
    public function test_execute_is_not_blocked_when_curl_security_is_disabled(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $url = $this->use_unreachable_site('http://127.0.0.1:1/moodle');
        set_config('curlsecurityblockedhosts', '');
        set_config('curlsecurityallowedport', '');
        $sink = $this->redirectEvents();

        $helper = new curl_security_helper();
        $this->assertFalse($helper->is_enabled());
        $this->assertFalse($helper->url_is_blocked($url));

        $this->assertSame(['accessible' => false], files::execute('/1/mod_assign/intro/0/notes.pdf'));
        $this->assertEmpty($this->get_url_blocked_events($sink));
    }

    /**
     * Serve the test site over real HTTP with PHP's built-in web server, and point the site at it.
     *
     * Call it once the test data is in place: the server keeps its own caches, so it does not see later changes.
     */
    private function start_site_server(): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        if (!function_exists('proc_open') || !function_exists('curl_init') || PHP_BINARY === '') {
            $this->markTestSkipped('The PHP built-in web server cannot be started here.');
        }

        // The web server reads the data from another process, so it must be committed.
        $this->preventResetByRollback();

        // Let the OS pick a free port.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $dir = make_request_directory();
        $this->requestlog = $dir . '/requests.log';
        touch($this->requestlog);
        $serverlog = $dir . '/server.log';

        $router = $CFG->dirroot . '/local/learnwise/tests/fixtures/phpunit_site_router.php';
        // A command string rather than an array, which proc_open() only accepts from PHP 7.4.
        // With exec, the shell is replaced by the web server, so proc_terminate() stops the server itself.
        $command = implode(' ', array_map('escapeshellarg', [
            PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $CFG->dirroot, $router,
        ]));
        $this->siteserver = proc_open(
            (DIRECTORY_SEPARATOR === '/' ? 'exec ' : '') . $command,
            [0 => ['pipe', 'r'], 1 => ['file', $serverlog, 'a'], 2 => ['file', $serverlog, 'a']],
            $pipes,
            $dir,
            getenv() + ['LEARNWISE_TEST_CACHEDIR' => $dir, 'LEARNWISE_TEST_REQUESTLOG' => $this->requestlog]
        );
        $this->assertIsResource($this->siteserver, 'The web server could not be started.');

        // Wait until Moodle has booted, so the first real request is not slowed down by a cold cache.
        $deadline = microtime(true) + 60;
        do {
            usleep(100000);
            $ch = curl_init("http://127.0.0.1:{$port}/__ready");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status !== 0 && $status !== 204) {
                $this->fail("The test site did not start ({$status}): {$body}\n" . file_get_contents($serverlog));
            }
        } while ($status !== 204 && microtime(true) < $deadline);
        $this->assertSame(204, $status, 'The web server did not start: ' . file_get_contents($serverlog));

        $CFG->wwwroot = "http://127.0.0.1:{$port}";
    }

    /**
     * Requests the test site received, apart from the start up check.
     *
     * @return array[]
     */
    private function get_site_requests(): array {
        $requests = array_map(function ($line) {
            return json_decode($line, true);
        }, array_filter(explode("\n", file_get_contents($this->requestlog))));

        return array_values(array_filter($requests, function ($request) {
            return $request['path'] !== '/__ready';
        }));
    }

    /**
     * Check how the test site answered the file request, before any redirect was followed.
     *
     * @param int $status Expected HTTP status.
     * @param string $location Text the redirect location should contain, if a redirect is expected.
     */
    private function assert_site_answered(int $status, string $location = ''): void {
        $requests = $this->get_site_requests();
        $this->assertNotEmpty($requests);
        $this->assertSame('/local/learnwise/api/file.php', $requests[0]['path']);
        $this->assertSame($status, $requests[0]['status']);
        if ($location !== '') {
            $locations = preg_grep('/^Location:/i', $requests[0]['headers']);
            $this->assertStringContainsString($location, implode("\n", $locations));
        }
    }

    /**
     * Create an assignment in a new course, with a file in its description.
     *
     * @param array $options Module options, e.g. visibility.
     * @return array The course, and the pluginfile path of the file.
     */
    private function create_assignment_with_intro_file(array $options = []): array {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id] + $options);
        $context = context_module::instance($assign->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_assign',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'notes.pdf',
        ], '%PDF-1.4 Assignment notes');

        return [$course, "/{$context->id}/mod_assign/intro/notes.pdf"];
    }

    /**
     * A student enrolled in the course can open the file.
     */
    public function test_enrolled_student_can_access_the_file(): void {
        [$course, $path] = $this->create_assignment_with_intro_file();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->start_site_server();
        $this->setUser($student);

        $this->assertSame(['accessible' => true], files::execute($path));
        $this->assert_site_answered(200);
    }

    /**
     * A user who is not enrolled in the course cannot open the file.
     */
    public function test_user_not_enrolled_in_the_course_cannot_access_the_file(): void {
        [, $path] = $this->create_assignment_with_intro_file();
        $user = $this->getDataGenerator()->create_user();
        $this->start_site_server();
        $this->setUser($user);

        $this->assertSame(['accessible' => false], files::execute($path));
        $this->assert_site_answered(303, '/enrol/index.php');
    }

    /**
     * A student whose enrolment is suspended cannot open the file.
     */
    public function test_student_with_a_suspended_enrolment_cannot_access_the_file(): void {
        [$course, $path] = $this->create_assignment_with_intro_file();
        $student = $this->getDataGenerator()->create_and_enrol(
            $course,
            'student',
            null,
            'manual',
            0,
            0,
            ENROL_USER_SUSPENDED
        );
        $this->start_site_server();
        $this->setUser($student);

        $this->assertSame(['accessible' => false], files::execute($path));
        $this->assert_site_answered(303, '/enrol/index.php');
    }

    /**
     * A student cannot open the file of an activity hidden from students.
     */
    public function test_student_cannot_access_the_file_of_a_hidden_activity(): void {
        [$course, $path] = $this->create_assignment_with_intro_file(['visible' => 0]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->start_site_server();
        $this->setUser($student);

        $this->assertSame(['accessible' => false], files::execute($path));
        $this->assert_site_answered(303, '/course/view.php');
    }

    /**
     * A teacher can still open the file of an activity hidden from students, so the verdict is per user.
     */
    public function test_teacher_can_access_the_file_of_a_hidden_activity(): void {
        [$course, $path] = $this->create_assignment_with_intro_file(['visible' => 0]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->start_site_server();
        $this->setUser($teacher);

        $this->assertSame(['accessible' => true], files::execute($path));
        $this->assert_site_answered(200);
    }

    /**
     * A file that does not exist is not accessible, even for a student of the course.
     */
    public function test_missing_file_is_not_accessible(): void {
        [$course, $path] = $this->create_assignment_with_intro_file();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->start_site_server();
        $this->setUser($student);

        $this->assertSame(['accessible' => false], files::execute(str_replace('notes.pdf', 'missing.pdf', $path)));
        $this->assert_site_answered(404);
    }

    /**
     * A LearnWise file proxy URL is checked as the pluginfile path that follows file.php.
     */
    public function test_proxy_url_is_checked_as_its_pluginfile_path(): void {
        [$course, $path] = $this->create_assignment_with_intro_file();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->start_site_server();
        $this->setUser($student);

        $this->assertSame(['accessible' => true], files::execute('/local/learnwise/api/file.php' . $path));
        $this->assertSame($path, $this->get_site_requests()[0]['query']['file']);
    }

    /**
     * The check is one HEAD request to api/file.php, with a key for the user that is removed straight after.
     */
    public function test_check_is_a_head_request_with_a_single_use_key(): void {
        global $DB;

        [$course, $path] = $this->create_assignment_with_intro_file();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->start_site_server();
        $this->setUser($student);

        $this->assertSame(['accessible' => true], files::execute($path));

        $requests = $this->get_site_requests();
        $this->assertCount(1, $requests);
        $this->assertSame('HEAD', $requests[0]['method']);
        $this->assertSame('/local/learnwise/api/file.php', $requests[0]['path']);
        $this->assertSame($path, $requests[0]['query']['file']);
        $this->assertNotEmpty($requests[0]['query']['key']);
        $this->assertFalse($DB->record_exists('user_private_key', ['value' => $requests[0]['query']['key']]));
        $this->assertFalse($DB->record_exists('user_private_key', ['userid' => $student->id]));
    }
}
