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

use local_learnwise\form\assistantinput;
use local_learnwise\form\webservicesetup;

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
    if (isset($formdata['enablelti'])) {
        $formdata['setupltisetup'] = $formdata['removeltisetup'] = false;
        if (!empty($formdata['enablelti'])) {
            $formdata['setupltisetup'] = true;
        } else {
            $formdata['removeltisetup'] = true;
        }
        $formidentifier = str_replace('\\', '_', assistantinput::class);
        $formdata['_qf__' . $formidentifier] = 1;
        $customdata = !empty($formdata['environment']) ? $formdata : null;
        $form = new assistantinput(null, $customdata, 'post', '', null, true, $formdata);
        if ($form->process_form_submission() && $formdata['setupltisetup']) {
            $o .= $form->render_widget();
        }
    }

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
