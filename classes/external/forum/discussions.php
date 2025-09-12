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

use context_course;
use context_module;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\external\baseapi;
use local_learnwise\external\timestampvalue;

/**
 * Class discussions
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class discussions extends baseapi {

    /**
     * The name of the API.
     *
     * @var string
     */
    public static $route = 'discussions';

    /**
     * Returns the parameters for the execute function.
     *
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'forumid' => new external_value(PARAM_INT, 'Forum id'),
        ]);
    }

    /**
     * Returns the description of the execute function.
     *
     * @param int $courseid ID of course
     * @param int $forumid ID of course module
     * @return array
     */
    public static function execute($courseid, $forumid) {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
            'forumid' => $forumid,
        ]);

        $cm = get_coursemodule_from_id('forum', $params['forumid'], $params['courseid']);

        $context = context_course::instance($params['courseid']);
        if (static::is_singleoperation()) {
            $context = context_module::instance($cm->id);
        }
        static::validate_context($context);
        if (static::is_singleoperation()) {
            require_capability('mod/forum:viewdiscussion', $context);
        }

        $vaultfactory = \mod_forum\local\container::get_vault_factory();
        $forumvault = $vaultfactory->get_forum_vault();
        $forum = $forumvault->get_from_id($cm->instance);

        $discussions = [];
        $alldiscussions = mod_forum_get_discussion_summaries($forum, $USER, 0, -1);
        foreach ($alldiscussions as $discussionsummary) {
            $discussion = $discussionsummary->get_discussion();
            if (self::skip_record($discussion->get_id())) {
                continue;
            }
            $resultdiscussion = [];
            $resultdiscussion['id'] = $discussion->get_id();
            $resultdiscussion['name'] = $discussion->get_name();
            if (self::is_singleoperation()) {
                $postvault = $vaultfactory->get_post_vault();
                $canviewprivatereplay = false;
                $managerfactory = \mod_forum\local\container::get_manager_factory();
                if (!empty(baseapi::$my)) {
                    $capabilitymanager = $managerfactory->get_capability_manager($forum);
                    $canviewprivatereplay = $capabilitymanager->can_view_any_private_reply($USER);
                }
                $posts = $postvault->get_from_discussion_id(
                    $USER,
                    $discussion->get_id(),
                    $canviewprivatereplay,
                    "created DESC"
                );
                $resultdiscussion['posts'] = [];
                foreach ($posts as $post) {
                    $resultpost = [];
                    $resultpost['id'] = $post->get_id();
                    $resultpost['subject'] = $post->get_subject();
                    $resultpost['message'] = strip_tags($post->get_message());
                    $resultpost['parentid'] = $post->get_parent_id();
                    $resultpost['timecreated'] = $post->get_time_created();
                    $resultdiscussion['posts'][] = $resultpost;
                }

                return $resultdiscussion;
            }
            $discussions[] = $resultdiscussion;
        }
        return $discussions;
    }


    /**
     * Summary of single_structure
     * @return external_single_structure
     */
    public static function single_structure() {
        $discussionstructure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id of discussion'),
            'name' => new external_value(PARAM_TEXT, 'name of discussion'),
        ]);
        if (self::is_singleoperation()) {
            $discussionstructure->keys['posts'] = new external_multiple_structure(
                self::post_structure()
            );
        }
        return $discussionstructure;
    }


    /**
     * Returns the structure of a single post.
     *
     * @return \external_single_structure
     */
    public static function post_structure() {
        $structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'discussion post id'),
            'subject' => new external_value(PARAM_TEXT, 'post subject'),
            'message' => new external_value(PARAM_RAW, 'post message'),
            'parentid' => new external_value(PARAM_INT, 'post parent id'),
            'timecreated' => new external_value(PARAM_INT, 'posted time in unix format'),
        ]);
        $structure->keys['timecreated'] = timestampvalue::make($structure->keys['timecreated']);
        return $structure;
    }

}
