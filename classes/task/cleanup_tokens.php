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

namespace local_learnwise\task;

use core\task\scheduled_task;
use local_learnwise\constants;

/**
 * Class cleanup_tokens
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_tokens extends scheduled_task {

    /**
     * Returns the name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('cleanuptokentask', constants::COMPONENT);
    }

    /**
     * Executes the task.
     *
     * @return void
     */
    public function execute() {
        global $DB;
        $time = time();
        $DB->delete_records_select('local_learnwise_authcode', 'timeexpiry < :time', ['time' => $time]);
        $DB->delete_records_select('local_learnwise_accesstoken', 'timeexpiry < :time', ['time' => $time]);
        $DB->delete_records_select('local_learnwise_refreshtoken', 'timeexpiry < :time', ['time' => $time]);
    }

}
