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
use calendar_event;
use calendar_information;

/**
 * Tests for the upcoming calendar events API.
 *
 * @covers     \local_learnwise\external\calenderdetails
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class calenderdetails_test extends advanced_testcase {
    /**
     * Reset the static state shared by every API class.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/calendar/lib.php');

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
     * Both scopes are optional, and the course defaults to the site.
     */
    public function test_execute_parameters_are_optional(): void {
        $params = calenderdetails::execute_parameters();

        $this->assertSame(VALUE_DEFAULT, $params->keys['courseid']->required);
        $this->assertSame((int) get_site()->id, (int) $params->keys['courseid']->default);
        $this->assertSame(NULL_NOT_ALLOWED, $params->keys['courseid']->allownull);
        $this->assertSame(VALUE_DEFAULT, $params->keys['categoryid']->required);
        $this->assertSame(NULL_ALLOWED, $params->keys['categoryid']->allownull);
    }

    /**
     * Event times are reported as ISO 8601.
     */
    public function test_times_are_declared_as_timestamps(): void {
        $this->assertSame(['timestart', 'timesort', 'timemodified'], calenderdetails::get_unixtimestamp_fields());

        $structure = calenderdetails::execute_returns()->content;

        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timestart']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timesort']);
    }

    /**
     * A user with an empty calendar gets an empty list rather than an error.
     */
    public function test_execute_with_no_events(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], calenderdetails::execute(get_site()->id, null));
    }

    /**
     * An upcoming personal event is reported.
     */
    public function test_execute_returns_an_upcoming_user_event(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_event([
            'name' => 'Study session',
            'eventtype' => 'user',
            'userid' => $user->id,
            'timestart' => time() + HOURSECS,
        ]);

        $response = calenderdetails::execute(get_site()->id, null);

        $this->assertCount(1, $response);
        $this->assertSame('Study session', $response[0]->name);
    }

    /**
     * An event that has already passed is not upcoming.
     */
    public function test_execute_ignores_past_events(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_event([
            'name' => 'Yesterday',
            'eventtype' => 'user',
            'userid' => $user->id,
            'timestart' => time() - DAYSECS,
        ]);

        $this->assertSame([], calenderdetails::execute(get_site()->id, null));
    }

    /**
     * An event beyond the look ahead window is not upcoming either.
     */
    public function test_execute_ignores_events_beyond_the_lookahead(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_user_preference('calendar_lookahead', 1, $user);
        $this->create_event([
            'name' => 'Next month',
            'eventtype' => 'user',
            'userid' => $user->id,
            'timestart' => time() + (30 * DAYSECS),
        ]);

        $this->assertSame([], calenderdetails::execute(get_site()->id, null));
    }

    /**
     * Asking for one course narrows the calendar to that course's events.
     */
    public function test_execute_narrows_to_a_single_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        $this->getDataGenerator()->enrol_user($user->id, $other->id);
        $this->setUser($user);
        $this->create_event([
            'name' => 'Wanted',
            'eventtype' => 'course',
            'courseid' => $course->id,
            'timestart' => time() + HOURSECS,
        ]);
        $this->create_event([
            'name' => 'Unwanted',
            'eventtype' => 'course',
            'courseid' => $other->id,
            'timestart' => time() + HOURSECS,
        ]);

        $response = calenderdetails::execute($course->id, null);

        $names = [];
        foreach ($response as $event) {
            $names[] = $event->name;
        }
        $this->assertSame(['Wanted'], $names);
    }

    /**
     * The look ahead window can be widened by the caller.
     */
    public function test_calendar_get_events_honours_an_explicit_lookahead(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        set_user_preference('calendar_lookahead', 1, $user);
        $this->create_event([
            'name' => 'In ten days',
            'eventtype' => 'user',
            'userid' => $user->id,
            'timestart' => time() + (10 * DAYSECS),
        ]);
        $calendar = calendar_information::create(time(), get_site()->id, null);

        $this->assertSame([], calenderdetails::calendar_get_events($calendar)->events);
        $this->assertCount(1, calenderdetails::calendar_get_events($calendar, 30)->events);
    }

    /**
     * Create a calendar event.
     *
     * Core ships no calendar test generator, so the event goes through the calendar API.
     *
     * @param array $record Event fields
     * @return calendar_event
     */
    protected function create_event(array $record) {
        $event = (object) ($record + [
            'description' => '',
            'format' => FORMAT_HTML,
            'courseid' => 0,
            'groupid' => 0,
            'userid' => 0,
            'modulename' => 0,
            'instance' => 0,
            'timeduration' => 0,
            'visible' => 1,
            'type' => CALENDAR_EVENT_TYPE_STANDARD,
        ]);
        $event->timesort = $event->timestart;
        return calendar_event::create($event, false);
    }
}
