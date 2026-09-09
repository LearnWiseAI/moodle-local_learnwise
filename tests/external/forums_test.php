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

/**
 * Tests for the forums API.
 *
 * @covers     \local_learnwise\external\forums
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class forums_test extends advanced_testcase {
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
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * The API is driven by a course id.
     */
    public function test_execute_parameters_take_a_course_id(): void {
        $params = forums::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * A course without forums yields an empty list rather than an error.
     */
    public function test_execute_on_a_course_without_forums(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], forums::execute($course->id));
    }

    /**
     * Each forum is listed by course module id and name.
     */
    public function test_execute_lists_the_course_forums(): void {
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'News']);
        $second = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Q and A']);
        $this->setAdminUser();

        $response = forums::execute($course->id);

        $byid = [];
        foreach ($response as $forum) {
            $byid[(int) $forum['id']] = $forum['name'];
        }
        $this->assertSame('News', $byid[(int) $first->cmid]);
        $this->assertSame('Q and A', $byid[(int) $second->cmid]);
    }

    /**
     * A forum the caller may not read is left out of the list.
     */
    public function test_execute_skips_forums_the_user_cannot_read(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $visible = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Open']);
        $restricted = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'Closed']);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability(
            'mod/forum:viewdiscussion',
            CAP_PROHIBIT,
            $roleid,
            context_module::instance($restricted->cmid)->id,
            true
        );
        $this->setUser($student);

        $ids = array_map('intval', array_column(forums::execute($course->id), 'id'));

        $this->assertContains((int) $visible->cmid, $ids);
        $this->assertNotContains((int) $restricted->cmid, $ids);
    }

    /**
     * Pinning a forum returns that single forum with its discussions inlined.
     */
    public function test_execute_inlines_discussions_when_pinned(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id, 'name' => 'News']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Welcome',
        ]);
        $this->setUser($user);
        forums::set_id($forum->cmid);

        $response = forums::execute($course->id);

        $this->assertSame((int) $forum->cmid, (int) $response['id']);
        $this->assertSame('News', $response['name']);
        $this->assertCount(1, $response['discussions']);
        $this->assertSame('Welcome', $response['discussions'][0]['name']);
    }

    /**
     * Discussions are only declared for the single forum flavour.
     */
    public function test_single_structure_declares_discussions_only_when_pinned(): void {
        $this->assertSame(['id', 'name'], array_keys(forums::single_structure()->keys));

        forums::set_id(1);

        $this->assertArrayHasKey('discussions', forums::single_structure()->keys);
    }
}
