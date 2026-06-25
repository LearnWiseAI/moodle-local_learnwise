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

use context;
use local_learnwise\constants;
use local_learnwise\external\upsertlti;
use local_learnwise\util;
use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Class updatelti
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class updatelti extends moodleform {
    /**
     * Summary of definition
     * @return void
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required');

        $mform->addElement('text', 'assistantid', get_string('assistantid', constants::COMPONENT));
        $mform->setType('assistantid', PARAM_ALPHANUMEXT);
        $mform->addRule('assistantid', get_string('required'), 'required');

        $mform->addElement('submit', 'submitbutton', get_string('add'));
        $mform->closeHeaderBefore('submitbutton');

        $this->set_display_vertical();
    }

    /**
     * Sets the submit button default after set_data is called.
     *
     * @return void
     */
    public function definition_after_data() {
        $mform = $this->_form;
        $id = $mform->exportValue('id');
        if ($id > 0) {
            $mform->getElement('submitbutton')->setValue(get_string('update'));
        }
    }

    /**
     * Returns the context used for dynamic submission.
     *
     * @return context
     */
    public function get_context_for_dynamic_submission() {
        global $PAGE;
        return $PAGE->context;
    }

    /**
     * Checks access for dynamic submission.
     *
     * @return void
     */
    public function check_access_for_dynamic_submission() {
        require_capability('moodle/site:config', $this->get_context_for_dynamic_submission());
    }

    /**
     * Loads data for dynamic submission.
     *
     * @return void
     */
    public function set_data_for_dynamic_submission() {
        $id = $this->optional_param('id', 0, PARAM_INT);
        $formdata = [];
        if ($id > 0) {
            $formdata += (array) util::get_lti_data($id);
        }
        $this->set_data($formdata);
    }

    /**
     * Processes the form data for dynamic submission.
     *
     * @return mixed|null
     */
    public function process_dynamic_submission() {
        if (!$this->is_cancelled() && $this->is_submitted() && $this->is_validated()) {
            $params = upsertlti::prepare_input_params(
                $this->get_data()
            );
            return upsertlti::upsert($params);
        }
        return null;
    }
}
