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

/**
 * Class implementing WS local_learnwise_upsertlti
 *
 * @package    local_learnwise
 * @copyright  2026 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_learnwise\external;

use context_system;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_learnwise\constants;
use local_learnwise\util;
use stdClass;

/**
 * Implementation of web service local_learnwise_upsertlti
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upsertlti extends baseapi {
    /**
     * Describes the parameters for local_learnwise_upsertlti
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'id', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'name', VALUE_DEFAULT),
            'assistantid' => new external_value(PARAM_ALPHANUMEXT, 'assistant id', VALUE_DEFAULT),
        ]);
    }

    /**
     * Implementation of web service local_learnwise_upsertlti
     *
     * @param int $id
     * @param string $name
     * @param string|null $assistantid
     */
    public static function execute($id, $name, $assistantid) {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        return self::upsert([
            'id' => $id,
            'name' => $name,
            'assistantid' => $assistantid,
        ]);
    }

    /**
     * Insert or update LTI configuration.
     *
     * @param array $params Parameters for upsert operation
     * @return stdClass The LTI type record
     */
    public static function upsert($params) {
        global $CFG;
        require_once($CFG->dirroot . '/mod/lti/lib.php');
        require_once($CFG->dirroot . '/mod/lti/locallib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            $params
        );

        $environment = util::get_env();
        $toolurl = util::get_ltitoolurl($environment);
        $ltiprefixurl = util::get_ltiprefixurl($environment);

        if ($ltityperecord = util::get_lti_data($params['id'])) {
            $ltidata = lti_get_type_type_config($params['id']);
            if (!empty($ltidata) && !empty($params['name'])) {
                $ltidata->lti_typename = $params['name'];
            }
        }

        if (empty($ltidata)) {
            $ltidata = new stdClass();
            $ltidata->tab = '';
            $ltidata->typeid = 0;
            $ltidata->course = get_site()->id;
            $ltidata->lti_typename = empty($params['name']) ? $params['name'] : 'Learnwise';
            $ltidata->oldicon = $ltidata->lti_icon = $ltidata->lti_secureicon = '';
            $ltidata->lti_description = '';
            $ltidata->lti_coursevisible = LTI_COURSEVISIBLE_ACTIVITYCHOOSER;
            $ltidata->lti_launchcontainer = LTI_LAUNCH_CONTAINER_EMBED_NO_BLOCKS;
            $ltidata->lti_contentitem = 0;
            $ltidata->ltiservice_gradesynchronization = 0;
            $ltidata->ltiservice_memberships = 0;
            $ltidata->ltiservice_toolsettings = 0;
            $ltidata->lti_sendname = 1;
            $ltidata->lti_sendemailaddr = 1;
            $ltidata->lti_acceptgrades = 2;
            $ltidata->lti_forcessl = 1;
            $ltidata->lti_organizationid_default = LTI_DEFAULT_ORGID_SITEID;
            $ltidata->lti_organizationid = '';
            $ltidata->lti_organizationurl = '';
        }

        $ltidata->lti_typename = $params['name'];
        $ltidata->lti_toolurl = $toolurl;
        $ltidata->lti_publickeyset = $ltiprefixurl . '/lti/jwks';
        $ltidata->lti_initiatelogin = $ltiprefixurl . '/lti';
        $ltidata->lti_redirectionuris = $ltiprefixurl . '/lti';
        $ltidata->lti_ltiversion = LTI_VERSION_1P3;
        $ltidata->lti_keytype = LTI_JWK_KEYSET;
        $ltidata->lti_customparameters = "assistant_id={$params['assistantid']}
course_id=\$Context.id";
        lti_load_type_if_cartridge($ltidata);

        $ltitype = new stdClass();
        $ltitype->state = LTI_TOOL_STATE_CONFIGURED;

        if (empty($ltidata->typeid)) {
            $ltitypeid = lti_add_type($ltitype, $ltidata);
        } else {
            $ltitypeid = $ltitype->id = $ltidata->typeid;
            if (empty($params['assistantid'])) {
                unset($ltidata->lti_customparameters);
            }
            lti_update_type($ltitype, $ltidata);
        }

        $ltityperecord = util::get_lti_data($ltitypeid);

        $typeids = get_config(constants::COMPONENT, 'ltitypeids');
        if (empty($typeids)) {
            $typeids = '';
        }
        $typeids = array_filter(explode(',', $typeids));
        if (!in_array($ltityperecord->id, $typeids)) {
            $typeids[] = $ltityperecord->id;
            set_config('ltitypeids', join(',', $typeids), constants::COMPONENT);
        }

        return $ltityperecord;
    }

    /**
     * Define api supports single operation
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * Returns the structure for a single operation.
     *
     * @return external_single_structure The structure for the API response.
     */
    public static function single_structure() {
        global $CFG;
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'lti id'),
            'assistantid' => new external_value(PARAM_ALPHANUMEXT, 'lti assistant id'),
            'data' => new external_single_structure([
                'platformid' => new external_value(PARAM_URL, 'platformid', VALUE_DEFAULT, $CFG->wwwroot),
                'clientid' => new external_value(PARAM_RAW, 'clientid'),
                'deploymentid' => new external_value(PARAM_TEXT, 'deploymentid'),
                'publickeyseturl' => new external_value(
                    PARAM_URL,
                    'publickeyseturl',
                    VALUE_DEFAULT,
                    $CFG->wwwroot . '/mod/lti/certs.php'
                ),
                'accesstokenurl' => new external_value(
                    PARAM_URL,
                    'accesstokenurl',
                    VALUE_DEFAULT,
                    $CFG->wwwroot . '/mod/lti/token.php'
                ),
                'authrequesturl' => new external_value(
                    PARAM_URL,
                    'accesstokenurl',
                    VALUE_DEFAULT,
                    $CFG->wwwroot . '/mod/lti/auth.php'
                ),
            ]),
        ]);
    }
}
