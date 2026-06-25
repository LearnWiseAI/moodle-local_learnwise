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
 * Callback implementations for Learnwise
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\form\webservicesetup;
use local_learnwise\output\setup;
use local_learnwise\util;

/**
 * Summary of local_learnwise_standard_footer_html
 *
 * @return string
 */
function local_learnwise_before_standard_top_of_body_html() {
    return \local_learnwise\hook_callbacks::before_standard_top_of_body_html_generation();
}

/**
 * Summary of local_learnwise_output_fragment_process_setup
 *
 * @param mixed $args
 * @return string
 */
function local_learnwise_output_fragment_process_setup($args) {
    $args = (object) $args;
    $context = $args->context;
    $formdata = [];
    parse_str($args->formdata, $formdata);

    require_capability('moodle/site:config', $context);

    $o = '';

    if (isset($formdata['enablewebservice'])) {
        $formdata['setupwebservicesetup'] = $formdata['removewebservicesetup'] = false;
        $formidentifier = str_replace('\\', '_', webservicesetup::class);
        $formdata['_qf__' . $formidentifier] = 1;
        if (!empty($formdata['enablewebservice'])) {
            $formdata['setupwebservicesetup'] = true;
        } else {
            $formdata['removewebservicesetup'] = true;
        }
        $form = new webservicesetup(null, null, 'post', '', null, true, $formdata);
        if ($form->process_form_submission() && $formdata['setupwebservicesetup']) {
            $o .= $form->render_widget();
        }
    }

    return $o;
}

/**
 * LTI Configuration Form
 * @param array $args
 * @return string
 */
function local_learnwise_output_fragment_form($args) {
    $args = (object) $args;
    $formdata = [];
    parse_str($args->formdata, $formdata);

    $formclass = $formdata['formclass'];
    $formurl = new moodle_url(get_local_referer());
    $formurl->param('formclass', $formclass);
    $formurl->param('action', $formdata['action']);

    $form = new $formclass($formurl, null, 'post', '', null, true, $formdata);
    $form->check_access_for_dynamic_submission();
    $form->set_data_for_dynamic_submission();

    $response = ['success' => false];
    if ($formresponse = $form->process_dynamic_submission()) {
        $response['success'] = true;
        $response['data'] = $formresponse;
    } else {
        $response['formhtml'] = $form->render();
    }
    return json_encode($response);
}

/**
 * Refresh LTI configuration
 * @param array $args
 * @return string
 */
function local_learnwise_output_fragment_refresh_lticonfig($args) {
    global $PAGE;
    $args = (object) $args;
    $html = '';
    $output = $PAGE->get_renderer(constants::COMPONENT);
    if ($args->action == 'refreshtable') {
        $setup = new setup();
        $templatedata = $setup->export_for_template($output);
        $html = $output->render_from_template(
            constants::COMPONENT . '/lticonfigtable',
            $templatedata
        );
    } else if ($args->action == 'refreshtablerow') {
        $ltityperecord = util::get_lti_data($args->id);
        if (!empty($ltityperecord)) {
            $html = $output->render_from_template(constants::COMPONENT . '/lticonfigrow', $ltityperecord->templatedata);
        }
    }
    return $html;
}
