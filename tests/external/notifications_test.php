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
use core\message\message;
use external_multiple_structure;

/**
 * Tests for the notifications API.
 *
 * @covers     \local_learnwise\external\notifications
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @runTestsInSeparateProcesses
 */
final class notifications_test extends advanced_testcase {
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
     * Unlike Moodle's own popup notification service, the recipient defaults to the caller.
     */
    public function test_execute_parameters_default_the_recipient(): void {
        $params = notifications::execute_parameters();

        $this->assertSame(VALUE_DEFAULT, $params->keys['useridto']->required);
        $this->assertSame(0, $params->keys['useridto']->default);
        $this->assertArrayHasKey('newestfirst', $params->keys);
        $this->assertArrayHasKey('limit', $params->keys);
        $this->assertArrayHasKey('offset', $params->keys);
    }

    /**
     * The wrapping "notifications" envelope is unwrapped into a plain list.
     */
    public function test_execute_returns_a_bare_list(): void {
        $structure = notifications::execute_returns();

        $this->assertInstanceOf(external_multiple_structure::class, $structure);
        $this->assertArrayHasKey('subject', $structure->content->keys);
        $this->assertArrayHasKey('timecreated', $structure->content->keys);
    }

    /**
     * Notification times are reported as ISO 8601.
     */
    public function test_times_are_declared_as_timestamps(): void {
        $this->assertSame(['timecreated', 'timeread'], notifications::get_unixtimestamp_fields());

        $structure = notifications::execute_returns()->content;

        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timecreated']);
        $this->assertInstanceOf(timestampvalue::class, $structure->keys['timeread']);
    }

    /**
     * A user with an empty inbox gets an empty list rather than an error.
     */
    public function test_execute_with_no_notifications(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->assertSame([], notifications::execute(0, true, 0, 0));
    }

    /**
     * Notifications sent to the caller are returned.
     */
    public function test_execute_returns_the_callers_notifications(): void {
        $this->preventResetByRollback();
        $sender = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $this->send_notification($sender, $recipient, 'Assignment graded');
        $this->setUser($recipient);

        $response = notifications::execute(0, true, 0, 0);

        $this->assertCount(1, $response);
        $this->assertSame('Assignment graded', $response[0]->subject);
        $this->assertSame((int) $recipient->id, (int) $response[0]->useridto);
    }

    /**
     * The limit is honoured.
     */
    public function test_execute_honours_the_limit(): void {
        $this->preventResetByRollback();
        $sender = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $this->send_notification($sender, $recipient, 'First');
        $this->send_notification($sender, $recipient, 'Second');
        $this->setUser($recipient);

        $this->assertCount(1, notifications::execute(0, true, 1, 0));
        $this->assertCount(2, notifications::execute(0, true, 0, 0));
    }

    /**
     * Send one notification between two users.
     *
     * @param stdClass $from Sending user
     * @param stdClass $to Receiving user
     * @param string $subject Notification subject
     * @return void
     */
    protected function send_notification($from, $to, $subject) {
        $message = new message();
        $message->component = 'moodle';
        $message->name = 'instantmessage';
        $message->userfrom = $from;
        $message->userto = $to;
        $message->subject = $subject;
        $message->fullmessage = $subject;
        $message->fullmessageformat = FORMAT_MOODLE;
        $message->fullmessagehtml = '<p>' . $subject . '</p>';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->courseid = SITEID;
        message_send($message);
    }
}
