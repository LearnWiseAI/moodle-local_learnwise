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

        $this->assertStringNotContainsString('assistant-123', hook_callbacks::before_standard_top_of_body_html_generation());
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

        $this->assertStringContainsString('assistant-123', $html);
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
     * The rendered widget carries the environment's remote and chat hosts.
     */
    public function test_widget_uses_environment_hosts(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('environment', 'production', 'local_learnwise');
        $this->make_page();

        $html = hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertStringContainsString('aiden.learnwise.ai', $html);
        $this->assertStringContainsString('chat.learnwise.ai', $html);
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

        $this->assertStringContainsString('contentframe', $html);
        $this->assertStringContainsString('<script>', $html);
    }

    /**
     * Ordinary pages do not get the LTI resize script.
     */
    public function test_non_lti_page_has_no_resize_script(): void {
        $this->setAdminUser();
        $this->make_page();

        $this->assertStringNotContainsString(
            'contentframe',
            (string) hook_callbacks::before_standard_top_of_body_html_generation()
        );
    }

    /**
     * All places the widget still shows when it is not restricted to courses.
     */
    public function test_widget_shown_on_every_page__when_not_restricted(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('showincoursesonly', 0, constants::COMPONENT);
        $this->make_page();

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found in HTML when not restricted to courses'
        );
        $this->assertTrue(
            strpos($html, 'courseId: null') !== false,
            'courseId: null should be found in HTML'
        );

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();

        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found in HTML when not restricted to courses'
        );
        $this->assertTrue(
            strpos($html, "courseId: \"{$course->id}\"") !== false,
            "courseId: \"{$course->id}\" should be found in course page"
        );
    }

    /**
     * When a course allowlist is configured, only those courses get the widget.
     */
    public function test_widget_respects_course_allowlist(): void {
        $this->setAdminUser();
        $this->enable_widget();

        $allowed = $this->getDataGenerator()->create_course();
        $blocked = $this->getDataGenerator()->create_course();
        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses((string) $allowed->id);

        $this->make_page($allowed);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(strpos($html, 'assistant-123') !== false);

        $this->make_page($blocked);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(strpos($html, 'assistant-123') !== false);
    }

    /**
     * An empty allowlist hides the widget in courses.
     */
    public function test_empty_allowlist_hides_in_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses('');

        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(strpos($html, 'assistant-123') !== false);
    }

    /**
     * Outside a course the widget is suppressed when it is restricted to courses only.
     */
    public function test_widget_hidden_off_course_when_restricted_to_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();

        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses('');

        $this->make_page();

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should not be found in HTML when restricted to courses only'
        );
    }

    /**
     * The course restriction only applies off-course: inside a course the allowlist decides.
     */
    public function test_course_restriction_does_not_affect_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('showincoursesonly', 1, constants::COMPONENT);

        $course = $this->getDataGenerator()->create_course();
        util::add_courses((string) $course->id);
        $this->make_page($course);

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, 'assistant-123') !== false,
            'assistant-123 should be found even with course restriction when inside a course'
        );

        $this->make_page();
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, 'assistant-123') === false,
            'assistant-123 should not be found even with course restriction on site page'
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
        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses((string) $allowed->id);

        $this->make_page($allowed);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
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
     * Excluding the last enabled course hides every course; adding one back restores only that course.
     */
    public function test_remove_all_courses_and_enable_one_again(): void {
        $this->setAdminUser();
        $this->enable_widget();
        $first = $this->getDataGenerator()->create_course();
        $second = $this->getDataGenerator()->create_course();
        $ids = $first->id . ',' . $second->id;
        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses($ids);
        foreach ([$first, $second] as $course) {
            $this->make_page($course);
            $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
            $this->assertTrue(strpos($html, 'assistant-123') !== false);
        }
        util::remove_courses($ids);
        $this->assertSame('', get_config(constants::COMPONENT, 'courseids'));

        foreach ([$first, $second] as $course) {
            $this->make_page($course);
            $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
            $this->assertFalse(strpos($html, 'assistant-123') !== false);
        }

        util::add_courses((string) $first->id);
        $this->make_page($first);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(strpos($html, 'assistant-123') !== false);
        $this->make_page($second);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(strpos($html, 'assistant-123') !== false);
    }

    /**
     * A fresh installation without a course selection does not enable newly created courses.
     */
    public function test_unset_allowlist_hides_in_courses(): void {
        $this->setAdminUser();
        $this->enable_widget();
        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses('');
        $course = $this->getDataGenerator()->create_course();
        $this->make_page($course);
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(strpos($html, 'assistant-123') !== false);
    }

    /**
     * Inside a course the real course id reaches the widget; the site course maps to null.
     */
    public function test_widget_course_id_excludes_the_site_course(): void {
        $this->setAdminUser();
        $this->enable_widget();

        $course = $this->getDataGenerator()->create_course();
        set_config('showincoursesonly', 1, constants::COMPONENT);
        util::add_courses((string) $course->id);
        $this->make_page($course);

        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertTrue(
            strpos($html, "courseId: \"{$course->id}\"") !== false,
            "courseId: \"{$course->id}\" should be found in course page"
        );

        $this->make_page();
        $html = (string) hook_callbacks::before_standard_top_of_body_html_generation();
        $this->assertFalse(
            strpos($html, 'courseId: null') !== false,
            'courseId: null should be found on site course'
        );
    }
}
