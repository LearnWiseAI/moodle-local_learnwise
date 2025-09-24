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
 * Class constants
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class constants extends util {
    /** @var string */
    const COMPONENT = 'local_learnwise';

    /** @var string */
    const SCOPE = 'webservice';

    /** @var array */
    const ENVIRONMENTS = ['production', 'development', 'sandbox'];

    /** @var string */
    const REGION = 'eu';

    /**
     * Returns the URL to redirect the user to.
     *
     * @return string The redirect URL.
     */
    public static function get_redirecturl() {
        $redirecturls = get_config(static::component(), 'redirecturl');
        $redirecturls = array_map('trim', explode(PHP_EOL, $redirecturls));
        $redirecturls = array_filter($redirecturls, 'trim');
        return join(' ', $redirecturls);
    }

    /**
     * List of regions
     *
     * @return string[]
     */
    public static function region_options() {
        return [
            'ca',
            'eu',
            'uk',
            'us',
        ];
    }
}
