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
 * Tests for the plugin constants and redirect URL handling.
 *
 * @covers     \local_learnwise\constants
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class constants_test extends \advanced_testcase {
    /**
     * The component name matches the plugin directory.
     */
    public function test_component(): void {
        $this->assertSame('local_learnwise', constants::COMPONENT);
        $this->assertSame('local_learnwise', constants::component());
    }

    /**
     * The known environments are stable, since the URL helpers index into them.
     */
    public function test_environments(): void {
        $this->assertSame(['production', 'development', 'sandbox'], constants::ENVIRONMENTS);
    }

    /**
     * The region list covers every supported deployment region.
     */
    public function test_region_options(): void {
        $regions = constants::region_options();

        $this->assertSame(['ca', 'eu', 'uk', 'us', 'au'], $regions);
        $this->assertContains(constants::REGION, $regions, 'The default region must be a valid option');
    }

    /**
     * Every region that gets an LTI host prefix is a real region option.
     */
    public function test_prefixed_regions_are_valid_options(): void {
        foreach (util::LTIPREFIXEDREGIONS as $region) {
            $this->assertContains($region, constants::region_options());
        }
    }

    /**
     * With nothing configured there is no redirect URL.
     */
    public function test_get_redirecturl_when_unset(): void {
        $this->resetAfterTest();

        $this->assertSame('', constants::get_redirecturl());
    }

    /**
     * A single redirect URL is returned as-is.
     */
    public function test_get_redirecturl_single(): void {
        $this->resetAfterTest();
        set_config('redirecturl', 'https://example.test/callback', 'local_learnwise');

        $this->assertSame('https://example.test/callback', constants::get_redirecturl());
    }

    /**
     * Multiple newline-separated URLs are joined with spaces, as OAuth2 expects.
     */
    public function test_get_redirecturl_multiple(): void {
        $this->resetAfterTest();
        set_config('redirecturl', "https://a.test/cb\nhttps://b.test/cb", 'local_learnwise');

        $this->assertSame('https://a.test/cb https://b.test/cb', constants::get_redirecturl());
    }

    /**
     * Blank lines and stray whitespace are trimmed away rather than becoming empty entries.
     */
    public function test_get_redirecturl_trims_and_drops_blanks(): void {
        $this->resetAfterTest();
        set_config('redirecturl', "  https://a.test/cb  \n\n\n   \nhttps://b.test/cb\n", 'local_learnwise');

        $this->assertSame('https://a.test/cb https://b.test/cb', constants::get_redirecturl());
    }

    /**
     * The OAuth2 scope is the plugin's single webservice scope.
     */
    public function test_scope(): void {
        $this->assertSame('webservice', constants::SCOPE);
    }
}
