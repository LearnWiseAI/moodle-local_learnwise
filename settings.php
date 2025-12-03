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
 * TODO describe file settings
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;

defined('MOODLE_INTERNAL') || die();

/* @phpstan-ignore variable.undefined */
if ($hassiteconfig) {
    $setup = new admin_externalpage(
        'local_learnwise_setup',
        get_string('integration_listpage', constants::COMPONENT),
        "{$CFG->wwwroot}/local/learnwise/setup.php",
        'moodle/site:config',
        false
    );
    $ADMIN->add('server', $setup);

    if (!empty($CFG->learnwisedevmode)) {
        $settings = new admin_settingpage('local_learnwise', new lang_string('pluginname', constants::COMPONENT));
        $ADMIN->add('localplugins', $settings);

        $settings->add(
            new admin_setting_configtext(
                'local_learnwise/assessmenthost',
                new lang_string('assessmenthost', constants::COMPONENT),
                '',
                '',
                PARAM_URL
            )
        );
    }
}
