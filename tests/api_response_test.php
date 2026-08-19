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

/**
 * Tests for the plugin API response.
 *
 * @covers     \local_learnwise\api_response
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class api_response_test extends \advanced_testcase {
    /**
     * Responses at the configured byte limit remain unchanged.
     */
    public function test_response_at_size_limit_is_preserved() {
        $response = new api_response();
        $parameters = ['a' => 1];
        $response->set_response_size_limit(7, 'Too large');

        $response->setParameters($parameters);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($parameters, $response->getParameters());
    }

    /**
     * Responses beyond the configured byte limit become a concise HTTP 413 error.
     */
    public function test_response_beyond_size_limit_is_rejected() {
        $response = new api_response();
        $response->set_response_size_limit(6, 'Narrow the Moodle request.');

        $response->setParameters(['a' => 1]);

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([
            'error' => 'response_too_large',
            'error_description' => 'Narrow the Moodle request.',
        ], $response->getParameters());
        $this->assertSame('no-store', $response->getHttpHeader('Cache-Control'));
    }
}
