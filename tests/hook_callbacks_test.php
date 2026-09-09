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
     * When a course allowlist is configured, only those courses get the widget.
     */
    public function test_widget_respects_course_allowlist(): void {
        $this->setAdminUser();
        $this->enable_widget();

        $allowed = $this->getDataGenerator()->create_course();
        $blocked = $this->getDataGenerator()->create_course();
        set_config('courseids', (string) $allowed->id, 'local_learnwise');

        $this->make_page($allowed);
        $this->assertStringContainsString('assistant-123', hook_callbacks::before_standard_top_of_body_html_generation());

        $this->make_page($blocked);
        $this->assertStringNotContainsString(
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

        $this->assertStringContainsString('assistant-123', hook_callbacks::before_standard_top_of_body_html_generation());
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
}
