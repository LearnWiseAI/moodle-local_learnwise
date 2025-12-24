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

use local_learnwise\constants;
use moodleform;
use stdClass;

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Class permission
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class permission extends moodleform {
    /**
     * Summary of definition
     * @return void
     */
    public function definition() {
        $mform = $this->_form;
        $authquestiontext = get_string('permission_question', constants::COMPONENT);
        $mform->addElement('html', $authquestiontext);

        $scopetext = get_string('permission_list', constants::COMPONENT);
        $mform->addElement('html', $scopetext);

        $this->add_action_buttons(true, get_string('confirm'));
    }

    /**
     * Summary of process_form_submission
     * @return bool|null
     */
    public function process_form_submission() {
        global $DB, $USER;
        if ($this->is_permission_allowed()) {
            return true;
        }
        if (!$this->is_submitted()) {
            return null;
        }
        if ($this->is_cancelled()) {
            return false;
        }
        $data = $this->get_data();
        if (!$data) {
            return false;
        }
        $client = $this->get_client();
        $record = new stdClass();
        $record->clientid = !empty($client) ? $client->id : 0;
        $record->userid = $USER->id;
        $record->id = $DB->insert_record('local_learnwise_userauth', $record);
        return !empty($record->id);
    }

    /**
     * Summary of is_permission_allowed
     * @return bool
     */
    public function is_permission_allowed() {
        global $DB, $USER;
        $client = $this->get_client();
        if (empty($client)) {
            return false;
        }
        return $DB->record_exists('local_learnwise_userauth', ['clientid' => $client->id, 'userid' => $USER->id]);
    }

    /**
     * Summary of get_client
     * @return stdClass|false
     */
    public function get_client() {
        global $DB;
        $clientid = $this->optional_param('client_id', null, PARAM_ALPHANUM);
        if (empty($clientid)) {
            return false;
        }
        return $DB->get_record('local_learnwise_clients', ['uniqid' => $clientid]);
    }

    /**
     * Checks if a parameter was passed in the previous form submission
     *
     * @param string $name the name of the page parameter we want
     * @param mixed  $default the default value to return if nothing is found
     * @param string $type expected type of parameter
     * @return mixed
     */
    public function optional_param($name, $default, $type) {
        if (method_exists(moodleform::class, 'optional_param')) {
            return parent::optional_param($name, $default, $type);
        }
        if (isset($this->_ajaxformdata[$name])) {
            return clean_param($this->_ajaxformdata[$name], $type);
        } else {
            return optional_param($name, $default, $type);
        }
    }
}
