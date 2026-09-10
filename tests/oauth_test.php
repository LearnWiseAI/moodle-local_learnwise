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

use local_learnwise\local\OAuth2\Request;
use local_learnwise\local\OAuth2\Response;
use local_learnwise\local\OAuth2\Server as legacy_server;
use local_learnwise\local\OAuth2\GrantType\AuthorizationCode;
use local_learnwise\local\OAuth2\GrantType\RefreshToken;

/**
 * OAuth interoperability and PKCE security regression tests.
 *
 * @covers \local_learnwise\server
 * @covers \local_learnwise\oauth\authorize_controller
 * @covers \local_learnwise\storage
 * @package local_learnwise
 * @copyright 2026 LearnWise <help@learnwise.ai>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class oauth_test extends \advanced_testcase {
    /** @var storage OAuth storage. */
    protected $storage;
    /** @var \stdClass Client credentials. */
    protected $client;
    /** @var \stdClass Authorizing user. */
    protected $user;
    /** @var string RFC 7636 appendix B verifier. */
    const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    /** @var string RFC 7636 appendix B S256 challenge. */
    const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
    /** @var string Callback URL. */
    const CALLBACK = 'https://example.test/callback';

    /**
     * Set up real Moodle-backed OAuth storage.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->storage = new storage();
        $this->client = util::get_or_generate_client();
        $this->user = $this->getDataGenerator()->create_user();
        set_config('redirecturl', self::CALLBACK, 'local_learnwise');
    }

    /**
     * Build an independent server to exercise persistence between HTTP requests.
     *
     * @param bool $legacy Use the unchanged bundled controller shipped by old plugins.
     * @return legacy_server
     */
    protected function make_server(bool $legacy = false): legacy_server {
        $class = $legacy ? legacy_server::class : server::class;
        $server = new $class($this->storage, ['enforce_state' => true]);
        $server->addGrantType(new AuthorizationCode($this->storage));
        $server->addGrantType(new RefreshToken($this->storage, [
            'always_issue_new_refresh_token' => true, 'unset_refresh_token_after_use' => true,
        ]));
        return $server;
    }

    /**
     * Issue a code through the real authorization controller.
     *
     * @param array $pkce Challenge parameters.
     * @param bool $legacy Use the old controller.
     * @return string Authorization code.
     */
    protected function authorize(array $pkce = [], bool $legacy = false): string {
        $response = new Response();
        $this->make_server($legacy)->handleAuthorizeRequest(new Request(array_merge([
            'client_id' => $this->client->uniqid, 'response_type' => 'code',
            'redirect_uri' => self::CALLBACK, 'state' => 'test-state',
        ], $pkce)), $response, true, $this->user->id);
        $this->assertSame(302, $response->getStatusCode());
        parse_str(parse_url($response->getHttpHeader('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('test-state', $query['state']);
        return $query['code'];
    }

    /**
     * Exchange a code or refresh token using a new server instance.
     *
     * @param array $params Token request parameters.
     * @return Response
     */
    protected function exchange(array $params): Response {
        $response = new Response();
        $request = new Request([], array_merge([
            'grant_type' => 'authorization_code', 'client_id' => $this->client->uniqid,
            'client_secret' => $this->client->secret, 'redirect_uri' => self::CALLBACK,
        ], $params), [], [], [], ['REQUEST_METHOD' => 'POST']);
        $this->make_server()->handleTokenRequest($request, $response);
        return $response;
    }

    /**
     * Valid S256 exchanges succeed once; refresh tokens still rotate without PKCE.
     */
    public function test_s256_exchange_replay_and_refresh(): void {
        $code = $this->authorize(['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256']);
        $stored = $this->storage->getAuthorizationCode($code);
        $this->assertSame(self::CHALLENGE, $stored['code_challenge']);
        $this->assertSame('S256', $stored['code_challenge_method']);
        $response = $this->exchange(['code' => $code, 'code_verifier' => self::VERIFIER]);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotFalse($this->storage->getAccessToken($response->getParameter('access_token')));
        $this->assertFalse($this->storage->getAuthorizationCode($code));
        $this->assertSame(400, $this->exchange(['code' => $code, 'code_verifier' => self::VERIFIER])->getStatusCode());
        $refresh = $response->getParameter('refresh_token');
        $rotated = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
        $this->assertSame(200, $rotated->getStatusCode());
        $this->assertNotSame($refresh, $rotated->getParameter('refresh_token'));
        $this->assertFalse($this->storage->getRefreshToken($refresh));
    }

    /**
     * Legacy clients keep working, and old controllers tolerate a new client's challenge/verifier.
     */
    public function test_legacy_interoperability(): void {
        $code = $this->authorize();
        $this->assertNull($this->storage->getAuthorizationCode($code)['code_challenge']);
        $this->assertSame(200, $this->exchange(['code' => $code])->getStatusCode());
        $code = $this->authorize(['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256'], true);
        $this->assertNull($this->storage->getAuthorizationCode($code)['code_challenge']);
        $this->assertSame(200, $this->exchange(['code' => $code, 'code_verifier' => self::VERIFIER])->getStatusCode());
    }

    /**
     * Bound codes cannot be exchanged without the correct verifier.
     *
     * @dataProvider invalid_verifier_provider
     * @param string|null $verifier Supplied verifier.
     */
    public function test_invalid_verifier_is_rejected(?string $verifier): void {
        $code = $this->authorize(['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256']);
        $params = ['code' => $code];
        if ($verifier !== null) {
            $params['code_verifier'] = $verifier;
        }
        $response = $this->exchange($params);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($response->getParameter('access_token'));
    }

    /**
     * Invalid verifier cases.
     *
     * @return array
     */
    public static function invalid_verifier_provider(): array {
        return [[null], [''], ['short'], [str_repeat('a', 64)], [str_repeat('a', 129)], [str_repeat('!', 43)]];
    }

    /**
     * Malformed/unsupported PKCE cannot silently fall back to legacy OAuth.
     *
     * @dataProvider invalid_challenge_provider
     * @param array $pkce Challenge parameters.
     */
    public function test_invalid_challenge_is_rejected(array $pkce): void {
        global $DB;
        $response = new Response();
        $this->make_server()->handleAuthorizeRequest(new Request(array_merge([
            'client_id' => $this->client->uniqid, 'response_type' => 'code',
            'redirect_uri' => self::CALLBACK, 'state' => 'test-state',
        ], $pkce)), $response, true, $this->user->id);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, $DB->count_records('local_learnwise_authcode'));
    }

    /**
     * Invalid challenge combinations.
     *
     * @return array
     */
    public static function invalid_challenge_provider(): array {
        return [
            [['code_challenge' => '']],
            [['code_challenge_method' => 'S256']],
            [['code_challenge' => self::CHALLENGE]],
            [['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'plain']],
            [['code_challenge' => 'short', 'code_challenge_method' => 'S256']],
            [['code_challenge' => [self::CHALLENGE], 'code_challenge_method' => 'S256']],
        ];
    }

    /**
     * PKCE supplements client authentication and redirect matching.
     */
    public function test_pkce_does_not_bypass_client_or_redirect_checks(): void {
        $code = $this->authorize(['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256']);
        foreach ([['client_secret' => 'wrong'], ['redirect_uri' => 'https://example.test/wrong']] as $override) {
            $response = $this->exchange(array_merge(['code' => $code, 'code_verifier' => self::VERIFIER], $override));
            $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
            $this->assertNull($response->getParameter('access_token'));
        }
    }

    /**
     * Login-return and consent-action URLs preserve the challenge exactly.
     */
    public function test_login_and_consent_url_preserves_pkce(): void {
        $_GET['code_challenge'] = self::CHALLENGE;
        $_GET['code_challenge_method'] = 'S256';
        $url = server::get_authorization_url(['client_id' => $this->client->uniqid, 'state' => 'test-state']);
        $this->assertSame(self::CHALLENGE, $url->get_param('code_challenge'));
        $this->assertSame('S256', $url->get_param('code_challenge_method'));
        $this->assertSame('test-state', $url->get_param('state'));
        unset($_GET['code_challenge'], $_GET['code_challenge_method']);
        $this->assertNull(server::get_authorization_url([])->get_param('code_challenge'));
    }

    /**
     * The consent form submits the same challenge as the login-return URL, for GET and POST entry.
     */
    public function test_consent_form_preserves_pkce(): void {
        global $PAGE;
        $this->setUser($this->user);
        $PAGE->set_context(\context_system::instance());
        $_POST['code_challenge'] = self::CHALLENGE;
        $_POST['code_challenge_method'] = 'S256';
        $url = server::get_authorization_url([
            'client_id' => $this->client->uniqid, 'response_type' => 'code',
            'redirect_uri' => self::CALLBACK, 'state' => 'test-state',
        ]);
        $PAGE->set_url($url);
        $form = new \local_learnwise\form\permission($url);
        $html = $form->render();
        $this->assertStringContainsString(self::CHALLENGE, $html);
        $this->assertStringContainsString('code_challenge_method', $html);
        $this->assertStringContainsString('S256', $html);
        unset($_POST['code_challenge'], $_POST['code_challenge_method']);
    }

    /**
     * Production server wiring also enforces PKCE for POST authorization parameters.
     */
    public function test_production_server_preserves_post_challenge(): void {
        $response = new Response();
        server::get_instance()->handleAuthorizeRequest(new Request([], [
            'client_id' => $this->client->uniqid, 'response_type' => 'code',
            'redirect_uri' => self::CALLBACK, 'state' => 'test-state',
            'code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256',
        ]), $response, true, $this->user->id);
        $this->assertSame(302, $response->getStatusCode());
        parse_str(parse_url($response->getHttpHeader('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(self::CHALLENGE, $this->storage->getAuthorizationCode($query['code'])['code_challenge']);
        $this->assertSame(400, $this->exchange(['code' => $query['code']])->getStatusCode());
    }

    /**
     * Expired codes remain invalid even with the correct verifier.
     */
    public function test_expired_code_is_rejected(): void {
        global $DB;
        $code = $this->authorize(['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256']);
        $DB->set_field('local_learnwise_authcode', 'timeexpiry', time() - 60, ['code' => hash('sha256', $code)]);
        $response = $this->exchange(['code' => $code, 'code_verifier' => self::VERIFIER]);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertNull($response->getParameter('access_token'));
    }

    /**
     * Schema upgrades from older releases and current main preserve credentials and consent.
     *
     * @dataProvider upgrade_version_provider
     * @param int $oldversion Installed version before the security upgrade.
     */
    public function test_upgrade_preserves_legacy_credentials(int $oldversion): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/learnwise/db/upgrade.php');
        $code = $this->authorize();
        $this->storage->setAccessToken('existing-access', $this->client->uniqid, $this->user->id, time() + 3600);
        $this->storage->setRefreshToken('existing-refresh', $this->client->uniqid, $this->user->id, time() + 3600);
        // Recreate the actual pre-upgrade storage format, including the absent hash marker.
        $legacy = [
            'local_learnwise_authcode' => ['code', $code],
            'local_learnwise_accesstoken' => ['token', 'existing-access'],
            'local_learnwise_refreshtoken' => ['token', 'existing-refresh'],
        ];
        $manager = $DB->get_manager();
        foreach ($legacy as $name => [$field, $value]) {
            $DB->set_field($name, $field, $value);
            $manager->drop_field(new \xmldb_table($name), new \xmldb_field('tokenhashed'));
        }
        $table = new \xmldb_table('local_learnwise_authcode');
        $manager->drop_field($table, new \xmldb_field('codechallengemethod'));
        $manager->drop_field($table, new \xmldb_field('codechallenge'));
        set_config('version', $oldversion, 'local_learnwise');
        set_config('upgraderunning', time() + 3600);
        $this->assertTrue(xmldb_local_learnwise_upgrade($oldversion));
        foreach ($legacy as $name => [$field, $value]) {
            $record = $DB->get_record($name, [], '*', MUST_EXIST);
            $this->assertSame(hash('sha256', $value), $record->$field);
            $this->assertEquals(1, $record->tokenhashed);
            $this->assertFalse($DB->record_exists($name, [$field => $value]));
        }
        $this->assertNull($this->storage->getAuthorizationCode($code)['code_challenge']);
        $this->assertSame(
            $this->client->secret,
            $DB->get_field('local_learnwise_clients', 'secret', ['id' => $this->client->id])
        );
        $this->assertSame(1, $DB->count_records('local_learnwise_userauth'));
        $this->assertNotFalse($this->storage->getAccessToken('existing-access'));
        $this->assertNotFalse($this->storage->getRefreshToken('existing-refresh'));
        $this->assertSame(200, $this->exchange(['code' => $code])->getStatusCode());
        $refreshed = $this->exchange(['grant_type' => 'refresh_token', 'refresh_token' => 'existing-refresh']);
        $this->assertSame(200, $refreshed->getStatusCode());
        // An interrupted upgrade with the fields already present can resume safely.
        set_config('version', $oldversion, 'local_learnwise');
        $this->assertTrue(xmldb_local_learnwise_upgrade($oldversion));
    }

    /**
     * Migrating an in-flight S256 code preserves its verifier requirement and expiry.
     */
    public function test_hash_upgrade_preserves_pkce(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/learnwise/db/upgradelib.php');
        $code = $this->authorize(['code_challenge' => self::CHALLENGE, 'code_challenge_method' => 'S256']);
        $record = $DB->get_record('local_learnwise_authcode', [], '*', MUST_EXIST);
        $record->code = $code;
        $record->tokenhashed = 0;
        $DB->update_record('local_learnwise_authcode', $record);
        set_config('upgraderunning', time() + 3600);
        local_learnwise_upgrade_hash_user_tokens();
        $this->assertEquals($record->timeexpiry, $this->storage->getAuthorizationCode($code)['expires']);
        $this->assertSame(self::CHALLENGE, $this->storage->getAuthorizationCode($code)['code_challenge']);
        $this->assertSame(400, $this->exchange(['code' => $code])->getStatusCode());
        $this->assertSame(200, $this->exchange(['code' => $code, 'code_verifier' => self::VERIFIER])->getStatusCode());
        $this->assertSame(400, $this->exchange(['code' => $code, 'code_verifier' => self::VERIFIER])->getStatusCode());
    }
    /**
     * Releases before PKCE, including main's chat-display setting release.
     *
     * @return array
     */
    public static function upgrade_version_provider(): array {
        return [[2026090800], [2026091000]];
    }

}
