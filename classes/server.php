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

defined('MOODLE_INTERNAL') || die();

use OAuth2\Autoloader;
use OAuth2\GrantType\AuthorizationCode;
use OAuth2\GrantType\RefreshToken;
use OAuth2\Server as OAuth2Server;

require_once(dirname(dirname(__FILE__)) . '/OAuth2/Autoloader.php');
Autoloader::register();

/**
 * Class server
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server extends OAuth2Server {

    /**
     * The singleton instance of the server class.
     *
     * @var self|null
     */
    protected static $instance = null;

    /**
     * Returns the singleton instance of the server class.
     *
     * @return server The server instance.
     */
    public static function get_instance(): server {
        if (is_null(self::$instance)) {
            $storage = new storage();
            $server = new static($storage, [
                'enforce_state' => false,
                'access_lifetime' => HOURSECS,
                'refresh_token_lifetime' => WEEKSECS,
            ]);

            $server->addGrantType(new AuthorizationCode($storage));
            $server->addGrantType(new RefreshToken($storage, [
                'always_issue_new_refresh_token' => true,
                'unset_refresh_token_after_use' => true,
            ]));
            self::$instance = $server;
        }
        return self::$instance;
    }

}
