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

defined('MOODLE_INTERNAL') || die();

use context_system;
use Exception;
use html_table;
use html_table_cell;
use html_table_row;
use html_writer;
use local_learnwise\constants;
use local_learnwise\util;
use moodleform;
use stdClass;
use webservice;

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Class webservicesetup
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class webservicesetup extends moodleform {

    /**
     * Summary of definition
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $currenttoken = util::get_or_generate_token_for_user('learnwise', false);

        if (!empty($currenttoken)) {
            $this->_customdata['currenttoken'] = $currenttoken;
            $htmltable = $this->get_htmltable();

            $mform->addElement('html', html_writer::table($htmltable));

            $mform->addElement('submit', 'removewebservicesetup', get_string('removetoken', constants::COMPONENT), [
                'id' => 'id_removetoken',
            ]);
        }

        $mform->addElement('submit', 'setupwebservicesetup', get_string('setuptoken', constants::COMPONENT));
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
        if (empty($this->_customdata['currenttoken'])) {
            return null;
        }
        $currenttoken = $this->_customdata['currenttoken'];
        $htmltable = new html_table();
        $htmltable->data[] = $htmltablerow = new html_table_row();
        $htmltablerow->cells[] = $htmltablecell = new html_table_cell(get_string('externaltoken', constants::COMPONENT));
        $htmltablecell->header = true;
        $htmltablerow->cells[] = $htmltablecell = new html_table_cell();
        $htmltablecell->text = util::generate_copy_input($currenttoken->token);
        return $htmltable;
    }

    /**
     * Summary of update_from_formdata
     * @param \stdClass $formdata
     * @return bool
     */
    public function update_from_formdata(stdClass $formdata) {
        global $CFG;
        if (!empty($formdata->setupwebservicesetup)) {
            set_config('enablewebservices', true);
            $webservicemanager = new webservice();

            $extservice = $webservicemanager->get_external_service_by_shortname('learnwise');
            if (!$extservice) {
                throw new Exception("Service not found");
            }
            if (!$extservice->enabled) {
                $extservice->enabled = 1;
                $webservicemanager->update_external_service($extservice);
            }

            $activeprotocols = empty($CFG->webserviceprotocols) ? [] : explode(',', $CFG->webserviceprotocols);

            if (!in_array('rest', $activeprotocols)) {
                $activeprotocols[] = 'rest';
                $updateprotocol = true;
            }

            if (!empty($updateprotocol)) {
                set_config('webserviceprotocols', implode(',', $activeprotocols));
            }

            if (!empty($CFG->defaultuserroleid)) {
                $systemcontext = context_system::instance();
                assign_capability('webservice/rest:use', CAP_ALLOW, $CFG->defaultuserroleid, $systemcontext->id, true);
            }

            if ($extservice->restrictedusers) {
                $admin = get_admin();
                $wsauthorizedusers = $webservicemanager->get_ws_authorised_users($extservice->id);
                $authorizeuserfound = false;
                foreach ($wsauthorizedusers as $user) {
                    if ($user->id == $admin->id) {
                        if (empty($user->validuntil) || $user->validuntil > time()) {
                            $authorizeuserfound = true;
                            break;
                        } else {
                            $webservicemanager->remove_ws_authorised_user($user, $extservice->id);
                        }
                    }
                }
                if (empty($authorizeuserfound)) {
                    $serviceuser = new stdClass();
                    $serviceuser->externalserviceid = $extservice->id;
                    $serviceuser->userid = $admin->id;
                    $webservicemanager->add_ws_authorised_user($serviceuser);
                }
            }
            if (empty($this->_customdata['currenttoken'])) {
                $this->_customdata['currenttoken'] = util::get_or_generate_token_for_user('learnwise', true);
            }
            return true;
        } else if (!empty($formdata->removewebservicesetup)) {
            $webservicemanager = new webservice();
            $extservice = $webservicemanager->get_external_service_by_shortname('learnwise');
            if (!$extservice) {
                throw new Exception("Service not found");
            }
            if ($extservice->enabled) {
                $extservice->enabled = 0;
                $webservicemanager->update_external_service($extservice);
            }
            $currenttoken = util::get_or_generate_token_for_user('learnwise', false);
            if (!empty($currenttoken)) {
                $webservicemanager->delete_user_ws_token($currenttoken->id);
            }
            $this->_customdata['currenttoken'] = null;
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
        if (empty($this->_customdata['currenttoken'])) {
            return null;
        }
        $currenttoken = $this->_customdata['currenttoken'];
        return $OUTPUT->render_from_template('local_learnwise/webservicetoken', [
            'token' => $currenttoken->token,
        ]);
    }

}
