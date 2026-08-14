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
 * LearnWise setup module for managing plugin configuration interface.
 * Handles floating button, LTI integration, web services, and live API settings.
 *
 * @module     local_learnwise/setup
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


define(['core/fragment', 'core/config'], function(Fragment, Config) {

    var elements = {
        addcourses: document.getElementById('addcourses'),
        removecourses: document.getElementById('removecourses'),
    };

    /**
     * Get selected course IDs from a course selector element.
     *
     * @param {string} selectorId - The ID of the select element to query
     * @returns {Array} Array of selected course ID values
     */
    function getSelectedCourseIds(selectorId) {
        var selector = document.getElementById(selectorId);
        if (!selector) {
            return [];
        }

        var selected = [];
        Array.prototype.forEach.call(selector.options, function(option) {
            if (option.selected) {
                selected.push(option.value);
            }
        });

        return selected;
    }

    /**
     * Synchronize course selectors by sending queries and updating button states.
     * Refreshes both potential and current course selectors and updates UI button states.
     */
    function syncCourseSelectors() {
        if (window.M && window.M.local_learnwise) {
            var potentialSelector = window.M.local_learnwise.get_course_selector('potential_courses');
            var currentSelector = window.M.local_learnwise.get_course_selector('current_courses');

            if (potentialSelector) {
                potentialSelector.send_query(true);
            }
            if (currentSelector) {
                currentSelector.send_query(true);
            }
        }
        updateButtonStates();
    }

    /**
     * Update the disabled state of add/remove course buttons based on selections.
     * Enables buttons only when there are selected courses in the respective selectors.
     */
    function updateButtonStates() {
        var addHasSelection = getSelectedCourseIds('potential_courses').length > 0;
        var removeHasSelection = getSelectedCourseIds('current_courses').length > 0;

        elements.addcourses.disabled = !addHasSelection;
        elements.removecourses.disabled = !removeHasSelection;
    }

    /**
     * Bind selection change listeners to a course selector element.
     * Updates button states when selection changes via click or change events.
     *
     * @param {string} selectorId - The ID of the select element to bind listeners to
     */
    function bindSelectorSelectionListener(selectorId) {
        var selector = document.getElementById(selectorId);
        if (!selector) {
            return;
        }

        selector.addEventListener('change', function() {
            updateButtonStates();
        });
        selector.addEventListener('click', function() {
            updateButtonStates();
        });
    }

    /**
     * Perform a course action (add or remove) on selected courses.
     * Sends the selected course IDs to the server via fragment loading and syncs selectors on completion.
     *
     * @param {string} action - The action to perform ('add' or 'remove')
     * @param {string} selectorId - The ID of the select element containing selected courses
     */
    function performCourseAction(action, selectorId) {
        var courseids = getSelectedCourseIds(selectorId);

        if (!courseids.length) {
            return;
        }

        var params = {
            action: action,
            courseids: courseids.join(','),
        };

        Fragment.loadFragment('local_learnwise', 'process_courses', Config.contextid, params)
        .then(function() {
            return syncCourseSelectors();
        }).catch(function(error) {
            window.console.error('Error loading fragment:', error);
        });
    }

    /**
     * Initialize all event listeners for course management interface.
     * Sets up button click handlers and selector selection listeners on page load.
     */
    function initializeEventListeners() {
        updateButtonStates();
        bindSelectorSelectionListener('potential_courses');
        bindSelectorSelectionListener('current_courses');

        elements.addcourses.addEventListener('click', function(e) {
            e.preventDefault();
            performCourseAction('add', 'potential_courses');
        });

        elements.removecourses.addEventListener('click', function(e) {
            e.preventDefault();
            performCourseAction('remove', 'current_courses');
        });
    }

    return {
        init: function() {
            initializeEventListeners();
        }
    };
});


