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
 * Upgrade functions for Learnwise
 *
 * @package    local_learnwise
 * @copyright  2026 YOUR NAME <your@email.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_learnwise\constants;
use local_learnwise\util;

/**
 * Sync new defined capabilites to api user
 *
 * @return void
 */
function local_learnwise_upgrade_sync_role_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/accesslib.php');

    update_capabilities(constants::COMPONENT);

    $role = util::get_or_create_role();

    foreach (util::ROLECAPS as $capability) {
        assign_capability(
            $capability,
            CAP_ALLOW,
            $role->id,
            SYSCONTEXTID,
            true
        );
    }
}
