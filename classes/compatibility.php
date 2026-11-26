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

namespace local_learnwise;

defined('MOODLE_INTERNAL') || die();

// NOTE: These class aliases depend on load order. They only exist after one of baseapi,
// timestampvalue, util or api_server is loaded. Any new class using core_external\*
// that does not extend baseapi should explicitly require compatibility.php or extend baseapi.

if (!class_exists(\core_external\external_api::class)) {
    require_once($CFG->libdir . '/externallib.php');
    class_alias(
        \external_api::class,
        \core_external\external_api::class
    );
}
if (!class_exists(\core_external\external_description::class)) {
    class_alias(
        \external_description::class,
        \core_external\external_description::class
    );
}
if (!class_exists(\core_external\external_value::class)) {
    class_alias(
        \external_value::class,
        \core_external\external_value::class
    );
}
if (!class_exists(\core_external\external_single_structure::class)) {
    class_alias(
        \external_single_structure::class,
        \core_external\external_single_structure::class
    );
}
if (!class_exists(\core_external\external_multiple_structure::class)) {
    class_alias(
        \external_multiple_structure::class,
        \core_external\external_multiple_structure::class
    );
}
if (!class_exists(\core_external\external_function_parameters::class)) {
    class_alias(
        \external_function_parameters::class,
        \core_external\external_function_parameters::class
    );
}
if (!class_exists(\core_external\external_format_value::class)) {
    class_alias(
        \external_format_value::class,
        \core_external\external_format_value::class
    );
}
if (!class_exists(\core_external\util::class)) {
    class_alias(
        \external_util::class,
        \core_external\util::class
    );
}

use context;

/**
 * Class compatibility
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait compatibility {
    /**
     * external_format_text polyfill
     *
     * @param string|null $text The content that may contain ULRs in need of rewriting.
     * @param string|int|null $textformat The text format.
     * @param int|\context $context This parameter and the next two identify the file area to use.
     * @param string|null $component
     * @param string|null $filearea helps identify the file area.
     * @param int|string|null $itemid helps identify the file area.
     * @param array|\stdClass|null $options text formatting options
     * @return array text + textformat
     */
    public static function external_format_text(
        $text,
        $textformat,
        $context,
        $component = null,
        $filearea = null,
        $itemid = null,
        $options = null
    ) {
        $callbackfn = 'external_format_text';
        if (is_callable('\core_external\util::format_text')) {
            if (is_int($context)) {
                $context = context::instance_by_id($context);
            }
            $callbackfn = '\core_external\util::format_text';
        }
        return call_user_func(
            $callbackfn,
            $text,
            $textformat,
            $context,
            $component,
            $filearea,
            $itemid,
            $options
        );
    }

    /**
     * user_create_user polyfill
     *
     * @param \stdClass|array $user user to create
     * @param bool $updatepassword if true, authentication plugin will update password.
     * @param bool $triggerevent set false if user_created event should not be triggered.
     * @return int id of the newly created user
     */
    public static function user_create_user($user, $updatepassword, $triggerevent) {
        $callbackfn = 'user_create_user';
        if (is_callable('\core\user::create_user')) {
            $callbackfn = '\core\user::create_user';
        }
        return call_user_func(
            $callbackfn,
            $user,
            $updatepassword,
            $triggerevent
        );
    }

    /**
     * user_can_view_profile polyfill
     *
     * @param object $user The other user's details.
     * @param object $course if provided, only check permissions in this course.
     * @param context $usercontext The user context if available.
     * @return bool true for ability to view this user, else false.
     */
    public static function user_can_view_profile($user, $course = null, $usercontext = null) {
        $callbackfn = 'user_can_view_profile';
        if (is_callable('\core\user::can_view_profile')) {
            $callbackfn = '\core\user::can_view_profile';
        }
        return call_user_func(
            $callbackfn,
            $user,
            $course,
            $usercontext
        );
    }

    /**
     * Tries to obtain user details, either from system profile or course profile.
     *
     * @param \stdClass $user The user.
     * @param array $userfields An array of userfields to be returned (optional)
     * @return array|null
     */
    public static function user_get_user_details_courses($user, array $userfields = []) {
        $callbackfn = 'user_get_user_details_courses';
        if (is_callable('\core\user::get_user_details_courses')) {
            $callbackfn = '\core\user::get_user_details_courses';
        }
        return call_user_func(
            $callbackfn,
            $user,
            $userfields
        );
    }
}
