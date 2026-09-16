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

/**
 * Router for PHP's built-in web server that serves the PHPUnit test site over real HTTP.
 *
 * The web server runs the real Moodle scripts, but against the PHPUnit database and dataroot. That lets a test
 * reach code that calls back into the site with curl, and see the site answer as the data the test created allows.
 *
 * A script's own require of config.php is swapped for phpunit_site_setup.php, so config.php is only ever loaded
 * once, and never sets up the real site.
 *
 * Environment variables:
 *  - LEARNWISE_TEST_CACHEDIR: empty directory used for this server's caches.
 *  - LEARNWISE_TEST_REQUESTLOG: file every request is appended to, one JSON object per line.
 *
 * GET /__ready sets Moodle up and answers 204, so a test can wait for a warm server.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

if (php_sapi_name() !== 'cli-server' || !preg_match('/^127\.0\.0\.1:\d+$/', $_SERVER['HTTP_HOST'] ?? '')) {
    http_response_code(403);
    exit;
}

ob_start();
$learnwiserequestpath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
register_shutdown_function(function () use ($learnwiserequestpath) {
    file_put_contents(getenv('LEARNWISE_TEST_REQUESTLOG'), json_encode([
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $learnwiserequestpath,
        'query' => $_GET,
        'status' => http_response_code(),
        'headers' => headers_list(),
        'error' => error_get_last(),
        'body' => ob_get_level() ? substr(ob_get_contents(), 0, 2000) : '',
    ]) . "\n", FILE_APPEND | LOCK_EX);
});

if ($learnwiserequestpath === '/__ready') {
    require(__DIR__ . '/phpunit_site_setup.php');
    http_response_code(204);
    exit;
}

$learnwiseroot = dirname(__DIR__, 4);
$learnwisescript = realpath($learnwiseroot . $learnwiserequestpath);
if (
    $learnwisescript === false || strpos($learnwisescript, $learnwiseroot . DIRECTORY_SEPARATOR) !== 0 ||
        substr($learnwisescript, -4) !== '.php' || !is_file($learnwisescript)
) {
    http_response_code(404);
    exit;
}

// Run a rewritten copy of the script: its first require of config.php loads the test site setup instead,
// and __FILE__ and __DIR__ still point at the original script.
$learnwisecode = '';
$learnwisestatement = null;
$learnwiseconfigreplaced = false;
foreach (token_get_all(file_get_contents($learnwisescript)) as $learnwisetoken) {
    $learnwisetext = is_array($learnwisetoken) ? $learnwisetoken[1] : $learnwisetoken;
    $learnwiseid = is_array($learnwisetoken) ? $learnwisetoken[0] : null;

    if ($learnwiseid === T_FILE) {
        $learnwisetext = var_export($learnwisescript, true);
    } else if ($learnwiseid === T_DIR) {
        $learnwisetext = var_export(dirname($learnwisescript), true);
    }

    if ($learnwisestatement !== null) {
        $learnwisestatement .= $learnwisetext;
        if ($learnwisetext === ';') {
            if (strpos($learnwisestatement, 'config.php') !== false) {
                $learnwisestatement = 'require(' . var_export(__DIR__ . '/phpunit_site_setup.php', true) . ');';
                $learnwiseconfigreplaced = true;
            }
            $learnwisecode .= $learnwisestatement;
            $learnwisestatement = null;
        }
        continue;
    }

    $learnwiseisinclude = in_array($learnwiseid, [T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE], true);
    if (!$learnwiseconfigreplaced && $learnwiseisinclude) {
        $learnwisestatement = $learnwisetext;
        continue;
    }
    $learnwisecode .= $learnwisetext;
}

$learnwisecopy = tempnam(getenv('LEARNWISE_TEST_CACHEDIR'), 'script');
file_put_contents($learnwisecopy, $learnwisecode);

chdir(dirname($learnwisescript));
unset(
    $learnwiseroot,
    $learnwiserequestpath,
    $learnwisescript,
    $learnwisestatement,
    $learnwiseconfigreplaced,
    $learnwisetoken,
    $learnwisetext,
    $learnwiseid,
    $learnwiseisinclude,
    $learnwisecode
);

require($learnwisecopy);
