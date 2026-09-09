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
use external_multiple_structure;
use moodle_exception;

/**
 * Tests for the books API.
 *
 * @covers     \local_learnwise\external\books
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class books_test extends advanced_testcase {
    /**
     * The value of $ME before the test replaced it.
     *
     * @var string|null
     */
    protected $originalme = null;

    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG, $ME;
        require_once($CFG->dirroot . '/course/lib.php');

        parent::setUp();
        $this->resetAfterTest();
        $this->originalme = $ME;
        // The API branches on whether it was reached through Moodle's own web service entry point.
        $ME = '/local/learnwise/api/index.php';
        baseapi::$my = null;
        baseapi::$ids = [];
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        global $ME;
        $ME = $this->originalme;
        baseapi::$my = null;
        baseapi::$ids = [];
        parent::tearDown();
    }

    /**
     * The API takes a course id and an optional book id.
     */
    public function test_execute_parameters(): void {
        $params = books::execute_parameters();

        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
        $this->assertSame(VALUE_DEFAULT, $params->keys['id']->required);
        $this->assertSame(0, $params->keys['id']->default);
    }

    /**
     * A course without books yields an empty list rather than an error.
     */
    public function test_execute_on_a_course_without_books(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], books::execute($course->id, 0));
    }

    /**
     * Each book is listed with its course module id, name, section and revision.
     */
    public function test_execute_describes_a_book(): void {
        $course = $this->getDataGenerator()->create_course(['numsections' => 1, 'format' => 'topics']);
        $book = $this->getDataGenerator()->create_module('book', [
            'course' => $course->id,
            'name' => 'Handbook',
            'section' => 1,
            'intro' => '<p>All you need to know.</p>',
            'introformat' => FORMAT_HTML,
        ]);
        $this->setAdminUser();

        $response = books::execute($course->id, 0);

        $this->assertCount(1, $response);
        $this->assertSame((int) $book->cmid, (int) $response[0]['id']);
        $this->assertSame('Handbook', $response[0]['name']);
        $this->assertSame((int) $course->id, (int) $response[0]['course_id']);
        $this->assertSame(get_section_name($course, 1), $response[0]['sectionname']);
        $this->assertStringContainsString('All you need to know.', $response[0]['description']);
        $this->assertSame([], $response[0]['descriptionfiles']);
        $this->assertArrayNotHasKey('chapters', $response[0]);
    }

    /**
     * A book in the general section is reported without a section name.
     */
    public function test_execute_reports_no_section_name_for_the_general_section(): void {
        $course = $this->getDataGenerator()->create_course(['format' => 'topics']);
        $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'section' => 0]);
        $this->setAdminUser();

        $response = books::execute($course->id, 0);

        $this->assertNull($response[0]['sectionname']);
    }

    /**
     * Asking for one book returns it on its own, with its chapters.
     */
    public function test_execute_inlines_chapters_for_a_single_book(): void {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Handbook']);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $first = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Opening', 'pagenum' => 1]);
        $second = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Closing', 'pagenum' => 2]);
        $this->setAdminUser();

        $response = books::execute($course->id, $book->cmid);

        $this->assertSame((int) $book->cmid, (int) $response['id']);
        $this->assertCount(2, $response['chapters']);
        $this->assertSame('Opening', $response['chapters'][$first->id]['title']);
        $this->assertSame('Closing', $response['chapters'][$second->id]['title']);
        $this->assertFalse((bool) $response['chapters'][$first->id]['hassubitems']);
    }

    /**
     * A subchapter is nested under the main chapter that precedes it.
     */
    public function test_execute_nests_subchapters(): void {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $main = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Main', 'pagenum' => 1]);
        $sub = $generator->create_chapter([
            'bookid' => $book->id,
            'title' => 'Sub',
            'pagenum' => 2,
            'subchapter' => 1,
        ]);
        $this->setAdminUser();

        $response = books::execute($course->id, $book->cmid);

        $this->assertTrue((bool) $response['chapters'][$main->id]['hassubitems']);
        $this->assertSame(1, $response['chapters'][$sub->id]['level']);
        $this->assertSame((int) $main->id, (int) $response['chapters'][$sub->id]['parentid']);
    }

    /**
     * A hidden chapter stays hidden from a user who may not see hidden chapters.
     */
    public function test_execute_hides_hidden_chapters_from_students(): void {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $visible = $generator->create_chapter(['bookid' => $book->id, 'title' => 'Shown', 'pagenum' => 1]);
        $hidden = $generator->create_chapter([
            'bookid' => $book->id,
            'title' => 'Hidden',
            'pagenum' => 2,
            'hidden' => 1,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $response = books::execute($course->id, $book->cmid);

        $this->assertArrayHasKey($visible->id, $response['chapters']);
        $this->assertArrayNotHasKey($hidden->id, $response['chapters']);
    }

    /**
     * A hidden chapter is still returned to a user who may view hidden chapters.
     */
    public function test_execute_shows_hidden_chapters_to_a_privileged_user(): void {
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_book');
        $generator->create_chapter(['bookid' => $book->id, 'title' => 'Shown', 'pagenum' => 1]);
        $hidden = $generator->create_chapter([
            'bookid' => $book->id,
            'title' => 'Hidden',
            'pagenum' => 2,
            'hidden' => 1,
        ]);
        $this->setAdminUser();

        $response = books::execute($course->id, $book->cmid);

        $this->assertArrayHasKey($hidden->id, $response['chapters']);
        $this->assertTrue((bool) $response['chapters'][$hidden->id]['hidden']);
    }

    /**
     * A user who may not read the book is refused rather than handed an empty response.
     */
    public function test_execute_refuses_a_single_book_the_user_cannot_read(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
        assign_capability(
            'mod/book:read',
            CAP_PROHIBIT,
            $roleid,
            context_module::instance($book->cmid)->id,
            true
        );
        $this->setUser($student);

        $this->expectException(moodle_exception::class);
        books::execute($course->id, $book->cmid);
    }

    /**
     * Chapters are only declared for the single book flavour.
     */
    public function test_single_structure_declares_chapters_only_when_pinned(): void {
        $this->assertArrayNotHasKey('chapters', books::single_structure()->keys);

        books::set_id(1);
        $structure = books::single_structure();

        $this->assertArrayHasKey('chapters', $structure->keys);
        $this->assertSame(VALUE_OPTIONAL, $structure->keys['chapters']->required);
    }

    /**
     * A chapter is described by its position in the book as well as its content.
     */
    public function test_chapter_response_structure(): void {
        $keys = array_keys(books::get_chapter_response_structure()->keys);

        $this->assertSame(
            ['id', 'title', 'level', 'hassubitems', 'hidden', 'content', 'parentid'],
            $keys
        );
    }

    /**
     * Moodle's own web service entry point always returns a list, so the chapters have to be
     * declared on the list entries instead of on a single book.
     */
    public function test_execute_returns_declares_chapters_on_the_native_endpoint(): void {
        global $ME;

        $ME = '/local/learnwise/api/index.php';
        $this->assertArrayNotHasKey('chapters', books::execute_returns()->content->keys);

        $ME = '/webservice/rest/server.php';
        $returns = books::execute_returns();

        $this->assertInstanceOf(external_multiple_structure::class, $returns);
        $this->assertArrayHasKey('chapters', $returns->content->keys);
        $this->assertSame(VALUE_OPTIONAL, $returns->content->keys['chapters']->required);
    }
}
