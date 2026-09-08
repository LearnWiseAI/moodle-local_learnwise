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

namespace local_learnwise\task;

use local_learnwise\storage;
use local_learnwise\util;

/**
 * Tests for the expired token cleanup task.
 *
 * @covers     \local_learnwise\task\cleanup_tokens
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_tokens_test extends \advanced_testcase {
    /** @var storage */
    protected $storage;

    /** @var \stdClass */
    protected $client;

    /** @var \stdClass */
    protected $user;

    /**
     * Prepare a client and user to hang tokens off.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->storage = new storage();
        $this->client = util::get_or_generate_client();
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * The task reports a translated name.
     */
    public function test_get_name(): void {
        $this->assertSame(get_string('cleanuptokentask', 'local_learnwise'), (new cleanup_tokens())->get_name());
    }

    /**
     * Expired tokens and codes are purged.
     */
    public function test_expired_records_are_deleted(): void {
        $past = time() - 3600;

        $this->storage->setAccessToken('expired-access', $this->client->uniqid, $this->user->id, $past);
        $this->storage->setRefreshToken('expired-refresh', $this->client->uniqid, $this->user->id, $past);
        $this->storage->setAuthorizationCode(
            'expired-code',
            $this->client->uniqid,
            $this->user->id,
            'https://example.test/cb',
            $past
        );

        (new cleanup_tokens())->execute();

        $this->assertFalse($this->storage->getAccessToken('expired-access'));
        $this->assertFalse($this->storage->getRefreshToken('expired-refresh'));
        $this->assertFalse($this->storage->getAuthorizationCode('expired-code'));
    }

    /**
     * Tokens that are still valid survive the cleanup.
     */
    public function test_valid_records_are_kept(): void {
        $future = time() + 3600;

        $this->storage->setAccessToken('live-access', $this->client->uniqid, $this->user->id, $future);
        $this->storage->setRefreshToken('live-refresh', $this->client->uniqid, $this->user->id, $future);
        $this->storage->setAuthorizationCode(
            'live-code',
            $this->client->uniqid,
            $this->user->id,
            'https://example.test/cb',
            $future
        );

        (new cleanup_tokens())->execute();

        $this->assertNotFalse($this->storage->getAccessToken('live-access'));
        $this->assertNotFalse($this->storage->getRefreshToken('live-refresh'));
        $this->assertNotFalse($this->storage->getAuthorizationCode('live-code'));
    }

    /**
     * Only the expired half of a mixed set is removed.
     */
    public function test_only_expired_records_are_removed(): void {
        global $DB;

        $this->storage->setAccessToken('expired-access', $this->client->uniqid, $this->user->id, time() - 1);
        $this->storage->setAccessToken('live-access', $this->client->uniqid, $this->user->id, time() + 3600);

        (new cleanup_tokens())->execute();

        $this->assertSame(1, $DB->count_records('local_learnwise_accesstoken'));
        $this->assertNotFalse($this->storage->getAccessToken('live-access'));
    }

    /**
     * The user authorisation rows themselves are left in place.
     */
    public function test_userauth_rows_survive(): void {
        global $DB;

        $this->storage->setAccessToken('expired-access', $this->client->uniqid, $this->user->id, time() - 1);
        (new cleanup_tokens())->execute();

        $this->assertSame(1, $DB->count_records('local_learnwise_userauth'));
    }

    /**
     * Running the task against an empty database is harmless.
     */
    public function test_execute_with_nothing_to_clean(): void {
        global $DB;

        (new cleanup_tokens())->execute();

        $this->assertSame(0, $DB->count_records('local_learnwise_accesstoken'));
    }
}
