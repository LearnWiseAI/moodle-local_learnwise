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

namespace local_learnwise\output;

use local_learnwise\constants;
use local_learnwise\form\assistantinput;
use local_learnwise\form\webservicesetup;
use local_learnwise\util;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Class setup
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setup implements renderable, templatable {

    /**
     * Exports data for use in a mustache template.
     *
     * @param renderer_base $output The renderer to be used for output.
     * @return stdClass Data to be used by the template.
     */
    public function export_for_template(renderer_base $output) {
        $config = get_config('local_learnwise');
        $env = constants::get_env();
        $clientcreds = util::get_or_generate_client();
        $data = new stdClass;
        $data->floatingButtonAssistantId = $config->assistantid;
        $data->courseIds = $config->courseids;
        $data->liveApiConfigClientId = $clientcreds->uniqid;
        $data->liveApiConfigClientSecret = $clientcreds->secret;
        $data->liveApiConfigRedirectURLs = $config->redirecturl;
        $data->floatingButtonStatus = !empty($config->showassistantwidget);
        $data->ltiStatus = !empty($config->ltisetup);
        $data->webServicesStatus = !empty($config->webservices);
        $data->liveApiStatus = !empty($config->liveapi);
        $data->showltisetup = $this->showltisetup();
        $data->showtoast = $this->showtoast();
        $data->envProduction = $env === constants::ENVIRONMENTS[0];
        $data->envDevelopment = $env === constants::ENVIRONMENTS[1];
        $data->envSandbox = $env === constants::ENVIRONMENTS[2];
        if (empty($config->region)) {
            $config->region = constants::REGION;
        }
        $data->regionOptions = array_map(function($r) use ($config) {
            return [
                'key' => $r,
                'value' => strtoupper($r),
                'selected' => $config->region === $r,
            ];
        }, constants::regionOptions());

        if ($data->showltisetup) {
            $data->ltiAssistantId = $config->ltiassistantid;
        }
        if ($data->webServicesStatus) {
            $form = new webservicesetup();
            $data->webServicesConfigHtml = $form->render_widget();
        }
        return $data;
    }

    /**
     * Saves the provided post data.
     *
     * @param stdClass $postdata The data to be saved.
     * @return void
     */
    public function save(stdClass $postdata) {
        $plugin = 'local_learnwise';
        set_config('assistantid', $postdata->floatingButtonAssistantId, $plugin);
        set_config('courseids', $postdata->courseIds, $plugin);
        set_config('showassistantwidget', $postdata->floatingButtonStatus, $plugin);
        set_config('webservices', $postdata->webServicesStatus, $plugin);
        set_config('liveapi', $postdata->liveApiStatus, $plugin);
        set_config('redirecturl', $postdata->liveApiConfigRedirectURLs, $plugin);
        set_config('environment', $postdata->environment, $plugin);
        if (empty($postdata->floatingButtonRegion)) {
            $postdata->floatingButtonRegion = constants::REGION;
        }
        set_config('region', $postdata->floatingButtonRegion, $plugin);
        if (empty($postdata->webServicesStatus)) {
            $form = new webservicesetup();
            $formdata = new stdClass;
            $formdata->removewebservicesetup = true;
            $form->update_from_formdata($formdata);
        }
        if ($this->showltisetup()) {
            set_config('ltisetup', $postdata->ltiStatus, $plugin);
            set_config('ltiassistantid', $postdata->ltiAssistantId, $plugin);
            if (empty($postdata->ltiStatus)) {
                $form = new assistantinput();
                $formdata = new stdClass;
                $formdata->removeltisetup = true;
                if (empty($formdata->assistantid)) {
                    $formdata->assistantid = '';
                }
                $form->update_from_formdata($formdata);
            } else {
                $form = new assistantinput();
                $formdata = new stdClass;
                $formdata->setupltisetup = true;
                if (empty($formdata->assistantid)) {
                    $formdata->assistantid = '';
                }
                $form->update_from_formdata($formdata);
            }
        }
    }

    /**
     * Displays the LTI setup interface.
     *
     * This method is responsible for rendering the LTI setup page or interface
     * within the Learnwise local plugin output.
     *
     * @return bool
     */
    public function showltisetup() {
        global $CFG;
        return $CFG->branch >= 39;
    }

    /**
     * Displays a toast notification to the user.
     *
     * This method is responsible for rendering a toast message,
     * typically used for brief notifications or alerts.
     *
     * @return bool
     */
    public function showtoast() {
        global $CFG;
        return $CFG->branch >= 38;
    }

}
