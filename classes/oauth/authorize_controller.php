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

namespace local_learnwise\oauth;

use local_learnwise\local\OAuth2\Controller\AuthorizeController;
use local_learnwise\local\OAuth2\RequestInterface;
use local_learnwise\local\OAuth2\ResponseInterface;

// phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod -- Inherited OAuth API.
/**
 * Binds optional S256 PKCE parameters to authorization codes.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class authorize_controller extends AuthorizeController {
    /**
     * Validate ordinary OAuth requests and any supplied PKCE parameters.
     *
     * @param RequestInterface $request OAuth request.
     * @param ResponseInterface $response OAuth response.
     * @return bool
     */
    public function validateAuthorizeRequest(RequestInterface $request, ResponseInterface $response) {
        if (!parent::validateAuthorizeRequest($request, $response)) {
            return false;
        }
        $challenge = $request->query('code_challenge', $request->request('code_challenge'));
        $method = $request->query('code_challenge_method', $request->request('code_challenge_method'));
        // Legacy confidential clients may omit PKCE entirely.
        if ($challenge === null && $method === null) {
            return true;
        }
        if (!is_string($challenge) || preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge) !== 1 || $method !== 'S256') {
            $response->setError(400, 'invalid_request', 'PKCE requires a valid S256 code challenge.');
            return false;
        }
        return true;
    }

    /**
     * Pass the validated challenge to authorization code storage.
     *
     * @param RequestInterface $request OAuth request.
     * @param ResponseInterface $response OAuth response.
     * @param mixed $userid Authorizing user.
     * @return array
     */
    protected function buildAuthorizeParameters($request, $response, $userid) {
        $params = parent::buildAuthorizeParameters($request, $response, $userid);
        $params['code_challenge'] = $request->query('code_challenge', $request->request('code_challenge'));
        $params['code_challenge_method'] = $request->query('code_challenge_method', $request->request('code_challenge_method'));
        return $params;
    }
}
