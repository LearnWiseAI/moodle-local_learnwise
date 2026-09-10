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

namespace local_learnwise\external\quiz;

use advanced_testcase;
use context_module;
use external_multiple_structure;
use external_single_structure;
use local_learnwise\external\baseapi;
use moodle_exception;
use question_engine;
use stdClass;
use test_question_maker;

/**
 * Tests for the quiz attempt review API.
 *
 * @covers     \local_learnwise\external\quiz\reviewattempt
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class reviewattempt_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/engine/tests/helpers.php');

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
     * The API is driven by the course, the quiz and the attempt.
     */
    public function test_execute_parameters(): void {
        $params = reviewattempt::execute_parameters();

        $this->assertSame(['courseid', 'quizid', 'attemptid'], array_keys($params->keys));
        foreach ($params->keys as $key) {
            $this->assertSame(PARAM_INT, $key->type);
        }
    }

    /**
     * The API always answers with one review rather than a list.
     */
    public function test_it_is_always_a_single_operation(): void {
        $this->assertTrue(reviewattempt::is_singleoperation());
        $this->assertInstanceOf(external_single_structure::class, reviewattempt::execute_returns());
    }

    /**
     * A review is a grade plus the questions that made it up.
     */
    public function test_single_structure_is_a_grade_and_its_questions(): void {
        $structure = reviewattempt::single_structure();

        $this->assertSame(['grade', 'questions'], array_keys($structure->keys));
        $this->assertSame(PARAM_FLOAT, $structure->keys['grade']->type);
        $this->assertInstanceOf(external_multiple_structure::class, $structure->keys['questions']);
    }

    /**
     * Every field of a reviewed question is optional, since what is shown depends on the
     * quiz's own review settings.
     */
    public function test_single_structure_questions_are_all_optional(): void {
        $question = reviewattempt::single_structure()->keys['questions']->content;

        $this->assertSame(
            ['question', 'response', 'mark', 'maxmark', 'feedback', 'comment', 'correctness', 'status', 'questiontype'],
            array_keys($question->keys)
        );
        foreach ($question->keys as $key => $value) {
            $this->assertSame(VALUE_OPTIONAL, $value->required, "Key {$key} should be optional");
        }
    }

    /**
     * Reviewing an attempt reports the rescaled grade.
     */
    public function test_execute_reports_the_rescaled_grade(): void {
        [$course, $quiz, $user] = $this->create_quiz(['grade' => 100, 'sumgrades' => 10]);
        $attempt = $this->create_attempt($quiz, $user, 6.0);
        $this->setUser($user);

        $response = reviewattempt::execute($course->id, $quiz->cmid, $attempt->id);

        $this->assertEquals(60.0, $response['grade'], '', 0.001);
        $this->assertSame([], $response['questions']);
    }

    /**
     * A user with no access to the quiz at all is refused.
     */
    public function test_execute_refuses_a_user_outside_the_course(): void {
        [$course, $quiz, $user] = $this->create_quiz(['grade' => 100, 'sumgrades' => 10]);
        $attempt = $this->create_attempt($quiz, $user, 6.0);
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(moodle_exception::class);
        reviewattempt::execute($course->id, $quiz->cmid, $attempt->id);
    }

    /**
     * Documents current behaviour: the API guards the review with
     * quiz_attempt::check_review_capability() only, which asks whether the caller may review
     * their own attempts. It never asks whose attempt this is, so any student enrolled on the
     * course can read another student's review. Moodle's own mod/quiz/review.php adds an
     * is_review_allowed() check for attempts the caller does not own.
     */
    public function test_execute_does_not_check_who_owns_the_attempt(): void {
        [$course, $quiz, $user] = $this->create_quiz(['grade' => 100, 'sumgrades' => 10]);
        $attempt = $this->create_attempt($quiz, $user, 6.0);
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->setUser($other);

        $response = reviewattempt::execute($course->id, $quiz->cmid, $attempt->id);

        $this->assertEquals(60.0, $response['grade'], '', 0.001);
    }

    /**
     * An unknown attempt is rejected.
     */
    public function test_execute_rejects_an_unknown_attempt(): void {
        [$course, $quiz, $user] = $this->create_quiz();
        $this->setUser($user);

        $this->expectException(moodle_exception::class);
        reviewattempt::execute($course->id, $quiz->cmid, -1);
    }

    /**
     * Build a course with a quiz and an enrolled user.
     *
     * @param array $options Extra quiz settings
     * @return array
     */
    protected function create_quiz(array $options = []) {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', $options + ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        return [$course, $quiz, $user];
    }

    /**
     * Record a finished quiz attempt directly, so the test does not depend on the question engine.
     *
     * @param stdClass $quiz Quiz the attempt belongs to
     * @param stdClass $user User making the attempt
     * @param float $sumgrades Raw marks earned
     * @return stdClass
     */
    protected function create_attempt($quiz, $user, $sumgrades) {
        global $DB;

        $context = context_module::instance($quiz->cmid);
        $quba = question_engine::make_questions_usage_by_activity('mod_quiz', $context);
        $quba->set_preferred_behaviour('deferredfeedback');
        $slot = $quba->add_question(test_question_maker::make_question('truefalse', 'true'));
        $quba->get_question_attempt($slot)->start('deferredfeedback', 1);
        question_engine::save_questions_usage_by_activity($quba);

        $attempt = (object) [
            'quiz' => $quiz->id,
            'userid' => $user->id,
            'uniqueid' => $quba->get_id(),
            'attempt' => 1,
            'layout' => '',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'finished',
            'timestart' => time() - HOURSECS,
            'timefinish' => time() - (HOURSECS / 2),
            'timemodified' => time(),
            'sumgrades' => $sumgrades,
        ];
        $attempt->id = $DB->insert_record('quiz_attempts', $attempt);
        return $attempt;
    }
}
