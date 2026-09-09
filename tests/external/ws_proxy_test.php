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
use core_plugin_manager;
use local_learnwise\api_response;
use local_learnwise\api_server;

/**
 * Tests for the generic web service proxy.
 *
 * @covers     \local_learnwise\external\ws_proxy
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class ws_proxy_test extends advanced_testcase {
    /**
     * The request method before the test replaced it.
     *
     * @var string|null
     */
    protected $originalmethod = null;

    /**
     * The query string parameters before the test replaced them.
     *
     * @var array
     */
    protected $originalget = [];

    /**
     * The proxy reads the request straight out of the PHP superglobals.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->originalmethod = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : null;
        $this->originalget = $_GET;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
    }

    /**
     * Put the superglobals back the way they were.
     */
    protected function tearDown(): void {
        if ($this->originalmethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $this->originalmethod;
        }
        $_GET = $this->originalget;
        parent::tearDown();
    }

    /**
     * A path with no function name is rejected.
     */
    public function test_dispatch_rejects_a_path_without_a_function(): void {
        $response = $this->dispatch(['core_course']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            'Invalid WS path: expected /ws/<component>/<function>',
            $response->getParameters()['error']
        );
    }

    /**
     * An empty path is rejected.
     */
    public function test_dispatch_rejects_an_empty_path(): void {
        $response = $this->dispatch(['']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * A component that does not exist in this Moodle is rejected.
     */
    public function test_dispatch_rejects_an_unknown_component(): void {
        $response = $this->dispatch(['mod_notaplugin', 'get_things']);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('Invalid WS component', $response->getParameters()['error']);
    }

    /**
     * A function that is not a registered web service is refused.
     */
    public function test_dispatch_refuses_a_function_outside_the_whitelist(): void {
        $response = $this->dispatch(['core_course', 'not_a_real_function']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            "Function 'core_course_not_a_real_function' is not allowed through this proxy.",
            $response->getParameters()['error']
        );
    }

    /**
     * A disabled plugin cannot be reached through the proxy.
     */
    public function test_dispatch_refuses_a_disabled_component(): void {
        global $DB;

        $DB->set_field('modules', 'visible', 0, ['name' => 'choice']);
        core_plugin_manager::reset_caches();

        $response = $this->dispatch(['mod_choice', 'get_choices_by_courses']);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Component disabled: mod_choice', $response->getParameters()['error']);
    }

    /**
     * An allowed call is handed on to the Moodle web service machinery.
     */
    public function test_dispatch_forwards_an_allowed_call(): void {
        $server = $this->make_server(['core_course', 'get_courses']);
        $server->expects($this->once())
            ->method('set_functionname')
            ->with('core_course_get_courses');
        $server->expects($this->once())
            ->method('set_parameters')
            ->with([]);

        ws_proxy::dispatch($server);

        $this->assertSame(200, $server->get_response()->getStatusCode());
    }

    /**
     * A short component name is expanded to its full frankenstyle name.
     */
    public function test_dispatch_normalises_the_component_name(): void {
        $server = $this->make_server(['assign', 'get_assignments']);
        $server->expects($this->once())
            ->method('set_functionname')
            ->with('mod_assign_get_assignments');

        ws_proxy::dispatch($server);
    }

    /**
     * A function name spread over several path segments is joined back together.
     */
    public function test_dispatch_joins_a_multi_segment_function_name(): void {
        $server = $this->make_server(['core_course', 'get', 'courses']);
        $server->expects($this->once())
            ->method('set_functionname')
            ->with('core_course_get_courses');

        ws_proxy::dispatch($server);
    }

    /**
     * Query string parameters are forwarded to the Moodle function.
     */
    public function test_dispatch_forwards_query_string_parameters(): void {
        $_GET = ['options' => ['ids' => ['2']]];
        $server = $this->make_server(['core_course', 'get_courses']);
        $server->expects($this->once())
            ->method('set_parameters')
            ->with(['options' => ['ids' => ['2']]]);

        ws_proxy::dispatch($server);
    }

    /**
     * The proxy's own pagination controls are not passed on to Moodle.
     */
    public function test_dispatch_strips_the_proxy_pagination_controls(): void {
        $_GET = ['_page' => '2', '_per_page' => '10', 'courseid' => '5'];
        $server = $this->make_server(['core_course', 'get_courses']);
        $server->expects($this->once())
            ->method('set_parameters')
            ->with(['courseid' => '5']);

        ws_proxy::dispatch($server);
    }

    /**
     * An allowed call caps how much JSON may be sent back.
     */
    public function test_dispatch_caps_the_response_size(): void {
        $response = $this->dispatch(['core_course', 'get_courses']);
        $response->setParameters([str_repeat('a', 11 * 1024 * 1024)]);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame('response_too_large', $response->getParameters()['error']);
        $this->assertStringContainsString('10 MiB', $response->getParameters()['error_description']);
    }

    /**
     * The whitelist is built from the web service functions Moodle knows about.
     */
    public function test_get_allowed_functions_covers_registered_functions(): void {
        $allowed = ws_proxy::get_allowed_functions();

        $this->assertArrayHasKey('core_course_get_courses', $allowed);
        $this->assertArrayHasKey('mod_assign_get_assignments', $allowed);
        $this->assertArrayNotHasKey('core_course_not_a_real_function', $allowed);
        $this->assertSame('core_course_get_courses', $allowed['core_course_get_courses']->name);
    }

    /**
     * Dispatch a request and hand back the response the proxy wrote to.
     *
     * @param array $urlparts Path segments after /ws/
     * @return api_response
     */
    protected function dispatch(array $urlparts) {
        $server = $this->make_server($urlparts);
        ws_proxy::dispatch($server);
        return $server->get_response();
    }

    /**
     * Build an API server double that reports the given path and collects the response.
     *
     * @param array $urlparts Path segments after /ws/
     * @return \PHPUnit\Framework\MockObject\MockObject|api_server
     */
    protected function make_server(array $urlparts) {
        $server = $this->createMock(api_server::class);
        $server->method('get_urlparts')->willReturn($urlparts);
        $server->method('get_response')->willReturn(new api_response());
        return $server;
    }
}
