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

use context_system;
use core_useragent;
use external_single_structure;
use external_value;
use local_learnwise\constants;
use moodle_url;
use stdClass;

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

    /**
     * {@inheritdoc}
     */
    public static function description() {
        return 'Check file is accessible or not';
    }

    /**
     * {@inheritdoc}
     */
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
        global $USER;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path]
        );

        // The probe itself is answered by core file serving as $USER, but this route still has to
        // honour the context restriction carried by the calling token.
        self::validate_context(context_system::instance());

        $filteredpathparts = explode('file.php', $params['path'], 2);
        $filteredpath = array_pop($filteredpathparts);

        $scriptkey = constants::COMPONENT . '_' . sha1($filteredpath);
        $token = get_user_key($scriptkey, $USER->id, null, null, strtotime('+5 secs'));
        $urlbase = new moodle_url(
            '/local/learnwise/api/file.php',
            ['key' => $token, 'file' => $filteredpath]
        );

        $urlbase = self::clean_returnvalue(
            new external_value(PARAM_URL),
            $urlbase->out(false)
        );

        $curlreturn = self::send_head_request($urlbase);

        delete_user_key($scriptkey, $USER->id);

        $response['accessible'] = empty($curlreturn->error) &&
            $curlreturn->info['http_code'] === 200 &&
            !empty($curlreturn->response['Content-Disposition']);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public static function is_singleoperation() {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public static function single_structure() {
        return new external_single_structure([
            'accessible' => new external_value(PARAM_BOOL, 'file is accessible or not'),
        ]);
    }

    /**
     * Send head request with php's native curl
     *
     * @param string $url
     * @return stdClass Information get from running curl_* functions
     */
    public static function send_head_request($url) {
        $curlreturn = new stdClass();
        $curlreturn->responsefinished = false;
        $curlreturn->response = [];

        $useragent = core_useragent::get_moodlebot_useragent();

        $curloptions = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPGET => false,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_PROTOCOLS => (CURLPROTO_HTTP | CURLPROTO_HTTPS),
            CURLOPT_USERAGENT => $useragent,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $useragent,
            ],
        ];

        $curl = curl_init();
        curl_setopt_array($curl, $curloptions);
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($h, $header) use ($curlreturn) {
            return self::format_header($header, $curlreturn);
        });

        curl_exec($curl);
        $curlreturn->info  = curl_getinfo($curl);
        $curlreturn->error = curl_error($curl);
        $curlreturn->errno = curl_errno($curl);

        curl_close($curl);

        return $curlreturn;
    }

    /**
     * Curl response header formatter
     *
     * @param string $header
     * @param stdClass $curlreturn
     * @return int The length of the header
     */
    protected static function format_header($header, $curlreturn) {
        if (trim($header, "\r\n") === '') {
            $curlreturn->responsefinished = true;
        }

        if (strlen($header) > 2) {
            if ($curlreturn->responsefinished) {
                $curlreturn->responsefinished = false;
                $curlreturn->response = [];
            }
            $parts = explode(" ", rtrim($header, "\r\n"), 2);
            $key = rtrim($parts[0], ':');
            $value = isset($parts[1]) ? $parts[1] : null;
            if (!empty($curlreturn->response[$key])) {
                if (is_array($curlreturn->response[$key])) {
                    $curlreturn->response[$key][] = $value;
                } else {
                    $tmp = $curlreturn->response[$key];
                    $curlreturn->response[$key] = [];
                    $curlreturn->response[$key][] = $tmp;
                    $curlreturn->response[$key][] = $value;
                }
            } else {
                $curlreturn->response[$key] = $value;
            }
        }
        return strlen($header);
    }
}
