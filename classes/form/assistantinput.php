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

namespace local_learnwise\form;

use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use local_learnwise\constants;
use local_learnwise\util;
use moodleform;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/mod/lti/lib.php');
require_once($CFG->dirroot . '/mod/lti/locallib.php');

/**
 * Class assistantinput
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assistantinput extends moodleform {
    /**
     * Summary of definition
     * @return void
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;

        if (!isset($this->_customdata['environment'])) {
            $this->_customdata['environment'] = util::get_env();
        }
        $this->_customdata['toolurl'] = util::get_ltitoolurl($this->_customdata['environment']);
        $tooldomain = lti_get_domain_from_url($this->_customdata['toolurl']);
        $ltitypeid = get_config('local_learnwise', 'ltitypeid');
        if (!empty($ltitypeid)) {
            $ltityperecord = $DB->get_record('lti_types', ['id' => $ltitypeid]);
        }
        if (empty($ltityperecord)) {
            $ltityperecord = $DB->get_record('lti_types', ['tooldomain' => $tooldomain]);
        }
        $this->_customdata['typerecord'] = $ltityperecord;

        if (!empty($ltityperecord)) {
            $ltityperecord->urls = get_tool_type_urls($ltityperecord);
            $ltitable = $this->get_htmltable();
            foreach ($ltitable->data as $htmlrow) {
                $htmlrow->cells[0]->header = true;
                $htmlrow->cells[1]->text = util::generate_copy_input($htmlrow->cells[1]->text);
            }

            $mform->addElement('html', html_writer::table($ltitable));
            $mform->addElement('submit', 'removeltisetup', get_string('removeltisetup', constants::COMPONENT), [
                'id' => 'id_removesetup',
            ]);
        }

        $mform->addElement('text', 'assistantid', get_string('assistantid', constants::COMPONENT));
        $mform->setType('assistantid', PARAM_TEXT);
        $mform->addRule('assistantid', null, 'required');

        $mform->addElement('submit', 'setupltisetup', get_string('setupltisetup', constants::COMPONENT));
    }

    /**
     * Summary of process_form_submission
     * @return bool|null
     */
    public function process_form_submission() {
        if (!$this->is_submitted()) {
            return null;
        }
        if ($this->is_cancelled()) {
            return false;
        }
        $formdata = $this->get_data();
        if (!$formdata) {
            return false;
        }
        return self::update_from_formdata($formdata);
    }

    /**
     * Summary of get_htmltable
     * @return html_table|null
     */
    public function get_htmltable() {
        global $CFG;
        if (empty($this->_customdata['typerecord'])) {
            return null;
        }
        $ltityperecord = $this->_customdata['typerecord'];
        $ltitable = new html_table();
        $ltitable->data[] = new html_table_row([
            new html_table_cell(get_string('tooldetailsplatformid', 'lti')),
            new html_table_cell($CFG->wwwroot),
        ]);
        $ltitable->data[] = new html_table_row([
            new html_table_cell(get_string('tooldetailsclientid', 'lti')),
            new html_table_cell($ltityperecord->clientid),
        ]);
        $ltitable->data[] = new html_table_row([
            new html_table_cell(get_string('tooldetailsdeploymentid', 'lti')),
            new html_table_cell($ltityperecord->id),
        ]);
        $ltitable->data[] = new html_table_row([
            new html_table_cell(get_string('tooldetailspublickeyseturl', 'lti')),
            new html_table_cell($ltityperecord->urls['publickeyset']),
        ]);
        $ltitable->data[] = new html_table_row([
            new html_table_cell(get_string('tooldetailsaccesstokenurl', 'lti')),
            new html_table_cell($ltityperecord->urls['accesstoken']),
        ]);
        $ltitable->data[] = new html_table_row([
            new html_table_cell(get_string('tooldetailsauthrequesturl', 'lti')),
            new html_table_cell($ltityperecord->urls['authrequest']),
        ]);
        return $ltitable;
    }

    /**
     * Summary of update_from_formdata
     * @param \stdClass $formdata
     * @return bool
     */
    public function update_from_formdata(stdClass $formdata) {
        if (!empty($formdata->setupltisetup)) {
            $ltiprefixurl = util::get_ltiprefixurl($this->_customdata['environment']);
            $ltidata = new stdClass();
            $ltidata->tab = '';
            $ltidata->typeid = 0;
            $ltidata->course = get_site()->id;
            $ltidata->oldicon = $ltidata->lti_icon = $ltidata->lti_secureicon = '';
            $ltidata->lti_typename = 'Learnwise';
            $ltidata->lti_toolurl = $this->_customdata['toolurl'];
            $ltidata->lti_description = '';
            $ltidata->lti_ltiversion = LTI_VERSION_1P3;
            $ltidata->lti_keytype = LTI_JWK_KEYSET;
            $ltidata->lti_publickeyset = $ltiprefixurl . '/lti/jwks';
            $ltidata->lti_initiatelogin = $ltiprefixurl . '/lti';
            $ltidata->lti_redirectionuris = $ltiprefixurl . '/lti';
            $ltidata->lti_customparameters = "assistant_id={$formdata->assistantid}
course_id=\$Context.id";
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
            lti_load_type_if_cartridge($ltidata);

            if (!get_config('local_learnwise', 'lticlientid')) {
                set_config('lticlientid', random_string(15), 'local_learnwise');
            }
            $ltidata->lti_clientid = get_config('local_learnwise', 'lticlientid');

            $ltitype = new stdClass();
            $ltitype->state = LTI_TOOL_STATE_CONFIGURED;

            if (!empty($this->_customdata['typerecord'])) {
                $ltitype->id = $this->_customdata['typerecord']->id;
            }
            if (empty($ltitype->id)) {
                $ltitypeid = lti_add_type($ltitype, $ltidata);
            } else {
                $ltitypeid = $ltitype->id;
                lti_update_type($ltitype, $ltidata);
            }
            if (!empty($ltitypeid)) {
                $ltityperecord = lti_get_type($ltitypeid);
                $ltityperecord->urls = get_tool_type_urls($ltityperecord);
                $this->_customdata['typerecord'] = $ltityperecord;
                set_config('ltitypeid', $ltitypeid, 'local_learnwise');
            }
            return true;
        } else if (!empty($formdata->removeltisetup) && !empty($this->_customdata['typerecord'])) {
            $typeid = $this->_customdata['typerecord']->id;
            $type = lti_get_type($typeid);

            if (!empty($type)) {
                lti_delete_type($typeid);
                set_config('ltitypeid', null, 'local_learnwise');

                // If this is the last type for this proxy then remove the proxy
                // as well so that it isn't orphaned.
                $types = lti_get_lti_types_from_proxy_id($type->toolproxyid);
                if (empty($types)) {
                    lti_delete_tool_proxy($type->toolproxyid);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Summary of render_widget
     * @return bool|mixed|string
     */
    public function render_widget() {
        global $OUTPUT;
        $templatedata = [];
        $table = $this->get_htmltable();
        foreach ($table->data as $i => $htmlrow) {
            $templatedata[] = [
                'index' => $i + 1,
                'header' => $htmlrow->cells[0]->text,
                'value' => $htmlrow->cells[1]->text,
            ];
        }
        return $OUTPUT->render_from_template(
            'local_learnwise/ltisetup',
            ['data' => $templatedata]
        );
    }
}
