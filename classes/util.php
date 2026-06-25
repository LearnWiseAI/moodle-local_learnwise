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
use core_plugin_manager;
use Exception;
use external_util;
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
     * @var array Array of capabilities
     */
    const ROLECAPS = [
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
        'moodle/course:ignoreavailabilityrestrictions',
        'mod/assign:view',
        'mod/quiz:view',
        'mod/assign:grade',
        'moodle/user:viewdetails',
        'mod/assign:manageallocations',
        'mod/book:read',
        'local/learnwise:plugininfo',
    ];

    /**
     * @var array Array of regions that has region prefixed urls.
     */
    const LTIPREFIXEDREGIONS = ['ca', 'au'];

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
     * returns a token for the given service.
     * @param string $service
     * @param bool $create
     * @return stdClass|false
     */
    public static function get_or_generate_token_for_user($service, $create = true) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/webservice/lib.php');
        $wsmanager = new webservice();
        $extservice = self::find_external_service($wsmanager, $service);
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
            $token->token = bin2hex(random_bytes(32));
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
     * Resolve a configured service and keep backward compatibility with legacy shortnames.
     *
     * @param webservice $wsmanager
     * @param string $service
     * @return stdClass|false
     */
    public static function find_external_service(webservice $wsmanager, string $service) {
        $shortnames = [$service];
        if ($service === constants::COMPONENT) {
            $shortnames[] = 'learnwise';
        } else if ($service === 'learnwise') {
            $shortnames[] = constants::COMPONENT;
        }

        foreach (array_unique($shortnames) as $shortname) {
            $extservice = $wsmanager->get_external_service_by_shortname($shortname);
            if (!empty($extservice)) {
                return $extservice;
            }
        }
        return false;
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
        $ltiurl = str_replace(
            ['chat.', 'lti.sandbox'],
            ['lti.', 'lti-sbx'],
            self::get_ltitoolurl($env)
        );
        $region = get_config('local_learnwise', 'region');
        if (empty($region)) {
            $region = constants::REGION;
        }
        if (!self::valid_env($env)) {
            $env = self::get_env();
        }
        if ($env === constants::ENVIRONMENTS[0] && in_array($region, self::LTIPREFIXEDREGIONS)) {
            $ltiurl = str_replace('lti.', "lti-{$region}.", $ltiurl);
        }
        return $ltiurl;
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

        foreach (self::ROLECAPS as $capability) {
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

        $role = self::get_or_create_role();
        $systemcontext = context_system::instance();
        $existuserid = get_config('local_learnwise', 'tokenuserid');
        $existuser = $DB->get_record('user', ['id' => (int) $existuserid, 'deleted' => 0]);
        if (!empty($existuser)) {
            if ($role && !user_has_role_assignment($existuser->id, $role->id, $systemcontext->id)) {
                role_assign($role->id, $existuser->id, $systemcontext->id);
            }
            return $existuser;
        }

        $user = new stdClass();
        $user->username = 'learnwise_assistant_user';
        $user->firstname = 'Learnwise';
        $user->lastname = 'Assistant';
        $user->email = 'noreply@learnwise.ai';
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

        if ($role) {
            $systemcontext = context_system::instance();
            role_assign($role->id, $user->id, $systemcontext->id);
        }

        return $user;
    }

    /**
     * Returns the URL for the assessment host based on the LTI tool URL.
     * This is used to replace 'chat.' with 'feedback.' in the LTI tool URL.
     *
     * @param string|null $env
     * @return string
     */
    public static function get_assessmenthosturl($env = null) {
        global $CFG;
        $assessmenthost = get_config(constants::COMPONENT, 'assessmenthost');
        if (!empty($CFG->learnwisedevmode) && !empty($assessmenthost)) {
            return $assessmenthost;
        }
        return str_replace('chat.', 'feedback.', self::get_ltitoolurl($env));
    }

    /**
     * Returns installed plugin version
     *
     * @param string|null $component The plugin component
     * @return \core\plugininfo\base
     */
    public static function get_plugin_versioninfo($component = null) {
        if (empty($component)) {
            $component = static::component();
        }
        $pluginmanager = core_plugin_manager::instance();
        return $pluginmanager->get_plugin_info($component);
    }

    /**
     * Factory method to prepare response
     *
     * @param array $headers Headers in <key, value> pair
     * @return api_response Response with version header
     */
    public static function make_response($headers = []) {
        $headers = (array) $headers;
        $headers['X-version'] = static::get_plugin_versioninfo()->release;
        $response = new api_response();
        $response->setHttpHeaders($headers);
        return $response;
    }

    /**
     * Utility method to extract pluginfile urls
     *
     * @param string $text Text from which urls extracted
     * @param int $contextid file contextid
     * @param string $component file component
     * @param string $filearea file area
     * @param int|null $itemid file item id
     * @return array Urls extracted
     */
    public static function extract_pluginfile_urls_from_text($text, $contextid, $component, $filearea, $itemid) {
        global $CFG;
        require_once($CFG->libdir . '/externallib.php');
        require_once($CFG->libdir . '/filelib.php');
        $text = file_rewrite_pluginfile_urls(
            $text,
            'pluginfile.php',
            $contextid,
            $component,
            $filearea,
            $itemid
        );
        $text = str_replace('/webservice/pluginfile.php/', '/pluginfile.php/', $text);
        return array_filter(array_map(
            function ($file) use ($text) {
                $fileurl = str_replace('/intro/0/', '/intro/', $file['fileurl']);
                $fileurl = str_replace('/webservice/pluginfile.php/', '/pluginfile.php/', $fileurl);
                return (strpos($text, $fileurl) !== false) ? $fileurl : null;
            },
            external_util::get_area_files($contextid, $component, $filearea, $itemid ? $itemid : 0)
        ));
    }

    /**
     * Get lti configuration data
     *
     * @param int|string $ltitypeid
     * @return object|null
     */
    public static function get_lti_data($ltitypeid) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $ltityperecord = lti_get_type($ltitypeid);
        if (!$ltityperecord) {
            return null;
        }
        $ltityperecord->data = get_tool_type_config($ltityperecord);
        $ltityperecord->assistantid = null;
        $ltityperecord->config = $config = lti_get_type_config($ltityperecord->id);
        if (
            !empty($config['customparameters']) &&
            preg_match('/assistant_id=(?<assistantid>.*)[\n]?course_id/m', $config['customparameters'], $match)
        ) {
            $ltityperecord->assistantid = $match['assistantid'];
        }
        $ltityperecord->templatedata = [
            'id' => $ltityperecord->id,
            'name' => $ltityperecord->name,
            'assistantid' => $ltityperecord->assistantid,
            'data' => [
                [
                    'index' => 'tooldetailsplatformid-' . $ltityperecord->id,
                    'header' => get_string('tooldetailsplatformid', 'lti'),
                    'value' => $ltityperecord->data['platformid'],
                ],
                [
                    'index' => 'tooldetailsclientid-' . $ltityperecord->id,
                    'header' => get_string('tooldetailsclientid', 'lti'),
                    'value' => $ltityperecord->data['clientid'],
                ],
                [
                    'index' => 'tooldetailsdeploymentid-' . $ltityperecord->id,
                    'header' => get_string('tooldetailsdeploymentid', 'lti'),
                    'value' => $ltityperecord->id,
                ],
                [
                    'index' => 'tooldetailspublickeyseturl-' . $ltityperecord->id,
                    'header' => get_string('tooldetailspublickeyseturl', 'lti'),
                    'value' => $ltityperecord->data['publickeyseturl'],
                ],
                [
                    'index' => 'tooldetailsaccesstokenurl-' . $ltityperecord->id,
                    'header' => get_string('tooldetailsaccesstokenurl', 'lti'),
                    'value' => $ltityperecord->data['accesstokenurl'],
                ],
                [
                    'index' => 'tooldetailsauthrequesturl-' . $ltityperecord->id,
                    'header' => get_string('tooldetailsauthrequesturl', 'lti'),
                    'value' => $ltityperecord->data['authrequesturl'],
                ],
            ],
        ];

        return $ltityperecord;
    }

    /**
     * Check if an array is a PHP list without relying on PHP 8.1 array_is_list().
     *
     * @param array $data Data to inspect
     * @return bool
     */
    public static function array_is_list(array $data): bool {
        return array_keys($data) === range(0, count($data) - 1);
    }
}
