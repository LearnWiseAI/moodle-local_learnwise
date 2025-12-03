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

use moodle_database;
use local_learnwise\local\OAuth2\OpenID\Storage\AuthorizationCodeInterface;
use local_learnwise\local\OAuth2\Storage\AccessTokenInterface;
use local_learnwise\local\OAuth2\Storage\ClientCredentialsInterface;
use local_learnwise\local\OAuth2\Storage\RefreshTokenInterface;
use local_learnwise\local\OAuth2\Storage\ScopeInterface;
use stdClass;

// phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
/**
 * Class storage
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class storage implements
    AccessTokenInterface,
    AuthorizationCodeInterface,
    ClientCredentialsInterface,
    RefreshTokenInterface,
    ScopeInterface {
    /**
     * @var moodle_database
     */
    protected $db;

    /**
     * storage constructor.
     */
    public function __construct() {
        global $DB;
        $this->db = $DB;
    }

    /**
     * Returns the user authorization record for the given client and user.
     *
     * @param string $clientid
     * @param string $userid
     * @return stdClass
     */
    public function get_userauth(string $clientid, string $userid): stdClass {
        $client = $this->db->get_record('local_learnwise_clients', ['uniqid' => $clientid]);
        $params = ['clientid' => $client->id, 'userid' => $userid];
        $record = $this->db->get_record('local_learnwise_userauth', $params);
        if (!$record) {
            $record = (object) $params;
            $record->id = $this->db->insert_record('local_learnwise_userauth', $record);
        }
        return $record;
    }

    /**
     * Returns the access token for the given OAuth token.
     *
     * @param string $oauthtoken
     * @return array|false
     */
    public function getAccessToken($oauthtoken) {
        $token = $this->db->get_record('local_learnwise_accesstoken', ['token' => $oauthtoken]);
        if (!$token) {
            return false;
        }
        $userauth = $this->db->get_record('local_learnwise_userauth', ['id' => $token->authid]);
        if (!$userauth) {
            return false;
        }
        $client = $this->db->get_record('local_learnwise_clients', ['id' => $userauth->clientid]);
        if (!$client) {
            return false;
        }
        return [
            'access_token' => $token->token,
            'client_id' => $client->uniqid,
            'user_id' => $userauth->userid,
            'expires' => $token->timeexpiry,
            'scope' => $this->getDefaultScope(),
        ];
    }

    /**
     * Sets the access token for the given OAuth token.
     *
     * @param string $oauthtoken
     * @param string $clientid
     * @param string $userid
     * @param int $expires
     * @param string|null $scope
     * @return bool
     */
    public function setAccessToken($oauthtoken, $clientid, $userid, $expires, $scope = null) {
        $params = ['token' => $oauthtoken];
        $record = $this->db->get_record('local_learnwise_accesstoken', $params);
        $userauth = $this->get_userauth($clientid, $userid);
        if (!$record) {
            $record = (object) $params;
            $record->authid = $userauth->id;
            $record->timeexpiry = $expires;

            $this->db->insert_record('local_learnwise_accesstoken', $record);
        } else {
            $record->timeexpiry = $expires;
            $this->db->update_record('local_learnwise_accesstoken', $record);
        }

        return true;
    }

    /**
     * Returns the authorization code for the given code.
     *
     * @param string $code
     * @return array|false
     */
    public function getAuthorizationCode($code) {
        $authcode = $this->db->get_record('local_learnwise_authcode', ['code' => $code]);
        if (!$authcode) {
            return false;
        }
        $userauth = $this->db->get_record('local_learnwise_userauth', ['id' => $authcode->authid]);
        if (!$userauth) {
            return false;
        }
        $client = $this->db->get_record('local_learnwise_clients', ['id' => $userauth->clientid]);
        if (!$client) {
            return false;
        }
        $configredirecturl = constants::get_redirecturl();
        $redirecturi = optional_param('redirect_uri', false, PARAM_URL);
        $configredirecturls = preg_split('/\s+/', $configredirecturl);
        if (!in_array($redirecturi, $configredirecturls)) {
            $redirecturi = '';
        }
        return [
            'authorization_code' => $authcode->code,
            'client_id' => $client->uniqid,
            'user_id' => $userauth->userid,
            'redirect_uri' => $redirecturi,
            'expires' => $authcode->timeexpiry,
            'scope' => $this->getDefaultScope(),
            'id_token' => $authcode->token,
        ];
    }

    /**
     * Sets the authorization code for the given code.
     *
     * @param string $code
     * @param string $clientid
     * @param string $userid
     * @param string $redirecturi
     * @param int $expires
     * @param string|null $scope
     * @param string|null $idtoken
     * @param string|null $codechallenge
     * @param string|null $codechallengemethod
     */
    public function setAuthorizationCode(
        $code,
        $clientid,
        $userid,
        $redirecturi,
        $expires,
        $scope = null,
        $idtoken = null,
        $codechallenge = null,
        $codechallengemethod = null
    ) {
        $userauth = $this->get_userauth($clientid, $userid);
        $record = new stdClass();
        $record->authid = $userauth->id;
        $record->code = $code;
        $record->timeexpiry = $expires;
        if ($id = $this->db->get_field('local_learnwise_authcode', 'id', ['code' => $code])) {
            $record->id = $id;
            $this->db->update_record('local_learnwise_authcode', $record);
        } else {
            $record->id = $this->db->insert_record('local_learnwise_authcode', $record);
        }
    }

    /**
     * Expires the authorization code for the given code.
     *
     * @param string $code
     */
    public function expireAuthorizationCode($code) {
        $this->db->delete_records('local_learnwise_authcode', ['code' => $code]);
    }

    /**
     * Checks if the client credentials are valid.
     *
     * @param string $clientid
     * @param string|null $clientsecret
     * @return bool
     */
    public function checkClientCredentials($clientid, $clientsecret = null) {
        $client = $this->getClientDetails($clientid);
        if ($client) {
            return $clientsecret === $client['client_secret'];
        }
        return false;
    }

    /**
     * Initializes the event vault and retrieval strategy.
     * @param int $clientid
     * @return bool
     */
    public function isPublicClient($clientid) {
        $client = $this->getClientDetails($clientid);
        if ($client) {
            return empty($client['client_secret']);
        }
        return false;
    }

    /**
     * Summary of getClientDetails
     * @param mixed $clientid
     * @return array|false
     */
    public function getClientDetails($clientid) {
        $client = $this->db->get_record('local_learnwise_clients', ['uniqid' => $clientid]);
        if (!empty($client)) {
            return [
                'client_id' => $client->uniqid,
                'client_secret' => $client->secret,
                'redirect_uri' => constants::get_redirecturl(),
                'scope' => $this->getDefaultScope(),
            ];
        }
        return false;
    }

    /**
     * Summary of getClientScope
     * @param mixed $clientid
     * @return string|null
     */
    public function getClientScope($clientid) {
        if (!$clientdetails = $this->getClientDetails($clientid)) {
            return null;
        }
        if (!empty($clientdetails['scope'])) {
            return $clientdetails['scope'];
        }

        return null;
    }

    /**
     * Checks if the client is allowed to use the given grant type.
     *
     * @param string $clientid
     * @param string $granttype
     * @return bool
     */
    public function checkRestrictedGrantType($clientid, $granttype) {
        return in_array($granttype, ['authorization_code', 'refresh_token']);
    }


    /**
     * Returns the refresh token for the given refresh token.
     *
     * @param string $refreshtoken
     * @return array|false
     */
    public function getRefreshToken($refreshtoken) {
        $token = $this->db->get_record('local_learnwise_refreshtoken', ['token' => $refreshtoken]);
        if (!$token) {
            return false;
        }
        $userauth = $this->db->get_record('local_learnwise_userauth', ['id' => $token->authid]);
        if (!$userauth) {
            return false;
        }
        $client = $this->db->get_record('local_learnwise_clients', ['id' => $userauth->clientid]);
        if (!$client) {
            return false;
        }
        return [
            'refresh_token' => $token->token,
            'client_id' => $client->uniqid,
            'user_id' => $userauth->userid,
            'expires' => $token->timeexpiry,
            'scope' => $this->getDefaultScope(),
        ];
    }

    /**
     * Sets the refresh token for the given refresh token.
     *
     * @param string $refreshtoken
     * @param string $clientid
     * @param string $userid
     * @param int $expires
     * @param string|null $scope
     * @return void
     */
    public function setRefreshToken($refreshtoken, $clientid, $userid, $expires, $scope = null) {
        $userauth = $this->get_userauth($clientid, $userid);
        $record = new stdClass();
        $record->authid = $userauth->id;
        $record->token = $refreshtoken;
        $record->timeexpiry = $expires;
        $record->id = $this->db->insert_record('local_learnwise_refreshtoken', $record);
    }

    /**
     * Unsets the refresh token for the given refresh token.
     *
     * @param string $refreshtoken
     * @return void
     */
    public function unsetRefreshToken($refreshtoken) {
        $this->db->delete_records('local_learnwise_refreshtoken', ['token' => $refreshtoken]);
    }

    /**
     * Returns the encryption algorithm used for the client.
     *
     * @param int|null $clientid
     * @return string
     */
    public function getEncryptionAlgorithm($clientid = null) {
        return 'RS256';
    }

    /**
     * Summary of getDefaultScope
     * @param mixed $clientid
     * @return string
     */
    public function getDefaultScope($clientid = null) {
        return constants::SCOPE;
    }

    /**
     * Checks if the given scope exists.
     *
     * @param string $scope
     * @return bool
     */
    public function scopeExists($scope) {
        return $scope === $this->getDefaultScope();
    }
}
