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

namespace local_learnwise;

/**
 * Tests for the Moodle-backed OAuth2 storage.
 *
 * @covers     \local_learnwise\storage
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class storage_test extends \advanced_testcase {
    /** @var storage */
    protected $storage;

    /** @var \stdClass */
    protected $client;

    /** @var \stdClass */
    protected $user;

    /**
     * Set up a client and user shared by the storage tests.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->storage = new storage();
        $this->client = util::get_or_generate_client();
        $this->user = $this->getDataGenerator()->create_user();
    }

    /**
     * A user authorisation row is created on first use and reused afterwards.
     */
    public function test_get_userauth_creates_then_reuses(): void {
        global $DB;

        $first = $this->storage->get_userauth($this->client->uniqid, $this->user->id);
        $this->assertEquals($this->client->id, $first->clientid);
        $this->assertEquals($this->user->id, $first->userid);
        $this->assertNotEmpty($first->id);

        $second = $this->storage->get_userauth($this->client->uniqid, $this->user->id);
        $this->assertEquals($first->id, $second->id);
        $this->assertSame(1, $DB->count_records('local_learnwise_userauth'));
    }

    /**
     * Separate users get separate authorisation rows for the same client.
     */
    public function test_get_userauth_is_per_user(): void {
        $other = $this->getDataGenerator()->create_user();

        $a = $this->storage->get_userauth($this->client->uniqid, $this->user->id);
        $b = $this->storage->get_userauth($this->client->uniqid, $other->id);

        $this->assertNotEquals($a->id, $b->id);
    }

    /**
     * An access token round-trips through storage with the expected shape.
     */
    public function test_access_token_round_trip(): void {
        $expires = time() + 3600;
        $this->assertTrue(
            $this->storage->setAccessToken('tok-abc', $this->client->uniqid, $this->user->id, $expires)
        );

        $token = $this->storage->getAccessToken('tok-abc');
        $this->assertSame('tok-abc', $token['access_token']);
        $this->assertSame($this->client->uniqid, $token['client_id']);
        $this->assertEquals($this->user->id, $token['user_id']);
        $this->assertEquals($expires, $token['expires']);
        $this->assertSame(constants::SCOPE, $token['scope']);
    }

    /**
     * Re-setting an existing access token updates its expiry rather than duplicating it.
     */
    public function test_set_access_token_updates_expiry(): void {
        global $DB;

        $this->storage->setAccessToken('tok-abc', $this->client->uniqid, $this->user->id, time() + 60);
        $later = time() + 7200;
        $this->storage->setAccessToken('tok-abc', $this->client->uniqid, $this->user->id, $later);

        $this->assertSame(1, $DB->count_records('local_learnwise_accesstoken', ['token' => 'tok-abc']));
        $this->assertEquals($later, $this->storage->getAccessToken('tok-abc')['expires']);
    }

    /**
     * An unknown access token is rejected.
     */
    public function test_get_access_token_unknown(): void {
        $this->assertFalse($this->storage->getAccessToken('nope'));
    }

    /**
     * An access token whose user authorisation row has gone is rejected.
     */
    public function test_get_access_token_with_orphaned_authid(): void {
        global $DB;

        $this->storage->setAccessToken('tok-abc', $this->client->uniqid, $this->user->id, time() + 60);
        $DB->delete_records('local_learnwise_userauth');

        $this->assertFalse($this->storage->getAccessToken('tok-abc'));
    }

    /**
     * An access token whose client has gone is rejected.
     */
    public function test_get_access_token_with_orphaned_client(): void {
        global $DB;

        $this->storage->setAccessToken('tok-abc', $this->client->uniqid, $this->user->id, time() + 60);
        $DB->delete_records('local_learnwise_clients');

        $this->assertFalse($this->storage->getAccessToken('tok-abc'));
    }

    /**
     * An authorization code round-trips through storage with the expected shape.
     */
    public function test_authorization_code_round_trip(): void {
        $expires = time() + 600;
        $this->storage->setAuthorizationCode(
            'code-1',
            $this->client->uniqid,
            $this->user->id,
            'https://example.test/callback',
            $expires
        );

        $code = $this->storage->getAuthorizationCode('code-1');
        $this->assertSame('code-1', $code['authorization_code']);
        $this->assertSame($this->client->uniqid, $code['client_id']);
        $this->assertEquals($this->user->id, $code['user_id']);
        $this->assertSame('https://example.test/callback', $code['redirect_uri']);
        $this->assertEquals($expires, $code['expires']);
        $this->assertSame(constants::SCOPE, $code['scope']);
    }

    /**
     * Re-issuing the same code updates the existing row instead of inserting a second one.
     */
    public function test_set_authorization_code_updates_existing(): void {
        global $DB;

        $this->storage->setAuthorizationCode(
            'code-1',
            $this->client->uniqid,
            $this->user->id,
            'https://example.test/a',
            time() + 60
        );
        $this->storage->setAuthorizationCode(
            'code-1',
            $this->client->uniqid,
            $this->user->id,
            'https://example.test/b',
            time() + 120
        );

        $this->assertSame(1, $DB->count_records('local_learnwise_authcode', ['code' => 'code-1']));
        $this->assertSame('https://example.test/b', $this->storage->getAuthorizationCode('code-1')['redirect_uri']);
    }

    /**
     * An unknown authorization code is rejected.
     */
    public function test_get_authorization_code_unknown(): void {
        $this->assertFalse($this->storage->getAuthorizationCode('nope'));
    }

    /**
     * Expiring an authorization code removes it so it cannot be replayed.
     */
    public function test_expire_authorization_code(): void {
        $this->storage->setAuthorizationCode(
            'code-1',
            $this->client->uniqid,
            $this->user->id,
            'https://example.test/callback',
            time() + 600
        );
        $this->assertNotFalse($this->storage->getAuthorizationCode('code-1'));

        $this->storage->expireAuthorizationCode('code-1');

        $this->assertFalse(
            $this->storage->getAuthorizationCode('code-1'),
            'An expired authorization code must not be redeemable'
        );
    }

    /**
     * Client credentials validate only against the exact stored secret.
     */
    public function test_check_client_credentials(): void {
        $this->assertTrue($this->storage->checkClientCredentials($this->client->uniqid, $this->client->secret));
        $this->assertFalse($this->storage->checkClientCredentials($this->client->uniqid, 'wrong-secret'));
        $this->assertFalse($this->storage->checkClientCredentials($this->client->uniqid, ''));
        $this->assertFalse($this->storage->checkClientCredentials($this->client->uniqid, null));
        $this->assertFalse($this->storage->checkClientCredentials('unknown-client', $this->client->secret));
    }

    /**
     * A client that carries a secret is not a public client.
     */
    public function test_is_public_client(): void {
        $this->assertFalse($this->storage->isPublicClient($this->client->uniqid));
        $this->assertFalse($this->storage->isPublicClient('unknown-client'));
    }

    /**
     * Client details expose the id, secret, redirect URI and scope.
     */
    public function test_get_client_details(): void {
        $this->resetAfterTest();
        set_config('redirecturl', "https://a.test/cb\nhttps://b.test/cb", 'local_learnwise');

        $details = $this->storage->getClientDetails($this->client->uniqid);
        $this->assertSame($this->client->uniqid, $details['client_id']);
        $this->assertSame($this->client->secret, $details['client_secret']);
        $this->assertSame('https://a.test/cb https://b.test/cb', $details['redirect_uri']);
        $this->assertSame(constants::SCOPE, $details['scope']);
    }

    /**
     * An unknown client has no details.
     */
    public function test_get_client_details_unknown(): void {
        $this->assertFalse($this->storage->getClientDetails('unknown-client'));
    }

    /**
     * The client scope is the default scope, and unknown clients have none.
     */
    public function test_get_client_scope(): void {
        $this->assertSame(constants::SCOPE, $this->storage->getClientScope($this->client->uniqid));
        $this->assertNull($this->storage->getClientScope('unknown-client'));
    }

    /**
     * Only the authorization code and refresh token grants are permitted.
     */
    public function test_check_restricted_grant_type(): void {
        $this->assertTrue($this->storage->checkRestrictedGrantType($this->client->uniqid, 'authorization_code'));
        $this->assertTrue($this->storage->checkRestrictedGrantType($this->client->uniqid, 'refresh_token'));
        $this->assertFalse($this->storage->checkRestrictedGrantType($this->client->uniqid, 'client_credentials'));
        $this->assertFalse($this->storage->checkRestrictedGrantType($this->client->uniqid, 'password'));
        $this->assertFalse($this->storage->checkRestrictedGrantType($this->client->uniqid, 'implicit'));
    }

    /**
     * A refresh token round-trips through storage with the expected shape.
     */
    public function test_refresh_token_round_trip(): void {
        $expires = time() + 86400;
        $this->storage->setRefreshToken('refresh-1', $this->client->uniqid, $this->user->id, $expires);

        $token = $this->storage->getRefreshToken('refresh-1');
        $this->assertSame('refresh-1', $token['refresh_token']);
        $this->assertSame($this->client->uniqid, $token['client_id']);
        $this->assertEquals($this->user->id, $token['user_id']);
        $this->assertEquals($expires, $token['expires']);
        $this->assertSame(constants::SCOPE, $token['scope']);
    }

    /**
     * An unknown refresh token is rejected.
     */
    public function test_get_refresh_token_unknown(): void {
        $this->assertFalse($this->storage->getRefreshToken('nope'));
    }

    /**
     * Unsetting a refresh token makes it unusable.
     */
    public function test_unset_refresh_token(): void {
        $this->storage->setRefreshToken('refresh-1', $this->client->uniqid, $this->user->id, time() + 60);
        $this->assertNotFalse($this->storage->getRefreshToken('refresh-1'));

        $this->storage->unsetRefreshToken('refresh-1');

        $this->assertFalse($this->storage->getRefreshToken('refresh-1'));
    }

    /**
     * Unsetting one refresh token leaves the others alone.
     */
    public function test_unset_refresh_token_is_targeted(): void {
        $this->storage->setRefreshToken('refresh-1', $this->client->uniqid, $this->user->id, time() + 60);
        $this->storage->setRefreshToken('refresh-2', $this->client->uniqid, $this->user->id, time() + 60);

        $this->storage->unsetRefreshToken('refresh-1');

        $this->assertFalse($this->storage->getRefreshToken('refresh-1'));
        $this->assertNotFalse($this->storage->getRefreshToken('refresh-2'));
    }

    /**
     * The signing algorithm is RS256.
     */
    public function test_get_encryption_algorithm(): void {
        $this->assertSame('RS256', $this->storage->getEncryptionAlgorithm());
        $this->assertSame('RS256', $this->storage->getEncryptionAlgorithm($this->client->uniqid));
    }

    /**
     * Only the plugin's own scope exists.
     */
    public function test_scope_exists(): void {
        $this->assertSame(constants::SCOPE, $this->storage->getDefaultScope());
        $this->assertTrue($this->storage->scopeExists(constants::SCOPE));
        $this->assertFalse($this->storage->scopeExists('admin'));
        $this->assertFalse($this->storage->scopeExists(''));
        $this->assertFalse($this->storage->scopeExists('webservice extra'));
    }
}
