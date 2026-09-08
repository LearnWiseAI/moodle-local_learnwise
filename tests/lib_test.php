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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/learnwise/lib.php');

/**
 * Tests for the plugin's callback functions in lib.php.
 *
 * The fragment callbacks are reachable by any logged-in user through Moodle's fragment API,
 * so each one has to enforce its own capability check and sanitise its own input. These
 * tests cover those guards.
 *
 * The plugin's external classes load the deprecated lib/externallib.php at file scope,
 * which Moodle only permits inside an isolated process.
 *
 * @runTestsInSeparateProcesses
 * @covers     ::local_learnwise_output_fragment_process_courses
 * @covers     ::local_learnwise_output_fragment_form
 * @covers     ::local_learnwise_output_fragment_refresh_lticonfig
 * @covers     ::local_learnwise_output_fragment_process_setup
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $admin;

    /** @var \stdClass */
    protected $student;

    /**
     * Prepare an admin and a plain user for the capability checks.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->admin = get_admin();
        $this->student = $this->getDataGenerator()->create_user();
    }

    /**
     * An admin can add courses to the selected list through the fragment.
     */
    public function test_process_courses_add_as_admin(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = local_learnwise_output_fragment_process_courses([
            'context' => \context_system::instance(),
            'action' => 'add',
            'courseids' => (string) $course->id,
        ]);

        $this->assertSame(['success' => true], json_decode($result, true));
        $this->assertSame((string) $course->id, get_config('local_learnwise', 'courseids'));
    }

    /**
     * An admin can remove courses through the fragment.
     */
    public function test_process_courses_remove_as_admin(): void {
        $this->setAdminUser();
        util::add_courses('11,12,13');

        local_learnwise_output_fragment_process_courses([
            'context' => \context_system::instance(),
            'action' => 'remove',
            'courseids' => '12',
        ]);

        $stored = array_values(array_filter(explode(',', get_config('local_learnwise', 'courseids'))));
        $this->assertSame(['11', '13'], $stored);
    }

    /**
     * A non-admin cannot change the selected course list.
     *
     * Without the capability check any logged-in user could call this fragment and
     * reconfigure which courses the assistant is exposed to.
     */
    public function test_process_courses_requires_site_config(): void {
        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);

        local_learnwise_output_fragment_process_courses([
            'context' => \context_system::instance(),
            'action' => 'add',
            'courseids' => '1',
        ]);
    }

    /**
     * A rejected call leaves the stored configuration untouched.
     */
    public function test_process_courses_denied_changes_nothing(): void {
        $this->setAdminUser();
        util::add_courses('5');

        $this->setUser($this->student);
        try {
            local_learnwise_output_fragment_process_courses([
                'context' => \context_system::instance(),
                'action' => 'add',
                'courseids' => '6',
            ]);
            $this->fail('Expected required_capability_exception');
        } catch (\required_capability_exception $e) {
            $this->assertSame('5', get_config('local_learnwise', 'courseids'));
        }
    }

    /**
     * Course ids are cleaned to a plain integer sequence before use.
     *
     * The value is concatenated into a config string that is later split and used as ids,
     * so anything that is not a comma separated integer list must be stripped here.
     */
    public function test_process_courses_sanitises_courseids(): void {
        $this->setAdminUser();

        local_learnwise_output_fragment_process_courses([
            'context' => \context_system::instance(),
            'action' => 'add',
            'courseids' => "1,2,<script>alert(1)</script>,3';DROP TABLE users;--",
        ]);

        $stored = get_config('local_learnwise', 'courseids');
        $this->assertMatchesRegularExpression('/^[\d,]*$/', $stored, 'Only digits and commas may be stored');
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('DROP', $stored);
    }

    /**
     * A completely non-numeric course id list is reduced to nothing.
     */
    public function test_process_courses_rejects_non_numeric_input(): void {
        $this->setAdminUser();

        local_learnwise_output_fragment_process_courses([
            'context' => \context_system::instance(),
            'action' => 'add',
            'courseids' => 'abc',
        ]);

        $stored = get_config('local_learnwise', 'courseids');
        $this->assertMatchesRegularExpression('/^[\d,]*$/', (string) $stored);
        $this->assertStringNotContainsString('abc', (string) $stored);
    }

    /**
     * An unrecognised action is a no-op rather than an error.
     */
    public function test_process_courses_unknown_action(): void {
        $this->setAdminUser();
        util::add_courses('9');

        $result = local_learnwise_output_fragment_process_courses([
            'context' => \context_system::instance(),
            'action' => 'explode',
            'courseids' => '9',
        ]);

        $this->assertSame(['success' => true], json_decode($result, true));
        $this->assertSame('9', get_config('local_learnwise', 'courseids'));
    }

    /**
     * The LTI config refresh fragment is admin-only.
     */
    public function test_refresh_lticonfig_requires_site_config(): void {
        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);

        local_learnwise_output_fragment_refresh_lticonfig([
            'context' => \context_system::instance(),
            'action' => 'refreshtable',
        ]);
    }

    /**
     * Refreshing a single row for an unknown LTI type yields empty output, not an error.
     */
    public function test_refresh_lticonfig_unknown_row(): void {
        $this->setAdminUser();

        $html = local_learnwise_output_fragment_refresh_lticonfig([
            'context' => \context_system::instance(),
            'action' => 'refreshtablerow',
            'id' => 0,
        ]);

        $this->assertSame('', $html);
    }

    /**
     * Only the LTI update form may be instantiated through the form fragment.
     *
     * The form class name arrives in caller-supplied form data and is used to construct an
     * object, so anything outside the allowlist has to be refused.
     */
    public function test_form_fragment_rejects_unexpected_formclass(): void {
        $this->setAdminUser();

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Unexpected formclass');

        local_learnwise_output_fragment_form([
            'context' => \context_system::instance(),
            'formdata' => http_build_query([
                'formclass' => \stdClass::class,
                'action' => 'update',
            ]),
        ]);
    }

    /**
     * A core Moodle class cannot be smuggled in as the form class either.
     */
    public function test_form_fragment_rejects_arbitrary_core_class(): void {
        $this->setAdminUser();

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Unexpected formclass');

        local_learnwise_output_fragment_form([
            'context' => \context_system::instance(),
            'formdata' => http_build_query([
                'formclass' => 'moodle_url',
                'action' => 'update',
            ]),
        ]);
    }

    /**
     * An empty form class is refused rather than treated as a default.
     */
    public function test_form_fragment_rejects_empty_formclass(): void {
        $this->setAdminUser();

        $this->expectException(\coding_exception::class);
        $this->expectExceptionMessage('Unexpected formclass');

        local_learnwise_output_fragment_form([
            'context' => \context_system::instance(),
            'formdata' => http_build_query([
                'formclass' => '',
                'action' => 'update',
            ]),
        ]);
    }

    /**
     * The setup fragment is admin-only.
     */
    public function test_process_setup_requires_site_config(): void {
        $this->setUser($this->student);

        $this->expectException(\required_capability_exception::class);

        local_learnwise_output_fragment_process_setup([
            'context' => \context_system::instance(),
            'formdata' => http_build_query(['enablewebservice' => 0]),
        ]);
    }

    /**
     * The setup fragment returns a string for an admin caller.
     */
    public function test_process_setup_as_admin_returns_string(): void {
        $this->setAdminUser();

        $result = local_learnwise_output_fragment_process_setup([
            'context' => \context_system::instance(),
            'formdata' => http_build_query([]),
        ]);

        $this->assertIsString($result);
    }

    /**
     * The top-of-body callback delegates to the hook implementation.
     */
    public function test_before_standard_top_of_body_html_returns_string(): void {
        global $PAGE;

        $this->setAdminUser();
        $PAGE->set_url('/course/view.php');

        $this->assertIsString(local_learnwise_before_standard_top_of_body_html());
    }
}
