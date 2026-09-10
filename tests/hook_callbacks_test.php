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
use moodle_page;
use stdClass;

/**
 * Tests for the page injection callback.
 *
 * @covers     \local_learnwise\hook_callbacks
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class hook_callbacks_test extends advanced_testcase {
    /**
     * Reset the global page between tests so page layout changes do not leak.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Turn on the floating assistant widget.
     *
     * @param string $assistantid The configured assistant id
     */
    protected function enable_widget(string $assistantid = 'assistant-123'): void {
        set_config('showassistantwidget', 1, 'local_learnwise');
        set_config('assistantid', $assistantid, 'local_learnwise');
    }

    /**
     * Build a fresh page object for a course.
     *
     * @param stdClass|null $course Course to set on the page, or null for the site course
     * @return moodle_page
     */
    protected function make_page($course = null): moodle_page {
        global $PAGE;

        $PAGE = new moodle_page();
        $PAGE->set_url('/course/view.php');
        $PAGE->set_course($course ?? get_site());
        return $PAGE;
    }

    /**
     * Logged-out visitors get nothing injected.
     */
    public function test_no_output_when_logged_out(): void {
        $this->setUser(null);
        $this->enable_widget();
        $this->make_page();

        $this->assertNull(hook_callbacks::before_standard_top_of_body_html_generation());
    }

    /**
     * Guests get nothing injected.
     */
    public function test_no_output_for_guest(): void {
        $this->setGuestUser();
        $this->enable_widget();
        $this->make_page();

        $this->assertNull(hook_callbacks::before_standard_top_of_body_html_generation());
    }

    /**
     * With no assistant configured nothing is injected.
     */
    public function test_no_output_without_configuration(): void {
        $this->setAdminUser();
        $this->make_page();

        $this->assertSame('', hook_callbacks::before_standard_top_of_body_html_generation());
    }

    /**
     * The widget is not rendered when an assistant id is set but the widget is disabled.
     */
    public function test_no_widget_when_disabled(): void {
        $this->setAdminUser();
        set_config('showassistantwidget', 0, 'local_learnwise');
        set_config('assistantid', 'assistant-123', 'local_learnwise');
        $this->make_page();

        $this->assertNotContains('assistant-123', hook_callbacks::before_standard_top_of_body_html_generation());
    }

    /**
     * The widget is not rendered when enabled but no assistant id is configured.
     */
    public function test_no_widget_without_assistant_id(): void {
        $this->setAdminUser();
        set_config('showassistantwidget', 1, 'local_learnwise');
        $this->make_page();

        $this->assertSame('', hook_callbacks::before_standard_top_of_body_html_generation());
    }

    /**
     * A fully configured widget is injected for a logged-in user.
     */
    public function test_widget_is_injected_when_configured(): void {
        $this->setAdminUser();
        $this->enable_widget();
        $this->make_page();

        $html = hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertContains('assistant-123', $html);
    }

    /**
     * Maintenance, print and redirect layouts are skipped.
     */
    public function test_skipped_page_layouts(): void {
        $this->setAdminUser();
        $this->enable_widget();

        foreach (['maintenance', 'print', 'redirect'] as $layout) {
            $page = $this->make_page();
            $page->set_pagelayout($layout);

            $this->assertNull(
                hook_callbacks::before_standard_top_of_body_html_generation(),
                "Layout {$layout} must not receive injected markup"
            );
        }
    }

    /**
     * When a course allowlist is configured, only those courses get the widget.
     */
    public function test_widget_respects_course_allowlist(): void {
        $this->setAdminUser();
        $this->enable_widget();

        $allowed = $this->getDataGenerator()->create_course();
        $blocked = $this->getDataGenerator()->create_course();
        set_config('courseids', (string) $allowed->id, 'local_learnwise');

        $this->make_page($allowed);
        $this->assertContains('assistant-123', hook_callbacks::before_standard_top_of_body_html_generation());

        $this->make_page($blocked);
        $this->assertNotContains(
            'assistant-123',
            hook_callbacks::before_standard_top_of_body_html_generation()
        );
    }

    /**
     * An empty allowlist means the widget shows everywhere.
     */
    public function test_empty_allowlist_shows_everywhere(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('courseids', '', 'local_learnwise');

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);

        $this->assertContains('assistant-123', hook_callbacks::before_standard_top_of_body_html_generation());
    }

    /**
     * The rendered widget carries the environment's remote and chat hosts.
     */
    public function test_widget_uses_environment_hosts(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('environment', 'production', 'local_learnwise');
        $this->make_page();

        $html = hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertContains('aiden.learnwise.ai', $html);
        $this->assertContains('chat.learnwise.ai', $html);
    }

    /**
     * The LTI view page gets the iframe resize script.
     */
    public function test_lti_view_page_gets_resize_script(): void {
        global $PAGE;
        $this->setAdminUser();

        $PAGE = new moodle_page();
        $PAGE->set_url('/mod/lti/view.php', ['id' => 1]);
        $PAGE->set_course(get_site());

        $html = hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertContains('contentframe', $html);
        $this->assertContains('<script>', $html);
    }

    /**
     * Ordinary pages do not get the LTI resize script.
     */
    public function test_non_lti_page_has_no_resize_script(): void {
        $this->setAdminUser();
        $this->make_page();

        $this->assertNotContains(
            'contentframe',
            (string) hook_callbacks::before_standard_top_of_body_html_generation()
        );
    }

    /**
     * Outside a course the widget is suppressed when it is restricted to courses only.
     */
    public function test_widget_hidden_off_course_when_restricted_to_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();

        set_config('showincoursesonly', 1, 'local_learnwise');
        $this->make_page();

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should not be found in HTML when restricted to courses only'
        );
    }

    /**
     * Outside a course the widget still shows when it is not restricted to courses.
     */
    public function test_widget_shown_off_course_when_not_restricted(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('showincoursesonly', 0, 'local_learnwise');
        $this->make_page();

        $html = hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found in HTML when not restricted to courses'
        );
        $this->assertTrue(
            strpos($html, 'courseId: null') !== false,
            'courseId: null should be found in HTML'
        );
    }

    /**
     * The course restriction only applies off-course: inside a course the allowlist decides.
     */
    public function test_course_restriction_does_not_affect_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('showincoursesonly', 1, 'local_learnwise');

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);

        $html = hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found even with course restriction when inside a course'
        );
    }

    /**
     * The "allow" course keeps loads assistant.
     */
    public function test_allow_course_limits_to_listed_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();

        $allowed = $this->getDataGenerator()->create_course();
        $blocked = $this->getDataGenerator()->create_course();
        set_config('courseids', (string) $allowed->id, 'local_learnwise');

        $this->make_page($allowed);
        $html = hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found in allowed course'
        );

        $this->make_page($blocked);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should not be found in blocked course'
        );
    }

    /**
     * An empty course list means every course gets the widget under either operation.
     */
    public function test_empty_course_list_shows_in_every_course(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('courseids', '', 'local_learnwise');

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);

        $html = hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found when course list is empty (shows in all courses)'
        );
    }

    /**
     * Inside a course the real course id reaches the widget; the site course maps to null.
     */
    public function test_widget_course_id_excludes_the_site_course(): void {
        $this->setAdminUser();
        $this->enable_widget();

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);

        $html = hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, "courseId: \"{$course->id}\"") !== false,
            "courseId: \"{$course->id}\" should be found in course page"
        );

        $this->make_page();
        $html = hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, 'courseId: null') !== false,
            'courseId: null should be found on site course'
        );
    }
}
