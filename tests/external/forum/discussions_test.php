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

namespace local_learnwise\external\forum;

use local_learnwise\advanced_testcase;
use context_module;
use local_learnwise\external\baseapi;
use local_learnwise\external\timestampvalue;
use moodle_exception;
use required_capability_exception;
use stdClass;

/**
 * Tests for the forum discussions API.
 *
 * @covers     \local_learnwise\external\forum\discussions
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class discussions_test extends advanced_testcase {
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
     * The API is driven by a course id and a forum course module id.
     */
    public function test_execute_parameters(): void {
        $params = discussions::execute_parameters();

        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
        $this->assertSame(PARAM_INT, $params->keys['forumid']->type);
    }

    /**
     * A forum with no discussions yields an empty list rather than an error.
     */
    public function test_execute_on_an_empty_forum(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $this->setAdminUser();

        $this->assertSame([], discussions::execute($course->id, $forum->cmid));
    }

    /**
     * Discussions are listed by id and name.
     */
    public function test_execute_lists_the_discussions(): void {
        [$course, $forum, $user] = $this->create_forum();
        $this->create_discussion($course, $forum, $user, 'First topic');
        $this->create_discussion($course, $forum, $user, 'Second topic');
        $this->setUser($user);

        $response = discussions::execute($course->id, $forum->cmid);

        $names = array_column($response, 'name');
        sort($names);
        $this->assertSame(['First topic', 'Second topic'], $names);
    }

    /**
     * A listed discussion does not drag its posts along.
     */
    public function test_execute_omits_posts_from_the_list(): void {
        [$course, $forum, $user] = $this->create_forum();
        $this->create_discussion($course, $forum, $user, 'First topic');
        $this->setUser($user);

        $response = discussions::execute($course->id, $forum->cmid);

        $this->assertArrayNotHasKey('posts', $response[0]);
    }

    /**
     * Pinning a discussion returns it on its own, with its posts.
     */
    public function test_execute_inlines_posts_when_pinned(): void {
        [$course, $forum, $user] = $this->create_forum();
        $discussion = $this->create_discussion($course, $forum, $user, 'First topic');
        $this->setUser($user);
        discussions::set_id($discussion->id);

        $response = discussions::execute($course->id, $forum->cmid);

        $this->assertSame((int) $discussion->id, (int) $response['id']);
        $this->assertSame('First topic', $response['name']);
        $this->assertCount(1, $response['posts']);
        $this->assertSame('First topic', $response['posts'][0]['subject']);
        $this->assertSame(0, (int) $response['posts'][0]['parentid']);
    }

    /**
     * Post messages are flattened to plain text.
     */
    public function test_execute_strips_markup_from_post_messages(): void {
        [$course, $forum, $user] = $this->create_forum();
        $discussion = $this->create_discussion($course, $forum, $user, 'First topic', '<p>Hello <b>there</b></p>');
        $this->setUser($user);
        discussions::set_id($discussion->id);

        $response = discussions::execute($course->id, $forum->cmid);

        $this->assertSame('Hello there', trim($response['posts'][0]['message']));
    }

    /**
     * Replies are returned alongside the opening post, newest first.
     */
    public function test_execute_returns_replies(): void {
        [$course, $forum, $user] = $this->create_forum();
        $discussion = $this->create_discussion($course, $forum, $user, 'First topic');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $parent = $generator->create_post([
            'discussion' => $discussion->id,
            'userid' => $user->id,
            'parent' => $discussion->firstpost,
            'subject' => 'Re: First topic',
            'message' => 'A reply',
        ]);
        $this->setUser($user);
        discussions::set_id($discussion->id);

        $response = discussions::execute($course->id, $forum->cmid);

        $byid = [];
        foreach ($response['posts'] as $post) {
            $byid[(int) $post['id']] = $post;
        }
        $this->assertCount(2, $byid);
        $this->assertArrayHasKey((int) $parent->id, $byid);
        $this->assertSame((int) $discussion->firstpost, (int) $byid[(int) $parent->id]['parentid']);
        $this->assertSame('A reply', trim($byid[(int) $parent->id]['message']));
    }

    /**
     * Listing a hidden forum is refused, so its discussion titles do not leak.
     */
    public function test_execute_refuses_to_list_a_hidden_forum(): void {
        [$course, $forum, $user] = $this->create_forum();
        $this->create_discussion($course, $forum, $user, 'Secret topic');
        set_coursemodule_visible($forum->cmid, 0);
        rebuild_course_cache($course->id, true);
        $this->setUser($user);

        $this->expectException(moodle_exception::class);
        discussions::execute($course->id, $forum->cmid);
    }

    /**
     * Listing a forum restricted by an availability condition is refused.
     */
    public function test_execute_refuses_to_list_a_restricted_forum(): void {
        global $CFG;
        $CFG->enableavailability = true;
        $course = $this->getDataGenerator()->create_course();
        $availability = json_encode(\core_availability\tree::get_root_json([
            \availability_date\condition::get_json(\availability_date\condition::DIRECTION_FROM, time() + DAYSECS),
        ]));
        $forum = $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id,
            'availability' => $availability,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->create_discussion($course, $forum, $user, 'Secret topic');
        $this->setUser($user);

        $this->expectException(moodle_exception::class);
        discussions::execute($course->id, $forum->cmid);
    }

    /**
     * Listing requires mod/forum:viewdiscussion in the forum itself.
     */
    public function test_execute_list_requires_viewdiscussion(): void {
        global $DB;
        [$course, $forum, $user] = $this->create_forum();
        $this->create_discussion($course, $forum, $user, 'Secret topic');
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        assign_capability(
            'mod/forum:viewdiscussion',
            CAP_PROHIBIT,
            $studentrole,
            context_module::instance($forum->cmid)->id,
            true
        );
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        discussions::execute($course->id, $forum->cmid);
    }

    /**
     * Pinning a discussion is refused under the same forum-level checks.
     */
    public function test_execute_refuses_a_pinned_discussion_in_a_hidden_forum(): void {
        [$course, $forum, $user] = $this->create_forum();
        $discussion = $this->create_discussion($course, $forum, $user, 'Secret topic');
        set_coursemodule_visible($forum->cmid, 0);
        rebuild_course_cache($course->id, true);
        $this->setUser($user);
        discussions::set_id($discussion->id);

        $this->expectException(moodle_exception::class);
        discussions::execute($course->id, $forum->cmid);
    }

    /**
     * A teacher who may see hidden activities can still list a hidden forum.
     */
    public function test_execute_lists_a_hidden_forum_for_a_teacher(): void {
        [$course, $forum, $user] = $this->create_forum();
        $this->create_discussion($course, $forum, $user, 'Secret topic');
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        set_coursemodule_visible($forum->cmid, 0);
        rebuild_course_cache($course->id, true);
        $this->setUser($teacher);

        $response = discussions::execute($course->id, $forum->cmid);

        $this->assertSame(['Secret topic'], array_column($response, 'name'));
    }

    /**
     * Posts are only declared for the single discussion flavour.
     */
    public function test_single_structure_declares_posts_only_when_pinned(): void {
        $this->assertSame(['id', 'name'], array_keys(discussions::single_structure()->keys));

        discussions::set_id(1);

        $this->assertArrayHasKey('posts', discussions::single_structure()->keys);
    }

    /**
     * The time a post was made is reported as ISO 8601.
     */
    public function test_post_structure_reports_the_time_as_a_timestamp(): void {
        $structure = discussions::post_structure();

        $this->assertSame(
            ['id', 'subject', 'message', 'parentid', 'timecreated'],
            array_keys($structure->keys)
        );
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timecreated']);
    }

    /**
     * Build a course with a forum and an enrolled user.
     *
     * @return array
     */
    protected function create_forum() {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return [$course, $forum, $user];
    }

    /**
     * Start a discussion in the given forum.
     *
     * @param stdClass $course Course the forum belongs to
     * @param stdClass $forum Forum to post in
     * @param stdClass $user Author of the opening post
     * @param string $name Discussion name
     * @param string $message Opening post body
     * @return stdClass
     */
    protected function create_discussion($course, $forum, $user, $name, $message = 'Opening post') {
        return $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => $name,
            'message' => $message,
        ]);
    }
}
