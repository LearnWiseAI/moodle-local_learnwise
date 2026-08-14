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

// Define the core_course namespace if it has not already been defined
M.local_learnwise = M.local_learnwise || {};
// Define a course selectors array for against the cure_course namespace
M.local_learnwise.course_selectors = [];

M.local_learnwise.get_course_selector = function (name) {
    return this.course_selectors[name] || null;
};

M.local_learnwise.init_course_selector = function (Y, name, hash, extrafields, lastsearch) {
    // Creates a new course_selector object
    var course_selector = {

        name: name,

        extrafields: extrafields,

        querydelay: 0.5,

        searchfield: Y.one('#' + name + '_searchtext'),

        clearbutton: null,

        listbox: Y.one('#' + name),

        timeoutid: null,

        iotransactions: {},

        lastsearch: lastsearch,

        selectionempty: true,

        init: function () {
            // Hide the search button and replace it with a label.
            var searchbutton = Y.one('#' + this.name + '_searchbutton');
            this.searchfield.insert(Y.Node.create('<label for="' + this.name + '_searchtext">' + searchbutton.get('value') + '</label>'), this.searchfield);
            searchbutton.remove();

            // Hook up the event handler for when the search text changes.
            this.searchfield.on('keyup', this.handle_keyup, this);

            // Hook up the event handler for when the selection changes.
            this.listbox.on('keyup', this.handle_selection_change, this);
            this.listbox.on('click', this.handle_selection_change, this);
            this.listbox.on('change', this.handle_selection_change, this);

            // And when the search any substring preference changes. Do an immediate re-search.
            // Y.one('#courseselector_searchanywhereid').on('click', this.handle_searchanywhere_change, this);

            // Define our custom event.
            //this.createEvent('selectionchanged');
            this.selectionempty = this.is_selection_empty();

            // Replace the Clear submit button with a clone that is not a submit button.
            var clearbtn = Y.one('#' + this.name + '_clearbutton');
            // eslint-disable-next-line max-len
            this.clearbutton = Y.Node.create('<input type="button" value="' + clearbtn.get('value') + '" class="btn btn-secondary clearbtn m-x-1"/>');
            clearbtn.replace(Y.Node.getDOMNode(this.clearbutton));
            this.clearbutton.set('id', this.name + "_clearbutton");
            this.clearbutton.on('click', this.handle_clear, this);
            this.clearbutton.set('disabled', (this.get_search_text() == ''));

            this.send_query(false);
        },

        handle_keyup: function (e) {
            // Trigger an ajax search after a delay.
            this.cancel_timeout();
            this.timeoutid = Y.later(this.querydelay * 1000, e, function (obj) {
                obj.send_query(false);
            }, this);

            // Enable or disable the clear button.
            this.clearbutton.set('disabled', (this.get_search_text() == ''));

            // If enter was pressed, prevent a form submission from happening.
            if (e.keyCode == 13) {
                e.halt();
            }
        },

        handle_selection_change: function () {
            var isselectionempty = this.is_selection_empty();
            if (isselectionempty !== this.selectionempty) {
                this.fire('course_selector:selectionchanged', isselectionempty);
            }
            this.selectionempty = isselectionempty;
        },

        handle_searchanywhere_change: function () {
            if (this.lastsearch != '' && this.get_search_text() != '') {
                this.send_query(true);
            }
        },

        handle_clear: function () {
            this.searchfield.set('value', '');
            this.clearbutton.set('disabled', true);
            this.send_query(false);
        },

        send_query: function (forceresearch) {
            // Cancel any pending timeout.
            this.cancel_timeout();

            var value = this.get_search_text();
            this.searchfield.set('class', '');
            if (this.lastsearch == value && !forceresearch) {
                return;
            }

            // Try to cancel existing transactions.
            Y.Object.each(this.iotransactions, function (trans) {
                trans.abort();
            });

            var iotrans = Y.io(M.cfg.wwwroot + '/local/learnwise/courseselector/search.php', {
                method: 'POST',
                data: 'selectorid=' + hash + '&sesskey=' + M.cfg.sesskey + '&search=' + value + '&courseselector_searchanywhere=' + this.get_option('searchanywhere'),
                on: {
                    complete: this.handle_response
                },
                context: this
            });
            this.iotransactions[iotrans.id] = iotrans;

            this.lastsearch = value;
            this.listbox.setStyle('background', 'url(' + M.util.image_url('i/loading', 'moodle') + ') no-repeat center center');
            this.listbox.setStyle('background-size', '30px 30px');
        },

        handle_response: function (requestid, response) {
            try {
                delete this.iotransactions[requestid];
                if (!Y.Object.isEmpty(this.iotransactions)) {
                    // More searches pending. Wait until they are all done.
                    return;
                }
                this.listbox.setStyle('background', '');
                var data = Y.JSON.parse(response.responseText);
                if (data.error) {
                    this.searchfield.addClass('error');
                    return new M.core.ajaxException(data);
                }
                this.output_options(data);

                // If updated courseSummaries are present, overwrite the global variable
                // that's output by group_non_members_selector::print_course_summaries() in course/selector/lib.php
                if (typeof data.courseSummaries !== "undefined") {
                    courseSummaries = data.courseSummaries;
                }
            } catch (e) {
                this.listbox.setStyle('background', '');
                this.searchfield.addClass('error');
                return new M.core.exception(e);
            }
        },

        output_options: function (data) {
            // Clear out the existing options, keeping any ones that are already selected.
            var selectedcourses = {};
            this.listbox.all('optgroup').each(function (optgroup) {
                optgroup.all('option').each(function (option) {
                    if (option.get('selected')) {
                        selectedcourses[option.get('value')] = {
                            id: option.get('value'),
                            name: option.get('innerText') || option.get('textContent'),
                            disabled: option.get('disabled')
                        };
                    }
                    option.remove();
                }, this);
                optgroup.remove();
            }, this);

            // Output each optgroup.
            var count = 0;
            for (var key in data.results) {
                var groupdata = data.results[key];
                this.output_group(groupdata.name, groupdata.courses, selectedcourses, true);
                count++;
            }
            if (!count) {
                var searchstr = (this.lastsearch != '') ? this.insert_search_into_str(M.util.get_string('nomatchingcourses', 'local_learnwise', this.lastsearch), this.lastsearch) : M.util.get_string('none', 'moodle');
                this.output_group(searchstr, {}, selectedcourses, true);
            }

            // If there were previously selected courses who do not match the search, show them too.
            if (this.get_option('preserveselected') && selectedcourses) {
                this.output_group(this.insert_search_into_str(M.util.get_string('previouslyselectedcourses', 'local_learnwise'), this.lastsearch), selectedcourses, true, false);
            }
            this.handle_selection_change();
        },

        output_group: function (groupname, courses, selectedcourses, processsingle) {
            var optgroup = Y.Node.create('<optgroup></optgroup>');
            var count = 0;
            for (var key in courses) {
                var course = courses[key];
                var option = Y.Node.create('<option value="' + course.id + '">' + course.name + '</option>');
                if (course.disabled) {
                    option.set('disabled', true);
                } else if (selectedcourses === true || selectedcourses[course.id]) {
                    option.set('selected', true);
                    delete selectedcourses[course.id];
                } else {
                    option.set('selected', false);
                }
                optgroup.append(option);
                if (course.infobelow) {
                    extraoption = Y.Node.create('<option disabled="disabled" class="courseselector-infobelow"/>');
                    extraoption.appendChild(document.createTextNode(course.infobelow));
                    optgroup.append(extraoption);
                }
                count++;
            }

            if (count > 0) {
                optgroup.set('label', groupname + ' (' + count + ')');
                if (processsingle && count === 1 && this.get_option('autoselectunique') && option.get('disabled') == false) {
                    option.set('selected', true);
                }
            } else {
                optgroup.set('label', groupname);
                optgroup.append(Y.Node.create('<option disabled="disabled">\u00A0</option>'));
            }
            this.listbox.append(optgroup);
        },

        insert_search_into_str: function (str, search) {
            return str.replace("%%SEARCHTERM%%", search);
        },

        get_search_text: function () {
            return this.searchfield.get('value').toString().replace(/^ +| +$/, '');
        },

        is_selection_empty: function () {
            var selection = false;
            this.listbox.all('option').each(function () {
                if (this.get('selected')) {
                    selection = true;
                }
            });
            return !(selection);
        },

        cancel_timeout: function () {
            if (this.timeoutid) {
                clearTimeout(this.timeoutid);
                this.timeoutid = null;
            }
        },

        get_option: function (name) {
            var checkbox = Y.one('#courseselector_' + name + 'id');
            if (checkbox) {
                return (checkbox.get('checked'));
            } else {
                return false;
            }
        }
    };

    // Augment the course selector with the EventTarget class so that we can use
    // custom events.
    Y.augment(course_selector, Y.EventTarget, null, null, {});

    // Initialise the course selector.
    course_selector.init();

    // Store the course selector so that it can be retrieved.
    this.course_selectors[name] = course_selector;

    // Return the course selector.
    return course_selector;
};


M.local_learnwise.init_course_selector_options_tracker = function (Y) {
    // Create a course selector options tracker.
    var course_selector_options_tracker = {

        init: function () {
            var settings = [
                'courseselector_preserveselected',
                'courseselector_autoselectunique',
                'courseselector_searchanywhere'
            ];

            for (var s in settings) {
                var setting = settings[s];
                Y.one('#' + setting + 'id').on('click', this.set_course_preference, this, setting);
            }
        },

        set_course_preference: function (e, name) {
            M.util.set_course_preference(name, Y.one('#' + name + 'id').get('checked'));
        }
    };
    // Initialise the options tracker
    course_selector_options_tracker.init();
    // Return it just incase it is ever wanted
    return course_selector_options_tracker;
};