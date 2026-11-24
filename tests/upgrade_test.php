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

use advanced_testcase;

/**
 * Tests for the 2026112400 upgrade step (webservice auth for the API user).
 *
 * @covers     ::xmldb_local_learnwise_upgrade
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends advanced_testcase {
    /** @var int The version just before the step under test, so only that step runs. */
    const OLDVERSION = 2026092500;

    /**
     * Run the plugin upgrade from OLDVERSION.
     */
    protected function run_upgrade() {
        global $CFG;
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/learnwise/db/upgrade.php');

        set_config('version', self::OLDVERSION, 'local_learnwise');
        set_config('upgraderunning', time() + 3600);
        $this->assertTrue(xmldb_local_learnwise_upgrade(self::OLDVERSION));
    }

    /**
     * A site that never set up LearnWise web services is left alone: no API user, role or
     * tokenuserid is created and the webservice auth plugin stays disabled.
     */
    public function test_upgrade_without_apiuser_changes_nothing(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        set_config('auth', 'email');
        unset_config('tokenuserid', 'local_learnwise');
        $role = $DB->get_record('role', ['shortname' => 'learnwise_assistant']);
        if ($role) {
            delete_role($role->id);
        }
        $users = $DB->count_records('user');

        $this->run_upgrade();

        $this->assertSame('email', $CFG->auth);
        $this->assertFalse(is_enabled_auth('webservice'));
        $this->assertFalse(get_config('local_learnwise', 'tokenuserid'));
        $this->assertSame($users, $DB->count_records('user'));
        $this->assertFalse($DB->record_exists('user', ['username' => 'learnwise_assistant_user']));
        $this->assertFalse($DB->record_exists('role', ['shortname' => 'learnwise_assistant']));
    }

    /**
     * An existing API user gets webservice auth back and the webservice auth plugin is enabled.
     */
    public function test_upgrade_with_apiuser_restores_webservice_auth(): void {
        global $DB;
        $this->resetAfterTest();

        set_config('auth', 'email');
        $apiuser = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        $other = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        set_config('tokenuserid', $apiuser->id, 'local_learnwise');

        $this->run_upgrade();

        $this->assertSame('webservice', $DB->get_field('user', 'auth', ['id' => $apiuser->id]));
        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $other->id]));
        $this->assertTrue(is_enabled_auth('webservice'));
        $this->assertTrue(is_enabled_auth('email'));
    }

    /**
     * A tokenuserid pointing at a deleted user does not cause the user to be recreated or changed.
     */
    public function test_upgrade_with_deleted_apiuser_does_not_recreate_it(): void {
        global $DB;
        $this->resetAfterTest();

        $apiuser = $this->getDataGenerator()->create_user(['auth' => 'manual']);
        delete_user($apiuser);
        set_config('tokenuserid', $apiuser->id, 'local_learnwise');

        $this->run_upgrade();

        $this->assertSame('manual', $DB->get_field('user', 'auth', ['id' => $apiuser->id]));
        $this->assertFalse($DB->record_exists('user', ['username' => 'learnwise_assistant_user']));
    }
}
