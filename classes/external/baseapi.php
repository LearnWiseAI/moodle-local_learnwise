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

namespace local_learnwise\external;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use moodle_url;
use stored_file;

/**
 * Class baseapi
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class baseapi extends external_api {

    /**
     * The name of the API.
     *
     * @var string
     */
    public static $my = null;

    /**
     * The route for the API.
     *
     * @var string
     */
    public static $route = null;

    /**
     * Holds the IDs for single operations.
     *
     * @var array
     */
    public static $ids = [];

    /**
     * Returns the base parameters for the API.
     *
     * @param array $additional Additional parameters to merge with the base parameters.
     * @return \external_function_parameters
     */
    public static function base_parameters(array $additional = []) {
        return new external_function_parameters(array_merge($additional, [
            // Define base parameters.
        ]));
    }

    /**
     * Checks if the API is being called for a single operation.
     *
     * @return bool
     */
    public static function is_singleoperation() {
        return isset(static::$ids[static::class]);
    }

    /**
     * Skips the record if the ID does not match the current single operation ID.
     *
     * @param int $id The ID to check against the current single operation ID.
     * @return bool True if the record should be skipped, false otherwise.
     */
    public static function skip_record($id) {
        if (!static::is_singleoperation()) {
            return false;
        }
        return static::$ids[static::class] != $id;
    }

    /**
     * Returns the structure for the API response.
     *
     * @return \external_multiple_structure|\external_single_structure
     */
    public static function execute_returns() {
        $singlestructe = static::single_structure();
        foreach ((array) static::get_unixtimestamp_fields() as $field) {
            if (isset($singlestructe->keys[$field])) {
                $singlestructe->keys[$field] = timestampvalue::make($singlestructe->keys[$field]);
            }
        }
        if (static::is_singleoperation()) {
            return $singlestructe;
        }
        return new external_multiple_structure($singlestructe);
    }

    /**
     * Sets the ID for the current single operation.
     *
     * @param int $id The ID to set.
     */
    public static function set_id($id) {
        static::$ids[static::class] = $id;
    }

    /**
     * Returns the ID for the current single operation.
     *
     * @return int|null The ID if set, null otherwise.
     */
    public static function get_id() {

        if (!self::is_singleoperation()) {
            return null;
        }
        return static::$ids[static::class];
    }

    /**
     * Converts a Unix timestamp to an ISO 8601 formatted date string.
     *
     * @param int $unixstamp The Unix timestamp to convert.
     * @return string The formatted date string in ISO 8601 format, or the original value if invalid.
     */
    public static function converttimestamp($unixstamp) {
        $valid = is_numeric($unixstamp) && (int)$unixstamp == $unixstamp
            && $unixstamp <= PHP_INT_MAX && $unixstamp >= 0;
        if (!$valid) {
            return $unixstamp;
        }
        $date = new \DateTime('now', new \DateTimeZone(\core_date::get_server_timezone()));
        return $date->setTimestamp($unixstamp)->format(\DateTime::ATOM); // ATOM = ISO 8601 format.
    }

    /**
     * Cleans the return value based on the description.
     *
     * @param \external_description $description The description of the return value.
     * @param mixed $response The response to clean.
     * @return mixed The cleaned response.
     */
    public static function clean_returnvalue(\external_description $description, $response) {
        global $CFG;
        if ($description instanceof timestampvalue) {
            return self::converttimestamp($response);
        }
        if (is_string($response)) {
            $response = str_replace(
                "{$CFG->wwwroot}/pluginfile.php",
                "{$CFG->wwwroot}/local/learnwise/api/file.php",
                $response
            );
        }
        return parent::clean_returnvalue($description, $response);
    }

    /**
     * Returns the fields that should be treated as Unix timestamps.
     *
     * @return array
     */
    public static function get_unixtimestamp_fields() {
        return [];
    }

    /**
     * Returns the URL for a stored file.
     *
     * @param stored_file $file The stored file object.
     * @return moodle_url The URL for the stored file.
     */
    public static function file_url_from_stored_file(stored_file $file) {
        return moodle_url::make_pluginfile_url(
            $file->get_contextid(), $file->get_component(), $file->get_filearea(),
            $file->get_itemid(), $file->get_filepath(), $file->get_filename()
        );
    }

    /**
     * Returns the structure for a single operation.
     *
     * This method must be implemented by subclasses to define the structure of the API response.
     *
     * @return \external_single_structure The structure for the API response.
     */
    abstract public static function single_structure();

}
