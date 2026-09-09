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

use advanced_testcase;
use dml_missing_record_exception;
use external_multiple_structure;
use external_single_structure;
use local_learnwise\external\baseapi;

/**
 * Tests for the single forum discussion API.
 *
 * @covers     \local_learnwise\external\forum\singlediscussion
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class singlediscussion_test extends advanced_testcase {
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
     * The API is driven by the discussion id alone; the forum is looked up from it.
     */
    public function test_execute_parameters_take_a_discussion_id(): void {
        $params = singlediscussion::execute_parameters();

        $this->assertSame(['id'], array_keys($params->keys));
        $this->assertSame(PARAM_INT, $params->keys['id']->type);
    }

    /**
     * The API always answers with one discussion rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(singlediscussion::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, singlediscussion::execute_returns());
    }

    /**
     * Posts are always part of the response.
     */
    public function test_single_structure_always_declares_posts(): void {
        $structure = singlediscussion::single_structure();

        $this->assertSame(['id', 'name', 'posts'], array_keys($structure->keys));
        $this->assertInstanceOf(external_multiple_structure::class, $structure->keys['posts']);
    }

    /**
     * Looking a discussion up by its own id returns it with its posts.
     */
    public function test_execute_returns_the_discussion_with_its_posts(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $discussion = $this->getDataGenerator()->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Only topic',
        ]);
        $this->setUser($user);

        $response = singlediscussion::execute($discussion->id);

        $this->assertSame((int) $discussion->id, (int) $response['id']);
        $this->assertSame('Only topic', $response['name']);
        $this->assertCount(1, $response['posts']);
    }

    /**
     * Only the requested discussion comes back, even when the forum holds several.
     */
    public function test_execute_ignores_the_other_discussions_in_the_forum(): void {
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Unwanted',
        ]);
        $wanted = $generator->create_discussion([
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => 'Wanted',
        ]);
        $this->setUser($user);

        $response = singlediscussion::execute($wanted->id);

        $this->assertSame('Wanted', $response['name']);
    }

    /**
     * An unknown discussion is rejected rather than silently ignored.
     */
    public function test_execute_rejects_an_unknown_discussion(): void {
        $this->setAdminUser();

        $this->expectException(dml_missing_record_exception::class);
        singlediscussion::execute(-1);
    }
}
