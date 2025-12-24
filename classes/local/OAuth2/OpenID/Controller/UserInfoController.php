<?php

namespace local_learnwise\local\OAuth2\OpenID\Controller;

use local_learnwise\local\OAuth2\Scope;
use local_learnwise\local\OAuth2\TokenType\TokenTypeInterface;
use local_learnwise\local\OAuth2\Storage\AccessTokenInterface;
use local_learnwise\local\OAuth2\OpenID\Storage\UserClaimsInterface;
use local_learnwise\local\OAuth2\Controller\ResourceController;
use local_learnwise\local\OAuth2\ScopeInterface;
use local_learnwise\local\OAuth2\RequestInterface;
use local_learnwise\local\OAuth2\ResponseInterface;

/**
 * @see OAuth2\Controller\UserInfoControllerInterface
 */
class UserInfoController extends ResourceController implements UserInfoControllerInterface
{
    /**
     * @var UserClaimsInterface
     */
    protected $userClaimsStorage;

    /**
     * Constructor
     *
     * @param TokenTypeInterface   $tokenType
     * @param AccessTokenInterface $tokenStorage
     * @param UserClaimsInterface  $userClaimsStorage
     * @param array                $config
     * @param ScopeInterface       $scopeUtil
     */
    public function __construct(TokenTypeInterface $tokenType, AccessTokenInterface $tokenStorage, UserClaimsInterface $userClaimsStorage, $config = array(), ScopeInterface $scopeUtil = null)
    {
        parent::__construct($tokenType, $tokenStorage, $config, $scopeUtil);

        $this->userClaimsStorage = $userClaimsStorage;
    }

    /**
     * Handle the user info request
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    public function handleUserInfoRequest(RequestInterface $request, ResponseInterface $response)
    {
        if (!$this->verifyResourceRequest($request, $response, 'openid')) {
            return;
        }

        $token = $this->getToken();
        $claims = $this->userClaimsStorage->getUserClaims($token['user_id'], $token['scope']);
        // The sub Claim MUST always be returned in the UserInfo Response.
        // http://openid.net/specs/openid-connect-core-1_0.html#UserInfoResponse
        $claims += array(
            'sub' => $token['user_id'],
        );
        $response->addParameters($claims);
    }
}