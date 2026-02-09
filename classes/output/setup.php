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

use invalid_parameter_exception;
use lang_string;
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
     * @var array
     */
    protected $errors;

    /**
     * @var stdClass
     */
    protected $formvalues;

    /**
     * Constructor setup
     */
    public function __construct() {
        $config = get_config('local_learnwise');
        $env = constants::get_env();
        $clientcreds = util::get_or_generate_client();
        $this->formvalues = new stdClass();
        $this->formvalues->floatingButtonAssistantId = !empty($config->assistantid) ? $config->assistantid : '';
        $this->formvalues->courseIds = !empty($config->courseids) ? $config->courseids : '';
        $this->formvalues->liveApiConfigClientId = $clientcreds->uniqid;
        $this->formvalues->liveApiConfigClientSecret = $clientcreds->secret;
        $this->formvalues->liveApiConfigRedirectURLs = !empty($config->redirecturl) ? $config->redirecturl : '';
        $this->formvalues->floatingButtonStatus = !empty($config->showassistantwidget);
        $this->formvalues->ltiStatus = !empty($config->ltisetup);
        $this->formvalues->webServicesStatus = !empty($config->webservices);
        $this->formvalues->liveApiStatus = !empty($config->liveapi);
        $this->formvalues->evironment = $env;
        $this->formvalues->region = !empty($config->region) ? $config->region : constants::REGION;
        $this->formvalues->ltiAssistantId = !empty($config->ltiassistantid) ? $config->ltiassistantid : '';
        $this->formvalues->aiAssessmentStatus = !empty($config->aiassessment);
        $this->formvalues->aiAssessmentAssistantId = !empty($config->aiassessmentassistantid) ?
            $config->aiassessmentassistantid : '';
    }

    /**
     * Set form data
     *
     * @param stdClass $postdata submitted data
     * @return void
     */
    public function update_formvalues(stdClass $postdata) {
        foreach ((array) $this->formvalues as $prop => $notused) {
            if (isset($postdata->{$prop})) {
                $this->formvalues->{$prop} = $postdata->{$prop};
            }
        }
    }

    /**
     * Validates input data
     *
     * @return bool
     *
     */
    public function validate() {
        $plugin = 'local_learnwise';
        $paramtypemap = [
            'floatingButtonAssistantId' => [
                PARAM_ALPHANUM,
                new lang_string('assistantid', $plugin),
            ],
            'courseIds' => [
                PARAM_SEQUENCE,
                new lang_string('courseids', $plugin),
            ],
            'floatingButtonStatus' => [
                PARAM_BOOL,
                new lang_string('showfloatingbutton', $plugin),
            ],
            'webServicesStatus' => [
                PARAM_BOOL,
                new lang_string('coursecontentsintegration', $plugin),
            ],
            'liveApiStatus' => [
                PARAM_BOOL,
                new lang_string('liveapiintegration', $plugin),
            ],
            'liveApiConfigRedirectURLs' => [
                function ($value) {
                    $lines = preg_split("/\r\n|\n/", $value);
                    foreach ($lines as $line) {
                        try {
                            validate_param($line, PARAM_URL);
                        } catch (invalid_parameter_exception $e) {
                            return false;
                        }
                    }
                    return true;
                },
                new lang_string('redirecturl', $plugin),
            ],
            'environment' => [
                function ($value) {
                    return in_array($value, constants::ENVIRONMENTS);
                },
                new lang_string('environment', $plugin),
            ],
            'floatingButtonRegion' => [
                function ($value) {
                    return in_array($value, constants::region_options());
                },
                new lang_string('region', $plugin),
            ],
            'aiAssessmentStatus' => [
                PARAM_BOOL,
                new lang_string('aiassessment', $plugin),
            ],
            'aiAssessmentAssistantId' => [
                PARAM_ALPHANUM,
                new lang_string('assistantid', $plugin),
            ],
        ];
        if ($this->showltisetup()) {
            $paramtypemap['ltiStatus'] = [
                PARAM_BOOL,
                new lang_string('enablelti', $plugin),
            ];
            $paramtypemap['ltiAssistantId'] = [
                PARAM_ALPHANUM,
                new lang_string('assistantid', $plugin),
            ];
        }
        $this->errors = [];
        foreach ($paramtypemap as $key => $paramvalidation) {
            if (isset($this->formvalues->{$key})) {
                $value = $this->formvalues->{$key};
                $paramtypevalidation = $paramvalidation[0];
                $paramtypename = $paramvalidation[1];
                if (is_callable($paramtypevalidation)) {
                    $validationresult = call_user_func($paramtypevalidation, $value);
                } else {
                    try {
                        $validationresult = validate_param($value, $paramtypevalidation);
                    } catch (invalid_parameter_exception $e) {
                        $validationresult = false;
                    }
                }
                if ($validationresult === false) {
                    $this->errors[$key] = get_string(
                        'fieldvalidationerror',
                        $plugin,
                        [
                            'field' => (string) $paramtypename,
                        ]
                    );
                }
            }
        }
        return empty($this->errors);
    }

    /**
     * Exports data for use in a mustache template.
     *
     * @param renderer_base $output The renderer to be used for output.
     * @return stdClass Data to be used by the template.
     */
    public function export_for_template(renderer_base $output) {
        $env = $this->formvalues->evironment;
        $data = clone($this->formvalues);
        $data->showltisetup = $this->showltisetup();
        $data->showtoast = $this->showtoast();
        $data->envProduction = $env === constants::ENVIRONMENTS[0];
        $data->envDevelopment = $env === constants::ENVIRONMENTS[1];
        $data->envSandbox = $env === constants::ENVIRONMENTS[2];
        $data->regionOptions = array_map(function ($r) {
            return [
                'key' => $r,
                'value' => strtoupper($r),
                'selected' => $this->formvalues->region === $r,
            ];
        }, constants::region_options());

        if (!$data->showltisetup) {
            unset($data->ltiAssistantId);
        }
        if ($data->webServicesStatus) {
            $form = new webservicesetup();
            $data->webServicesConfigHtml = $form->render_widget();
        }
        $data->errors = $this->errors;
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
            $formdata = new stdClass();
            $formdata->removewebservicesetup = true;
            $form->update_from_formdata($formdata);
        }
        if ($this->showltisetup()) {
            set_config('ltisetup', $postdata->ltiStatus, $plugin);
            set_config('ltiassistantid', $postdata->ltiAssistantId, $plugin);
            if (empty($postdata->ltiStatus)) {
                $form = new assistantinput();
                $formdata = new stdClass();
                $formdata->removeltisetup = true;
                if (empty($formdata->assistantid)) {
                    $formdata->assistantid = '';
                }
                $form->update_from_formdata($formdata);
            } else {
                $form = new assistantinput();
                $formdata = new stdClass();
                $formdata->setupltisetup = true;
                if (empty($formdata->assistantid)) {
                    $formdata->assistantid = '';
                }
                $form->update_from_formdata($formdata);
            }
        }
        set_config('aiassessment', $postdata->aiAssessmentStatus, $plugin);
        set_config('aiassessmentassistantid', $postdata->aiAssessmentAssistantId, $plugin);
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
