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
 * Stand in for config.php in requests served by phpunit_site_router.php.
 *
 * It loads config.php once, then sets Moodle up against the PHPUnit database and dataroot instead of the real site,
 * the same way lib/phpunit/bootstrap.php switches them.
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState
// phpcs:disable moodle.Files.RequireLogin.Missing

global $CFG;

if (!defined('ABORT_AFTER_CONFIG')) {
    define('ABORT_AFTER_CONFIG', true);
}
require_once(dirname(__DIR__, 4) . '/config.php');

// Switch to the PHPUnit site, never the real one.
if (
    empty($CFG->phpunit_prefix) || $CFG->phpunit_prefix === $CFG->prefix || empty($CFG->phpunit_dataroot) ||
        !file_exists($CFG->phpunit_dataroot . '/phpunittestdir.txt')
) {
    http_response_code(500);
    exit('The PHPUnit test site is not configured.');
}
$CFG->wwwroot = 'http://' . $_SERVER['HTTP_HOST'];
$CFG->dataroot = $CFG->phpunit_dataroot;
foreach (['dbtype', 'dblibrary', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix', 'dboptions'] as $learnwisesetting) {
    if (isset($CFG->{'phpunit_' . $learnwisesetting})) {
        $CFG->$learnwisesetting = $CFG->{'phpunit_' . $learnwisesetting};
    }
}

// Like lib/phpunit/bootstrap.php, drop the rest of config.php, including what it forces over the database config.
$learnwiseallowed = ['wwwroot', 'dataroot', 'dirroot', 'admin', 'directorypermissions', 'filepermissions',
    'dbtype', 'dblibrary', 'dbhost', 'dbname', 'dbuser', 'dbpass', 'prefix', 'dboptions',
    'proxyhost', 'proxyport', 'proxytype', 'proxyuser', 'proxypassword', 'proxybypass',
    'altcacheconfigpath', 'pathtogs', 'pathtophp', 'pathtodu', 'aspellpath', 'pathtodot',
    'pathtounoconv', 'alternative_file_system_class', 'pathtopython', 'routerconfigured'];
$learnwiseproductioncfg = (array) $CFG;
$CFG = new stdClass();
foreach ($learnwiseproductioncfg as $learnwisesetting => $learnwisevalue) {
    if (in_array($learnwisesetting, $learnwiseallowed) || strpos($learnwisesetting, 'phpunit_') === 0) {
        $CFG->$learnwisesetting = $learnwisevalue;
    }
}
$CFG->cachedir = getenv('LEARNWISE_TEST_CACHEDIR') . '/cache';
$CFG->localcachedir = getenv('LEARNWISE_TEST_CACHEDIR') . '/localcache';
unset($learnwisesetting, $learnwisevalue, $learnwiseallowed, $learnwiseproductioncfg);

define('ABORT_AFTER_CONFIG_CANCEL', true);
require($CFG->dirroot . '/lib/setup.php');
