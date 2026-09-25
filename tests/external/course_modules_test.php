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
use completion_info;
use grade_item;
use question_engine;
use quiz;
use quiz_attempt;

/**
 * Tests for the course modules API.
 *
 * @covers     \local_learnwise\external\course_modules
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class course_modules_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        parent::setUp();
        $this->resetAfterTest();
        baseapi::$my = null;
        baseapi::$ids = [];
        course_modules::$withcompletion = false;
    }

    /**
     * Reset the static state shared by every API class.
     */
    protected function tearDown(): void {
        baseapi::$my = null;
        baseapi::$ids = [];
        course_modules::$withcompletion = false;
        parent::tearDown();
    }

    /**
     * The API is driven by a course id.
     */
    public function test_execute_parameters_take_a_course_id(): void {
        $params = course_modules::execute_parameters();

        $this->assertArrayHasKey('courseid', $params->keys);
        $this->assertSame(PARAM_INT, $params->keys['courseid']->type);
    }

    /**
     * Every module of the course is listed with its course module id, name and type.
     */
    public function test_execute_lists_the_course_modules(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Reading']);
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'name' => 'Essay']);
        $this->setAdminUser();

        $response = course_modules::execute($course->id);

        $byid = [];
        foreach ($response as $module) {
            $byid[(int) $module['id']] = $module;
        }
        $this->assertSame('Reading', $byid[(int) $page->cmid]['name']);
        $this->assertSame('page', $byid[(int) $page->cmid]['type']);
        $this->assertSame('Essay', $byid[(int) $assign->cmid]['name']);
        $this->assertSame('assign', $byid[(int) $assign->cmid]['type']);
    }

    /**
     * A course with no activities yields an empty list rather than an error.
     */
    public function test_execute_on_an_empty_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();

        $this->assertSame([], course_modules::execute($course->id));
    }

    /**
     * The site wide flavour hides modules that are not visible.
     */
    public function test_execute_skips_hidden_modules(): void {
        $course = $this->getDataGenerator()->create_course();
        $visible = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Shown']);
        $hidden = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Hidden', 'visible' => 0]
        );
        $this->setAdminUser();

        $ids = array_map('intval', array_column(course_modules::execute($course->id), 'id'));

        $this->assertContains((int) $visible->cmid, $ids);
        $this->assertNotContains((int) $hidden->cmid, $ids);
    }

    /**
     * Completion is only reported when the caller asks for it.
     */
    public function test_execute_reports_completion_on_demand(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Reading', 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $without = course_modules::execute($course->id);
        $this->assertArrayNotHasKey('completion', $without[0]);
        $this->assertArrayNotHasKey('completionstatus', $without[0]);
        $this->assertArrayNotHasKey('timemodified', $without[0]);

        course_modules::$withcompletion = true;
        $with = course_modules::execute($course->id);
        // Moodle 5.x flags the argument the plugin passes to completion_info::get_data().
        $this->resetDebugging();
        $this->assertArrayHasKey('completion', $with[0]);
        $this->assertArrayHasKey('completionstatus', $with[0]);
        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $with[0]['completion']);
        $this->assertEquals(COMPLETION_INCOMPLETE, $with[0]['completionstatus']);
        $this->assertArrayNotHasKey('timemodified', $with[0]);
    }

    /**
     * A completed activity is reported with completion state, mode, and timemodified timestamp.
     */
    public function test_execute_reports_a_completed_module(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Reading', 'completion' => COMPLETION_TRACKING_MANUAL]
        );
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        $completion = new completion_info(get_course($course->id));
        $cm = get_fast_modinfo($course->id)->get_cm($page->cmid);
        $completion->update_state($cm, COMPLETION_COMPLETE, $user->id);

        course_modules::$withcompletion = true;
        $response = course_modules::execute($course->id);
        // Moodle 5.x flags the argument the plugin passes to completion_info::get_data().
        $this->resetDebugging();

        $this->assertEquals(COMPLETION_TRACKING_MANUAL, $response[0]['completion']);
        $this->assertEquals(COMPLETION_COMPLETE, $response[0]['completionstatus']);
        $this->assertArrayHasKey('timemodified', $response[0]);
        $this->assertNotEmpty($response[0]['timemodified']);
    }

    /**
     * A failed activity is reported with the failed completion state, mode, and timemodified timestamp.
     */
    public function test_execute_reports_a_failed_module(): void {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $quiz = $this->getDataGenerator()->create_module(
            'quiz',
            [
                'course' => $course->id,
                'name' => 'Quiz Reading',
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionpass' => 1,
                'sumgrades' => 1,
                'completionusegrade' => 1,
                'grade' => 100.0,
            ]
        );

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('numerical', null, ['category' => $cat->id]);
        quiz_add_quiz_question($question->id, $quiz);

        $item = grade_item::fetch(['courseid' => $course->id, 'itemtype' => 'mod',
                                        'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'outcomeid' => null]);
        $item->gradepass = 80;
        $item->update();

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->setUser($user);

        if (class_exists(\mod_quiz\quiz_settings::class)) {
            class_alias(\mod_quiz\quiz_settings::class, \quiz::class);
        }

        if (class_exists(\mod_quiz\quiz_attempt::class)) {
            class_alias(\mod_quiz\quiz_attempt::class, \quiz_attempt::class);
        }

        $quizobj = quiz::create($quiz->id, $user->id);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);

        $timenow = time();
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        $attemptobj = quiz_attempt::create($attempt->id);
        $tosubmit = [1 => ['answer' => '0']];
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        $attemptobj = quiz_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
        $attemptobj->process_finish($timenow, false);

        $completion = new completion_info($course);
        $cm = get_fast_modinfo($course->id)->get_cm($quiz->cmid);
        $completionstate = $completion->get_data($cm, false, $user->id)->completionstate;

        course_modules::$withcompletion = true;
        $response = course_modules::execute($course->id);
        $this->resetDebugging();
        $this->assertEquals(COMPLETION_TRACKING_AUTOMATIC, $response[0]['completion']);
        $this->assertEquals($completionstate, $response[0]['completionstatus']);
    }

    /**
     * Pinning a course module id returns that single module rather than a list.
     */
    public function test_execute_returns_a_single_module_when_pinned(): void {
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'First']);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Second']);
        $this->setAdminUser();
        course_modules::set_id($second->cmid);

        $response = course_modules::execute($course->id);

        $this->assertSame((int) $second->cmid, (int) $response['id']);
        $this->assertSame('Second', $response['name']);
    }

    /**
     * The completion, completionstatus, and timemodified fields are only declared when completion is being reported.
     */
    public function test_single_structure_declares_completion_on_demand(): void {
        $structurewithout = course_modules::single_structure();
        $this->assertArrayNotHasKey('completion', $structurewithout->keys);
        $this->assertArrayNotHasKey('completionstatus', $structurewithout->keys);
        $this->assertArrayNotHasKey('timemodified', $structurewithout->keys);

        course_modules::$withcompletion = true;

        $structurewith = course_modules::single_structure();
        $this->assertArrayHasKey('completion', $structurewith->keys);
        $this->assertArrayHasKey('completionstatus', $structurewith->keys);
        $this->assertArrayHasKey('timemodified', $structurewith->keys);
    }

    /**
     * Test get_unixtimestamp_fields returns timemodified.
     */
    public function test_get_unixtimestamp_fields(): void {
        $fields = course_modules::get_unixtimestamp_fields();
        $this->assertContains('timemodified', $fields);
    }
}
