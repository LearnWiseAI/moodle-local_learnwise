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

namespace local_learnwise\external\forum;

use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;

/**
 * Class singlediscussion
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class singlediscussion extends baseapi {
    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'discussions';

    #[\Override]
    public static function description() {
        return 'Get single forum discussion';
    }

    #[\Override]
    public static function execute_parameters() {
        return static::base_parameters([
            'id' => new external_value(PARAM_INT, 'Discussion id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $id ID of discussion
     * @return array
     */
    public static function execute($id) {
        global $DB;
        $params = static::validate_parameters(static::execute_parameters(), [
            'id' => $id,
        ]);

        $discussion = $DB->get_record('forum_discussions', ['id' => $params['id']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('forum', $discussion->forum, $discussion->course, false, MUST_EXIST);
        discussions::set_id($discussion->id);

        return discussions::execute($cm->course, $cm->id);
    }

    #[\Override]
    public static function single_structure() {
        $discussionstructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id of discussion'),
            'name' => new external_value(PARAM_TEXT, 'name of discussion'),
        ]);
        $discussionstructure->keys['posts'] = new external_multiple_structure(
            discussions::post_structure()
        );
        return $discussionstructure;
    }

    #[\Override]
    public static function is_singleoperation() {
        return true;
    }
}
