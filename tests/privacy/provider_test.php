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

namespace local_learnwise\privacy;

use context_course;
use context_system;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_learnwise\storage;
use local_learnwise\util;
use stdClass;

/**
 * Tests for the plugin's GDPR privacy provider.
 *
 * @covers     \local_learnwise\privacy\provider
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var storage */
    protected $storage;

    /** @var stdClass */
    protected $client;

    /** @var stdClass */
    protected $usera;

    /** @var stdClass */
    protected $userb;

    /**
     * Give two users a full set of OAuth2 artefacts.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->storage = new storage();
        $this->client = util::get_or_generate_client();
        $this->usera = $this->getDataGenerator()->create_user();
        $this->userb = $this->getDataGenerator()->create_user();

        $this->seed_tokens($this->usera, 'a');
        $this->seed_tokens($this->userb, 'b');
    }

    /**
     * Create an access token, refresh token and authorization code for a user.
     *
     * @param stdClass $user The owning user
     * @param string $suffix Unique suffix for the generated token values
     */
    protected function seed_tokens(stdClass $user, string $suffix): void {
        $expires = time() + 3600;
        $this->storage->setAccessToken("access-{$suffix}", $this->client->uniqid, $user->id, $expires);
        $this->storage->setRefreshToken("refresh-{$suffix}", $this->client->uniqid, $user->id, $expires);
        $this->storage->setAuthorizationCode(
            "code-{$suffix}",
            $this->client->uniqid,
            $user->id,
            'https://example.test/cb',
            $expires
        );
    }

    /**
     * The provider declares the tables and external locations it touches.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_learnwise'));
        $items = $collection->get_collection();

        $this->assertNotEmpty($items);
    }

    /**
     * A user's own context is reported as holding their data.
     */
    public function test_get_contexts_for_userid(): void {
        $contextlist = provider::get_contexts_for_userid($this->usera->id);
        $contextids = $contextlist->get_contextids();

        $this->assertCount(1, $contextids);
        $this->assertEquals(context_user::instance($this->usera->id)->id, reset($contextids));
    }

    /**
     * A user with no plugin data has no contexts.
     */
    public function test_get_contexts_for_userid_without_data(): void {
        $stranger = $this->getDataGenerator()->create_user();

        $this->assertCount(0, provider::get_contexts_for_userid($stranger->id)->get_contextids());
    }

    /**
     * Users holding plugin data are discoverable from a user context.
     *
     * The stored column is "userid"; querying "user_id" would fail outright, and rejecting
     * user contexts would leave the userlist deletion path unable to find anyone.
     */
    public function test_get_users_in_context_for_user_context(): void {
        $context = context_user::instance($this->usera->id);
        $userlist = new userlist($context, 'local_learnwise');

        provider::get_users_in_context($userlist);

        $this->assertSame([(int) $this->usera->id], array_map('intval', $userlist->get_userids()));
    }

    /**
     * The system context does not own user authorizations.
     */
    public function test_get_users_in_context_for_system_context(): void {
        $userlist = new userlist(context_system::instance(), 'local_learnwise');

        provider::get_users_in_context($userlist);

        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * Contexts the plugin stores nothing against yield no users.
     */
    public function test_get_users_in_context_ignores_other_context_levels(): void {
        $course = $this->getDataGenerator()->create_course();
        $userlist = new userlist(context_course::instance($course->id), 'local_learnwise');

        provider::get_users_in_context($userlist);

        $this->assertEmpty($userlist->get_userids());
    }

    /**
     * Exporting a user's data writes token metadata under the plugin subcontext.
     */
    public function test_export_user_data(): void {
        $context = context_user::instance($this->usera->id);
        $contextlist = new approved_contextlist($this->usera, 'local_learnwise', [$context->id]);

        provider::export_user_data($contextlist);

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * The client id is redacted rather than exported.
     */
    public function test_export_user_data_redacts_client_id(): void {
        $context = context_user::instance($this->usera->id);
        $contextlist = new approved_contextlist($this->usera, 'local_learnwise', [$context->id]);

        provider::export_user_data($contextlist);

        global $DB;
        $authid = $DB->get_field('local_learnwise_userauth', 'id', ['userid' => $this->usera->id]);
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_learnwise'), (string) $authid]);
        $this->assertSame(get_string('privacy:request:notexportedsecurity', 'local_learnwise'), $data->clientid);
        $this->assertNotEquals($this->client->id, $data->clientid);
    }

    /**
     * Export only credential presence and expiry, without changing stored OAuth credentials.
     */
    public function test_export_only_includes_token_metadata(): void {
        global $DB;
        $authid = $DB->get_field('local_learnwise_userauth', 'id', ['userid' => $this->usera->id]);
        $DB->set_field('local_learnwise_authcode', 'codechallenge', 'private-pkce-challenge', ['authid' => $authid]);
        $DB->set_field('local_learnwise_authcode', 'codechallengemethod', 'S256', ['authid' => $authid]);
        $tables = [
            'authcodes' => 'local_learnwise_authcode',
            'accesstokens' => 'local_learnwise_accesstoken',
            'refreshtokens' => 'local_learnwise_refreshtoken',
        ];
        $before = [];
        foreach ($tables as $property => $table) {
            $before[$property] = $DB->get_records($table);
        }
        $context = context_user::instance($this->usera->id);
        provider::export_user_data(new approved_contextlist($this->usera, 'local_learnwise', [$context->id]));
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_learnwise'), (string) $authid]);
        foreach ($tables as $property => $table) {
            $this->assertCount(1, $data->$property);
            foreach ($data->$property as $item) {
                $this->assertSame(['id', 'timeexpiry'], array_keys((array) $item));
                $record = $before[$property][$item->id];
                $this->assertEquals($authid, $record->authid);
                $this->assertSame(\core_privacy\local\request\transform::datetime($record->timeexpiry), $item->timeexpiry);
            }
            $this->assertEquals($before[$property], $DB->get_records($table));
        }
        $this->assert_tokens_exist('a', true);
        $this->assert_tokens_exist('b', true);
    }

    /**
     * An empty context list exports nothing.
     */
    public function test_export_user_data_with_empty_contextlist(): void {
        $context = context_user::instance($this->usera->id);
        $contextlist = new approved_contextlist($this->usera, 'local_learnwise', []);

        provider::export_user_data($contextlist);

        $this->assertFalse(writer::with_context($context)->has_any_data());
    }

    /**
     * Deleting one user's data leaves other users untouched.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        $context = context_user::instance($this->usera->id);
        $contextlist = new approved_contextlist($this->usera, 'local_learnwise', [$context->id]);

        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('local_learnwise_userauth', ['userid' => $this->usera->id]));
        $this->assertSame(1, $DB->count_records('local_learnwise_userauth', ['userid' => $this->userb->id]));
    }

    /**
     * Deleting a user's data removes their tokens and codes, not just the auth row.
     */
    public function test_delete_data_for_user_cascades_to_tokens(): void {
        global $DB;

        $context = context_user::instance($this->usera->id);
        provider::delete_data_for_user(new approved_contextlist($this->usera, 'local_learnwise', [$context->id]));

        $this->assertFalse($this->storage->getAccessToken('access-a'));
        $this->assertFalse($this->storage->getRefreshToken('refresh-a'));
        $this->assertFalse($this->storage->getAuthorizationCode('code-a'));

        // User B keeps everything.
        $this->assertNotFalse($this->storage->getAccessToken('access-b'));
        $this->assertSame(1, $DB->count_records('local_learnwise_accesstoken'));
        $this->assertSame(1, $DB->count_records('local_learnwise_refreshtoken'));
        $this->assertSame(1, $DB->count_records('local_learnwise_authcode'));
    }

    /**
     * The approved userlist deletion path removes only the approved users.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $context = context_user::instance($this->usera->id);
        $userlist = new approved_userlist($context, 'local_learnwise', [$this->usera->id]);

        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $DB->count_records('local_learnwise_userauth', ['userid' => $this->usera->id]));
        $this->assertSame(1, $DB->count_records('local_learnwise_userauth', ['userid' => $this->userb->id]));
        $this->assertNotFalse($this->storage->getAccessToken('access-b'));
    }

    /**
     * An empty approved userlist deletes nothing.
     */
    public function test_delete_data_for_users_with_no_users(): void {
        global $DB;

        $context = context_user::instance($this->usera->id);
        provider::delete_data_for_users(new approved_userlist($context, 'local_learnwise', []));

        $this->assertSame(2, $DB->count_records('local_learnwise_userauth'));
    }

    /**
     * A non-user context is ignored by the userlist deletion path.
     */
    public function test_delete_data_for_users_ignores_other_contexts(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $userlist = new approved_userlist(
            context_course::instance($course->id),
            'local_learnwise',
            [$this->usera->id]
        );

        provider::delete_data_for_users($userlist);

        $this->assertSame(2, $DB->count_records('local_learnwise_userauth'));
    }

    /**
     * Deleting a user context revokes only its owner's tokens.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        provider::delete_data_for_all_users_in_context(context_user::instance($this->usera->id));
        $this->assert_tokens_exist('a', false);
        $this->assert_tokens_exist('b', true);
    }

    /**
     * Empty, foreign-user, system and course contexts must not authorize erasure or export.
     */
    public function test_unrelated_contexts_do_not_delete_or_export(): void {
        $course = $this->getDataGenerator()->create_course();
        $contexts = [context_system::instance(), context_course::instance($course->id),
            context_user::instance($this->userb->id)];
        foreach (
            array_merge([[]], array_map(function ($context) {
                return [$context->id];
            }, $contexts)) as $ids
        ) {
            $approved = new approved_contextlist($this->usera, 'local_learnwise', $ids);
            provider::delete_data_for_user($approved);
            provider::export_user_data($approved);
            $this->assert_tokens_exist('a', true);
            $this->assert_tokens_exist('b', true);
        }
        foreach ($contexts as $context) {
            $this->assertFalse(writer::with_context($context)->has_any_data());
            if (!$context instanceof context_user) {
                provider::delete_data_for_all_users_in_context($context);
                provider::delete_data_for_users(new approved_userlist(
                    $context,
                    'local_learnwise',
                    [$this->usera->id, $this->userb->id]
                ));
                $this->assert_tokens_exist('a', true);
                $this->assert_tokens_exist('b', true);
            }
        }
    }

    /**
     * Approval of another user cannot erase data belonging to either context owner.
     */
    public function test_userlist_only_deletes_the_approved_context_owner(): void {
        $context = context_user::instance($this->usera->id);
        provider::delete_data_for_users(new approved_userlist($context, 'local_learnwise', [$this->userb->id]));
        $this->assert_tokens_exist('a', true);
        $this->assert_tokens_exist('b', true);
        provider::delete_data_for_users(new approved_userlist(
            $context,
            'local_learnwise',
            [$this->usera->id, $this->userb->id]
        ));
        $this->assert_tokens_exist('a', false);
        $this->assert_tokens_exist('b', true);
    }

    /**
     * Each client authorization is exported separately with credentials redacted, then erased together.
     */
    public function test_multiple_clients_are_exported_and_deleted_without_affecting_other_users(): void {
        global $DB;
        $this->client = (object) ['uniqid' => 'second-client', 'secret' => 'second-secret'];
        $this->client->id = $DB->insert_record('local_learnwise_clients', $this->client);
        $this->seed_tokens($this->usera, 'a2');
        $context = context_user::instance($this->usera->id);
        provider::export_user_data(new approved_contextlist($this->usera, 'local_learnwise', [$context->id]));
        $auths = $DB->get_records('local_learnwise_userauth', ['userid' => $this->usera->id]);
        $this->assertCount(2, $auths);
        $redacted = get_string('privacy:request:notexportedsecurity', 'local_learnwise');
        foreach ($auths as $auth) {
            $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_learnwise'), (string) $auth->id]);
            $this->assertEquals($this->usera->id, $data->userid);
            $this->assertSame($redacted, $data->clientid);
            foreach (['authcodes', 'accesstokens', 'refreshtokens'] as $property) {
                $this->assertCount(1, $data->$property);
                foreach ($data->$property as $item) {
                    $this->assertSame(['id', 'timeexpiry'], array_keys((array) $item));
                }
            }
        }
        $this->assert_tokens_exist('a', true);
        $this->assert_tokens_exist('a2', true);
        provider::delete_data_for_all_users_in_context($context);
        $this->assert_tokens_exist('a', false);
        $this->assert_tokens_exist('a2', false);
        $this->assert_tokens_exist('b', true);
        $this->assertSame(1, $DB->count_records('local_learnwise_userauth'));
        $this->assertSame(2, $DB->count_records('local_learnwise_clients'));
    }

    /**
     * Assert that all three credential types remain retrievable or have been erased.
     *
     * @param string $suffix Token fixture suffix.
     * @param bool $expected Whether the credentials should exist.
     */
    protected function assert_tokens_exist(string $suffix, bool $expected): void {
        $this->assertSame($expected, (bool) $this->storage->getAccessToken("access-{$suffix}"));
        $this->assertSame($expected, (bool) $this->storage->getRefreshToken("refresh-{$suffix}"));
        $this->assertSame($expected, (bool) $this->storage->getAuthorizationCode("code-{$suffix}"));
    }
}
