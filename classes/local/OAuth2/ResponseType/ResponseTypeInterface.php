<?php

namespace local_learnwise\local\OAuth2\ResponseType;

interface ResponseTypeInterface
{
    /**
     * @param array $params
     * @param mixed $user_id
     * @return mixed
     */
    public function getAuthorizeResponse($params, $user_id = null);
}
