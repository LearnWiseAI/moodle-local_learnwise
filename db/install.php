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
 * Install script for Learnwise
 *
 * Documentation: {@link https://moodledev.io/docs/guides/upgrade}
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Executed on installation of Learnwise
 *
 * @return bool
 */
function xmldb_local_learnwise_install() {
    global $CFG, $DB;
    local_learnwise\util::get_or_generate_client();

    // Services variable is populated by the included file below.
    include(dirname(__FILE__) . '/services.php');

    if (!empty($services)) {
        $servicekey = array_keys($services)[0];
        $service = (object) $services[$servicekey];
        $servicerecord = $DB->get_record('external_services', ['shortname' => $service->shortname]);
        if (empty($servicerecord)) {
            $servicerecord = $DB->get_record('external_services', ['name' => $servicekey]);
        }
        $currentcomponent = local_learnwise\util::component();

        // Check if manually created service exists.
        // If found then upgrade it to become component service.
        if (
            !empty($servicerecord) &&
            (
                ($servicerecord->component != $currentcomponent) ||
                ($servicerecord->name != $servicekey)
            )
        ) {
            $servicerecord->component = $currentcomponent;
            $servicerecord->shortname = $service->shortname;
            $servicerecord->name = $servicekey;
            $servicerecord->enabled = 1;
            $DB->update_record('external_services', $servicerecord);

            if (!empty($servicerecord->enabled)) {
                set_config('webservices', 1, $currentcomponent);

                // Check if api user already created.
                // If created then update user and set it to config.
                $apiuser = $DB->get_record('user', ['username' => 'learnwise_assistant_user', 'deleted' => 0]);
                if (!empty($apiuser)) {
                    $apiuser->firstname = 'Learnwise';
                    $apiuser->lastname = 'Assistant';
                    $apiuser->email = 'noreply@learnwise.ai';
                    $apiuser->auth = 'webservice';
                    $apiuser->description = get_string('donotdelete', $currentcomponent);
                    $apiuser->emailstop = 1;
                    $apiuser->confirmed = 1;
                    $apiuser->policyagreed = 1;
                    $apiuser->mnethostid = $CFG->mnet_localhost_id;
                    $DB->update_record('user', $apiuser);
                    set_config('tokenuserid', $apiuser->id, $currentcomponent);
                }

                $form = new local_learnwise\form\webservicesetup();
                $formdata = new stdClass();
                $formdata->setupwebservicesetup = true;
                $form->update_from_formdata($formdata);
            }
        }
    }

    return true;
}
