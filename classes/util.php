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

use context_system;
use core\event\webservice_token_created;
use Exception;
use html_writer;
use stdClass;
use webservice;

/**
 * Class util
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class util {
    /**
     * Returns the component name for the current class.
     * If the COMPONENT constant is not defined, it will use the first part of the class name.
     *
     * @return string
     */
    public static function component(): string {
        if (!defined('static::COMPONENT') || is_null(constant('static::COMPONENT'))) {
            $component = explode('\\', static::class)[0];
        } else {
            $component = constant('static::COMPONENT');
        }
        return $component;
    }

    /**
     * Returns a client object, either from the database or generates a new one.
     *
     * @return stdClass
     */
    public static function get_or_generate_client(): stdClass {
        global $DB;

        $clients = $DB->get_records('local_learnwise_clients');
        if (!empty($clients)) {
            return reset($clients);
        }

        $client = new stdClass();
        $client->uniqid = random_string(15);
        $client->secret = random_string(100);
        $client->id = $DB->insert_record('local_learnwise_clients', $client);
        return $client;
    }

    /**
     * returns a copy input field with a button to copy the value.
     * @param string $value
     * @return string
     */
    public static function generate_copy_input($value) {
        global $OUTPUT;
        $copyicon = $OUTPUT->pix_icon('t/copy', get_string('copy', 'core'));
        $htmlinputid = html_writer::random_id('input-');
        $out = html_writer::start_div('input-group');
        $out .= html_writer::empty_tag('input', ['id' => $htmlinputid, 'type' => 'text',
            'value' => $value, 'readonly' => 'readonly', 'class' => 'form-control']);
        $out .= html_writer::tag('a', $copyicon, [
            'class' => 'copy-button clickable input-group-append input-group-text icon-no-margin',
            'data-clipboard-target' => "#{$htmlinputid}",
        ]);
        $out .= html_writer::end_div();
        return $out;
    }

    /**
     * returns a token for the given service.
     * @param string $service
     * @param bool $create
     * @return stdClass|false
     */
    public static function get_or_generate_token_for_user($service, $create = true) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/webservice/lib.php');
        $wsmanager = new webservice();
        $extservice = $wsmanager->get_external_service_by_shortname($service);
        if (!$extservice) {
            throw new Exception("Service not found");
        }
        $tokenuser = self::get_or_create_apiuser();

        if ($extservice->restrictedusers) {
            $wsauthorizedusers = $wsmanager->get_ws_authorised_users($extservice->id);
            $authorizeuserfound = false;
            foreach ($wsauthorizedusers as $wsauthuser) {
                if ($wsauthuser->id == $tokenuser->id) {
                    if (empty($wsauthuser->validuntil) || $wsauthuser->validuntil > time()) {
                        $authorizeuserfound = true;
                        break;
                    } else {
                        $wsmanager->remove_ws_authorised_user($tokenuser, $extservice->id);
                    }
                } else {
                    $updaterecord = $DB->get_record('external_services_users', ['id' => $wsauthuser->serviceuserid]);
                    if ($updaterecord) {
                        $authorizeuserfound = true;
                        $updaterecord->userid = $tokenuser->id;
                        $wsmanager->update_ws_authorised_user($updaterecord);
                        break;
                    }
                }
            }
            if (empty($authorizeuserfound)) {
                $serviceuser = new stdClass();
                $serviceuser->externalserviceid = $extservice->id;
                $serviceuser->userid = $tokenuser->id;
                $wsmanager->add_ws_authorised_user($serviceuser);
            }
        }

        $conditions = [
            'userid' => $tokenuser->id,
            'externalserviceid' => $extservice->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ];
        $tokens = $DB->get_records('external_tokens', $conditions, 'timecreated ASC');

        foreach ($tokens as $key => $token) {
            $unsettoken = false;
            if (!empty($token->sid)) {
                if (!\core\session\manager::session_exists($token->sid)) {
                    $DB->delete_records('external_tokens', ['sid' => $token->sid]);
                    $unsettoken = true;
                }
            }

            if (!empty($token->validuntil) && $token->validuntil < time()) {
                $DB->delete_records('external_tokens', ['token' => $token->token, 'tokentype' => EXTERNAL_TOKEN_PERMANENT]);
                $unsettoken = true;
            }

            if (isset($token->iprestriction) && !address_in_subnet(getremoteaddr(), $token->iprestriction)) {
                $unsettoken = true;
            }

            if ($unsettoken) {
                unset($tokens[$key]);
            }
        }

        if (count($tokens) > 0) {
            $token = array_pop($tokens);
        } else if ($create) {
            $token = new stdClass();
            $token->token = md5(uniqid(rand(), 1));
            $token->userid = $tokenuser->id;
            $token->creatorid = $USER->id;
            $token->tokentype = EXTERNAL_TOKEN_PERMANENT;
            $token->contextid = SYSCONTEXTID;
            $token->timecreated = time();
            $token->externalserviceid = $extservice->id;
            $token->validuntil = $token->iprestriction = $token->sid = $token->lastaccess = null;
            $token->id = $DB->insert_record('external_tokens', $token);

            $eventtoken = clone $token;
            $eventtoken->privatetoken = null;
            $params = [
                'objectid' => $eventtoken->id,
                'relateduserid' => $tokenuser->id,
                'other' => [
                    'auto' => true,
                ],
            ];
            $event = webservice_token_created::create($params);
            $event->add_record_snapshot('external_tokens', $eventtoken);
            $event->trigger();
        } else {
            return false;
        }
        return $token;
    }

    /**
     * Returns the current environment based on the configuration.
     * If the environment is not valid, it returns the last environment in the list.
     *
     * @return string
     */
    public static function get_env() {
        $env = get_config('local_learnwise', 'environment');
        if (self::valid_env($env)) {
            return $env;
        }
        $envs = constants::ENVIRONMENTS;
        return end($envs);
    }

    /**
     * Validates the given environment against the predefined list of environments.
     *
     * @param string $env
     * @return bool
     */
    public static function valid_env($env) {
        return in_array($env, constants::ENVIRONMENTS);
    }

    /**
     * Returns the URL for the LTI tool based on the environment.
     * If the environment is not valid, it defaults to the last environment in the list.
     *
     * @param string|null $env
     * @return string
     */
    public static function get_ltitoolurl($env = null) {
        if (!self::valid_env($env)) {
            $env = self::get_env();
        }
        switch ($env) {
            case constants::ENVIRONMENTS[0]:
                return 'https://chat.learnwise.ai';
            case constants::ENVIRONMENTS[1]:
                return 'https://chat.learnwise.dev';
            case constants::ENVIRONMENTS[2]:
            default:
                return 'https://chat.sandbox.learnwise.dev';
        }
    }

    /**
     * Returns the URL for the LTI prefix based on the environment.
     * This is used to replace 'chat.' with 'lti.' in the LTI tool URL.
     *
     * @param string|null $env
     * @return string
     */
    public static function get_ltiprefixurl($env = null) {
        return str_replace('chat.', 'lti.', self::get_ltitoolurl($env));
    }

    /**
     * Returns the URL for the remote host based on the LTI tool URL.
     * This is used to replace 'chat.' with 'aiden.' in the LTI tool URL.
     *
     * @param string|null $env
     * @return string
     */
    public static function get_remotehosturl($env = null) {
        return str_replace('chat.', 'aiden.', self::get_ltitoolurl($env));
    }

    /**
     * Get role for api user (if not exists then create)
     * @return object
     */
    public static function get_or_create_role() {
        global $DB;

        $roleexist = $DB->get_record('role', ['shortname' => 'learnwise_assistant']);
        if (!empty($roleexist)) {
            return $roleexist;
        }

        $role = new stdClass();
        $role->name = get_string('learnwise:rolename', 'local_learnwise');
        $role->shortname = 'learnwise_assistant';
        $role->description = get_string('learnwise:roledescription', 'local_learnwise');
        $role->archetype = 'user';

        $role->id = create_role(
            $role->name,
            $role->shortname,
            $role->description,
            $role->archetype
        );

        $systemcontext = context_system::instance();
        set_role_contextlevels($role->id, [$systemcontext->contextlevel]);

        $capabilities = [
            'moodle/course:view',
            'moodle/course:viewhiddencourses',
            'webservice/rest:use',
            'moodle/webservice:createtoken',
            'moodle/course:update',
            'moodle/course:viewparticipants',
            'mod/forum:viewdiscussion',
            'mod/forum:viewqandawithoutposting',
            'mod/page:view',
            'mod/resource:view',
        ];

        foreach ($capabilities as $capability) {
            assign_capability(
                $capability,
                CAP_ALLOW,
                $role->id,
                SYSCONTEXTID,
                true
            );
        }

        return $role;
    }

    /**
     * Get api user (if not exists then create)
     * @return object
     */
    public static function get_or_create_apiuser() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');

        $existuserid = get_config('local_learnwise', 'tokenuserid');
        $existuser = $DB->get_record('user', ['id' => (int) $existuserid, 'deleted' => 0]);
        if (!empty($existuser)) {
            return $existuser;
        }

        $user = new stdClass();
        $user->username = 'learnwise_assistant_user';
        $user->firstname = 'Learnwise';
        $user->lastname = 'Assistant';
        $user->email = 'learnwise_assistant@example.com';
        $user->auth = 'webservice';
        $user->description = get_string('donotdelete', 'local_learnwise');
        $user->emailstop = 1;
        $user->confirmed = 1;
        $user->policyagreed = 1;
        $user->mnethostid = $CFG->mnet_localhost_id;
        $user->id = user_create_user(
            $user,
            false,
            false
        );

        set_config('tokenuserid', $user->id, 'local_learnwise');

        $role = self::get_or_create_role();
        if ($role) {
            $systemcontext = context_system::instance();
            role_assign($role->id, $user->id, $systemcontext->id);
        }

        return $user;
    }
}
