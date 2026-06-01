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

use local_learnwise\local\OAuth2\Response as oauth2_response;

defined('MOODLE_INTERNAL') || die();

/**
 * Plugin response wrapper.
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_response extends oauth2_response {
    /**
     * @var bool Whether an empty JSON response should be encoded as an array.
     */
    private $emptyarrayresponse = false;

    /**
     * Preserve list response shape when Moodle returns an empty external_multiple_structure.
     *
     * @param bool $emptyarrayresponse Whether empty parameters should encode as []
     * @return void
     */
    public function set_empty_array_response(bool $emptyarrayresponse = true) {
        $this->emptyarrayresponse = $emptyarrayresponse;
    }

    /**
     * Returns the JSON body for the response.
     *
     * @param string $format Response format
     * @return string
     */
    public function getResponseBody($format = 'json') {
        if ($format === 'json' && $this->emptyarrayresponse && $this->getParameters() === []) {
            return '[]';
        }
        return parent::getResponseBody($format);
    }
}
