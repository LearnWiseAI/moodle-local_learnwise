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

namespace local_learnwise\local\courseselector;

use advanced_testcase;

/**
 * Tests for the course selectors that read the configured course id list.
 *
 * @covers     \local_learnwise\local\courseselector\potential_course_selector
 * @covers     \local_learnwise\local\courseselector\current_course_selector
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class potential_course_selector_test extends advanced_testcase {
    /**
     * The course ids a selector offered, as a flat sorted list.
     *
     * @param array $result Return value of find_courses().
     * @return int[]
     */
    protected function found_ids(array $result): array {
        $ids = [];
        foreach ($result as $courses) {
            foreach ($courses as $course) {
                $ids[] = (int) $course->id;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * The selector that offers courses to add leaves out the ones already configured.
     */
    public function test_potential_selector_excludes_configured_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $one = $generator->create_course();
        $two = $generator->create_course();
        $three = $generator->create_course();

        set_config('courseids', $one->id . ',' . $three->id, 'local_learnwise');

        $selector = new potential_course_selector('potential_courses');
        $this->assertSame([(int) $two->id], $this->found_ids($selector->find_courses('')));
    }

    /**
     * The selector that lists configured courses offers exactly those.
     */
    public function test_current_selector_returns_the_configured_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $one = $generator->create_course();
        $two = $generator->create_course();
        $generator->create_course();

        set_config('courseids', $one->id . ',' . $two->id, 'local_learnwise');

        $selector = new current_course_selector('current_courses');
        $expected = [(int) $one->id, (int) $two->id];
        sort($expected);
        $this->assertSame($expected, $this->found_ids($selector->find_courses('')));
    }

    /**
     * Empty segments and stray whitespace in the stored value do not drop a real course id.
     */
    public function test_untidy_config_values_still_resolve(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $one = $generator->create_course();
        $generator->create_course();

        set_config('courseids', ' ' . $one->id . ' ,,', 'local_learnwise');

        $selector = new current_course_selector('current_courses');
        $this->assertSame([(int) $one->id], $this->found_ids($selector->find_courses('')));
    }

    /**
     * A config value that is not a course id list cannot reach the query.
     *
     * The ids used to be concatenated into the SQL, so a stored value carrying SQL of its own would
     * have been executed. They are bound as parameters now, and anything that is not a positive
     * integer is dropped before that.
     */
    public function test_a_poisoned_config_value_is_not_executed(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $one = $generator->create_course();
        $two = $generator->create_course();

        set_config('courseids', "'; DROP TABLE {course}; --", 'local_learnwise');

        // Nothing usable is left, so every course is still on offer and the site is intact.
        $selector = new potential_course_selector('potential_courses');
        $expected = [(int) $one->id, (int) $two->id];
        sort($expected);
        $this->assertSame($expected, $this->found_ids($selector->find_courses('')));
        $this->assertTrue($DB->record_exists('course', ['id' => $one->id]));

        // The same value offers no configured courses rather than matching them all.
        $currentselector = new current_course_selector('current_courses');
        $this->assertSame([], $this->found_ids($currentselector->find_courses('')));
    }

    /**
     * A trailing SQL fragment on a real course id is dropped rather than widening the match.
     */
    public function test_an_injected_fragment_on_a_real_id_is_dropped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $one = $generator->create_course();
        $two = $generator->create_course();

        set_config('courseids', $one->id . ') OR (1=1', 'local_learnwise');

        // Only the course the id names comes back, not everything the fragment would have matched.
        $selector = new current_course_selector('current_courses');
        $this->assertSame([(int) $one->id], $this->found_ids($selector->find_courses('')));

        $potentialselector = new potential_course_selector('potential_courses');
        $this->assertSame([(int) $two->id], $this->found_ids($potentialselector->find_courses('')));
    }

    /**
     * With nothing configured, every visible course can be added and none can be removed.
     */
    public function test_no_configured_courses(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $selector = new potential_course_selector('potential_courses');
        $this->assertSame([(int) $course->id], $this->found_ids($selector->find_courses('')));

        $currentselector = new current_course_selector('current_courses');
        $this->assertSame([], $this->found_ids($currentselector->find_courses('')));
    }

    /**
     * Hidden courses are never offered, whether or not they are configured.
     */
    public function test_hidden_courses_are_not_offered(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $hidden = $generator->create_course(['visible' => 0]);
        $visible = $generator->create_course();

        set_config('courseids', $hidden->id, 'local_learnwise');

        $selector = new current_course_selector('current_courses');
        $this->assertSame([], $this->found_ids($selector->find_courses('')));

        $potentialselector = new potential_course_selector('potential_courses');
        $this->assertSame([(int) $visible->id], $this->found_ids($potentialselector->find_courses('')));
    }
}
