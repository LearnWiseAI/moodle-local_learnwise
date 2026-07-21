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

use core\files\curl_security_helper;
use curl;
use external_single_structure;
use external_value;
use local_learnwise\constants;
use moodle_url;

/**
 * Class files
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class files extends baseapi {
    /**
     * Summary of route
     * @var string
     */
    public static $route = 'files/access';

    #[\Override]
    public static function description() {
        return 'Check file is accessible or not';
    }

    #[\Override]
    public static function execute_parameters() {
        return self::base_parameters([
            'path' => new external_value(PARAM_PATH, 'filepath'),
        ]);
    }

    /**
     * Checks file accessible using file path
     *
     * @param string $path File path
     * @return array
     */
    public static function execute($path) {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path]
        );

        $filteredpathparts = explode('file.php', $params['path'], 2);
        $filteredpath = array_pop($filteredpathparts);

        $scriptkey = constants::COMPONENT . '_' . sha1($filteredpath);
        $token = get_user_key($scriptkey, $USER->id, null, null, strtotime('+5 secs'));
        $urlbase = new moodle_url('/tokenpluginfile.php', ['key' => $token, 'file' => $filteredpath]);
        $urlbase = $urlbase->out(false);

        $urlbase = self::clean_returnvalue(
            new external_value(PARAM_URL),
            $urlbase
        );

        $securityhelper = new curl_security_helper();
        $ignoresecurity = $securityhelper->url_is_blocked($urlbase);

        $curl = new curl(['ignoresecurity' => $ignoresecurity]);
        $curl->head($urlbase);

        delete_user_key($scriptkey, $USER->id);

        $curlresponse = (array) $curl->response;
        $curlinfo = (array) $curl->info;

        $response['accessible'] = empty($curl->error) && $curlinfo['http_code'] === 200 &&
            !empty($curlresponse['Content-Disposition']);

        return $response;
    }

    #[\Override]
    public static function is_singleoperation() {
        return true;
    }

    #[\Override]
    public static function single_structure() {
        return new external_single_structure([
            'accessible' => new external_value(PARAM_BOOL, 'file is accessible or not'),
        ]);
    }
}
