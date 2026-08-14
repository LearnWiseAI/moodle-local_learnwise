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

namespace local_learnwise\local\courseselector;

use moodle_exception;
use stdClass;

/**
 * Class course_selector_base
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class course_selector_base {
    /** @var string The control name (and id) in the HTML. */
    protected $name;
    /** @var array Extra fields to search on and return in addition to firstname and lastname. */
    protected $extrafields = ['shortname'];
    /** @var object Context used for capability checks regarding this selector (does
     * not necessarily restrict user list) */
    protected $accesscontext;
    /** @var bool Whether the conrol should allow selection of many users, or just one. */
    protected $multiselect = true;
    /** @var int The height this control should have, in rows. */
    protected $rows = 20;
    /** @var array A list of userids that should not be returned by this control. */
    protected $exclude = [];
    /** @var array|null A list of the users who are selected. */
    protected $selected = null;
    /** @var bool When the search changes, do we keep previously selected options that do
     * not match the new search term? */
    protected $preserveselected = false;
    /** @var bool If only one user matches the search, should we select them automatically. */
    protected $autoselectunique = false;
    /** @var bool If true, the search term can match anywhere in the name, not just at the start. */
    protected $searchanywhere = false;
    /** @var array|null A list of course IDs that are being validated. */
    protected $validatingcourseids = null;
    /**  @var string The label for the select element. */
    protected $selectlabel;
    /**  @var bool Used to ensure we only output the search options for one user selector on
     * each page. */
    private static $searchoptionsoutput = true;
    /** @var array JavaScript YUI3 Module definition */
    protected static $jsmodule = [
            'name' => 'course_selector',
            'fullpath' => '/local/learnwise/courseselector/module.js',
            'requires'  => ['node', 'event-custom', 'datasource', 'json', 'moodle-core-notification'],
            'strings' => [
                ['previouslyselectedcourses', 'local_learnwise', '%%SEARCHTERM%%'],
                ['nomatchingcourses', 'local_learnwise', '%%SEARCHTERM%%'],
                ['none', 'moodle'],
            ],
        ];

    /** @var int this is used to define maximum number of course visible in list */
    public $maxcoursesperpage = 100;

    /** @var bool Whether to override fullname() */
    public $viewfullnames = false;

    /**
     * Constructor. Each subclass must have a constructor with this signature.
     *
     * @param string $name the control name/id for use in the HTML.
     * @param array $options other options needed to construct this selector.
     * You must be able to clone a userselector by doing new get_class($us)($us->get_name(), $us->get_options());
     */
    public function __construct($name, $options = []) {
        global $CFG, $PAGE;

        // Initialise member variables from constructor arguments.
        $this->name = $name;

        // Use specified context for permission checks, system context if not specified.
        if (isset($options['accesscontext'])) {
            $this->accesscontext = $options['accesscontext'];
        } else {
            $this->accesscontext = \context_system::instance();
        }

        if (isset($options['exclude']) && is_array($options['exclude'])) {
            $this->exclude = $options['exclude'];
        }
        if (isset($options['multiselect'])) {
            $this->multiselect = $options['multiselect'];
        }

        // Read the course prefs / optional_params that we use.
        $this->preserveselected = $this->initialise_option('courseselector_preserveselected', $this->preserveselected);
        $this->autoselectunique = $this->initialise_option('courseselector_autoselectunique', $this->autoselectunique);
        $this->searchanywhere = $this->initialise_option('courseselector_searchanywhere', $this->searchanywhere);

        if (!empty($options['selectlabel'])) {
            $this->selectlabel = $options['selectlabel'];
        }
    }


    /**
     * All to the list of user ids that this control will not select.
     *
     * For example, on the role assign page, we do not list the users who already have the role in question.
     *
     * @param array $arrayofcourseids the user ids to exclude.
     */
    public function exclude($arrayofcourseids) {
        $this->exclude = array_unique(array_merge($this->exclude, $arrayofcourseids));
    }

    /**
     * Set whether to show the full names of courses in the select.
     */
    public function searchbar_iflabel() {
        if (empty($this->selectlabel)) {
            return null;
        }
        $search = optional_param($this->name . '_searchtext', '', PARAM_RAW);
        $placeholder = get_string('search');
        $clearbtn = get_string('clear');
        $value = s($search);
        $html = <<<html
        <div class="mb-1 d-flex flex-nowrap align-items-center justify-content-between">
            <div class="p-0 d-flex flex-wrap">
                {$this->selectlabel}
            </div>
            <div class="text-right p-0">
                 <div class="search-container">
                    <input type="text" id="{$this->name}_searchtext" class="search-box"
                        placeholder="{$placeholder}" name="{$this->name}_searchtext" size="15" value="{$value}">
                    <label for="{$this->name}_searchtext" class="w0">
                        <i class="search-icon lnil lnil-search lnil-lg "></i>
                    </label>
                    <input type="hidden" name="{$this->name}_searchbutton" id="{$this->name}_searchbutton">
                    <input type="button" name="{$this->name}_clearbutton" id="{$this->name}_clearbutton"
                         value="{$clearbtn}" class="clearbtn">
                 </div>
            </div>
        </div>
html;

        return $html;
    }

    /**
     * Clear the list of excluded course ids.
     */
    public function clear_exclusions() {
        $this->exclude = [];
    }

    /**
     * Returns the list of course ids that this control will not select.
     *
     * @return array the list of course ids that this control will not select.
     */
    public function get_exclusions() {
        return is_array($this->exclude) ? $this->exclude : [];
    }

    /**
     * The courses that were selected.
     *
     * This is a more sophisticated version of optional_param($this->name, [], PARAM_INT) that validates the
     * returned list of ids against the rules for this course selector.
     *
     * @return array of course objects.
     */
    public function get_selected_courses() {
        // Do a lazy load.
        if (is_null($this->selected)) {
            $this->selected = $this->load_selected_courses();
        }
        return $this->selected;
    }


    /**
     * Return the selected course.
     * @return stdClass|null
     */
    public function get_selected_course() {
        if ($this->multiselect) {
            throw new moodle_exception('cannotcallusgetselectedcourse', 'local_learnwise');
        }
        $courses = $this->get_selected_courses();
        if (count($courses) == 1) {
            return reset($courses);
        } else if (count($courses) == 0) {
            return null;
        } else {
            throw new moodle_exception('courseselectortoomany', 'local_learnwise');
        }
    }

    /**
     * Invalidates the list of selected courses.
     *
     * If you update the database in such a way that it is likely to change the
     * list of courses that this component is allowed to select from, then you
     * must call this method. For example, on the role assign page, after you have
     * assigned some roles to some courses, you should call this.
     */
    public function invalidate_selected_courses() {
        $this->selected = null;
    }

    /**
     * Output this course_selector as HTML.
     *
     * @param boolean $return if true, return the HTML as a string instead of outputting it.
     * @return mixed if $return is true, returns the HTML as a string, otherwise returns nothing.
     */
    public function display($return = false) {
        global $PAGE;

        // Get the list of requested courses.
        $search = optional_param($this->name . '_searchtext', '', PARAM_RAW);
        if (optional_param($this->name . '_clearbutton', false, PARAM_BOOL)) {
            $search = '';
        }
        $groupedcourses = $this->find_courses($search);

        // Output the select.
        $name = $this->name;
        $multiselect = '';
        if ($this->multiselect) {
            $name .= '[]';
            $multiselect = 'multiple="multiple" ';
        }
        $output = '<div class="courseselector" id="' . $this->name . '_wrapper">' . "\n" .
                $this->searchbar_iflabel() .
            '<select name="' . $name . '" id="' . $this->name . '" ' .
            $multiselect . 'size="' . $this->rows . '" class="form-control no-overflow">' . "\n";

        // Populate the select.
        $output .= $this->output_options($groupedcourses, $search);

        // Output the search controls.
        $output .= "</select>\n<div class=\"form-inline\">\n";
        if (empty($this->searchbar_iflabel())) {
            $output .= '<input type="text" name="' . $this->name . '_searchtext" id="' .
                    $this->name . '_searchtext" size="15" value="' . s($search) . '" class="form-control"/>';
            $output .= '<input type="submit" name="' . $this->name . '_searchbutton" id="' .
                    $this->name . '_searchbutton" value="' . $this->search_button_caption() . '" class="btn btn-secondary"/>';
            $output .= '<input type="submit" name="' . $this->name . '_clearbutton" id="' .
                    $this->name . '_clearbutton" value="' . get_string('clear') . '" class="btn btn-secondary"/>';
        }
        // And the search options.
        $optionsoutput = false;
        if (!self::$searchoptionsoutput) {
            $output .= print_collapsible_region_start(
                '',
                'courseselector_options',
                get_string('searchoptions'),
                'courseselector_optionscollapsed',
                true,
                true
            );
            $output .= $this->option_checkbox(
                'preserveselected',
                $this->preserveselected,
                get_string('courseselectorpreserveselected', 'local_learnwise')
            );
            $output .= $this->option_checkbox(
                'autoselectunique',
                $this->autoselectunique,
                get_string('courseselectorautoselectunique', 'local_learnwise')
            );
            $output .= $this->option_checkbox(
                'searchanywhere',
                $this->searchanywhere,
                get_string('courseselectorsearchanywhere', 'local_learnwise')
            );
            $output .= print_collapsible_region_end(true);

            $PAGE->requires->js_init_call('M.local_learnwise.init_course_selector_options_tracker', [], false, self::$jsmodule);
            self::$searchoptionsoutput = true;
        }
        $output .= "</div>\n</div>\n\n";

        // Initialise the ajax functionality.
        $output .= $this->initialise_javascript($search);

        // Return or output it.
        if ($return) {
            return $output;
        } else {
            echo $output;
        }
    }

    /**
     * The height this control will be displayed, in rows.
     *
     * @param integer $numrows the desired height.
     */
    public function set_rows($numrows) {
        $this->rows = $numrows;
    }

    /**
     * Returns the number of rows to display in this control.
     *
     * @return integer the height this control will be displayed, in rows.
     */
    public function get_rows() {
        return $this->rows;
    }


    /**
     * Whether this control will allow selection of many, or just one user.
     *
     * @param boolean $multiselect true = allow multiple selection.
     */
    public function set_multiselect($multiselect) {
        $this->multiselect = $multiselect;
    }

    /**
     * Returns true is multiselect should be allowed.
     *
     * @return boolean whether this control will allow selection of more than one user.
     */
    public function is_multiselect() {
        return $this->multiselect;
    }

    /**
     * Returns the id/name of this control.
     *
     * @return string the id/name that this control will have in the HTML.
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Set the course fields that are displayed in the selector in addition to the course's name.
     *
     * @param array $fields a list of field names that exist in the course table.
     */
    public function set_extra_fields($fields) {
        $this->extrafields = $fields;
    }

    /**
     * Search the database for courses matching the $search string, and any other
     * conditions that apply. The SQL for testing whether a course matches the
     * search string should be obtained by calling the search_sql method.
     *
     * This method is used both when getting the list of choices to display to
     * the course, and also when validating a list of courses that was selected.
     *
     * When preparing a list of courses to choose from ($this->is_validating()
     * return false) you should probably have an maximum number of courses you will
     * return, and if more courses than this match your search, you should instead
     * return a message generated by the too_many_results() method. However, you
     * should not do this when validating.
     *
     * If you are writing a new course_selector subclass, I strongly recommend you
     * look at some of the subclasses later in this file and in admin/roles/lib.php.
     * They should help you see exactly what you have to do.
     *
     * @param string $search the search string.
     * @return array An array of arrays of courses. The array keys of the outer
     *      array should be the string names of optgroups. The keys of the inner
     *      arrays should be courseids, and the values should be course objects
     *      containing at least the list of fields returned by the method
     *      required_fields_sql(). If a course object has a ->disabled property
     *      that is true, then that option will be displayed greyed out, and
     *      will not be returned by get_selected_courses.
     */
    abstract public function find_courses($search);

    /**
     *
     * Note: this function must be implemented if you use the search ajax field
     *       (e.g. set $options['file'] = '/admin/filecontainingyourclass.php';)
     * @return array the options needed to recreate this course_selector.
     */
    protected function get_options() {
        return [
            'class' => get_class($this),
            'name' => $this->name,
            'exclude' => $this->exclude,
            'extrafields' => $this->extrafields,
            'multiselect' => $this->multiselect,
            'accesscontext' => $this->accesscontext,
        ];
    }


    /**
     * Returns true if this control is validating a list of courses.
     *
     * @return boolean if true, we are validating a list of selected courses,
     *      rather than preparing a list of courses to choose from.
     */
    protected function is_validating() {
        return !is_null($this->validatingcourseids);
    }

    /**
     * Get the list of courses that were selected by doing optional_param then validating the result.
     *
     * @return array of course objects.
     */
    protected function load_selected_courses() {
        // See if we got anything.
        if ($this->multiselect) {
            $courseids = optional_param_array($this->name, [], PARAM_INT);
        } else if ($courseid = optional_param($this->name, 0, PARAM_INT)) {
            $courseids = [$courseid];
        }
        // If there are no courses there is nobody to load.
        if (empty($courseids)) {
            return [];
        }

        // If we did, use the find_courses method to validate the ids.
        $this->validatingcourseids = $courseids;
        $groupedcourses = $this->find_courses('');
        $this->validatingcourseids = null;

        // Aggregate the resulting list back into a single one.
        $courses = [];
        foreach ($groupedcourses as $group) {
            foreach ($group as $course) {
                if (!isset($courses[$course->id]) && in_array($course->id, $courseids)) {
                    $courses[$course->id] = $course;
                }
            }
        }

        // If we are only supposed to be selecting a single course, make sure we do.
        if (!$this->multiselect && count($courses) > 1) {
            $courses = array_slice($courses, 0, 1);
        }

        return $courses;
    }

     /**
      * Used to generate a nice message when there are too many courses to show.
      *
      * The message includes the number of courses that currently match, and the
      * text of the message depends on whether the search term is non-blank.
      *
      * @param string $search the search term, as passed in to the find courses method.
      * @param int $count the number of courses that currently match.
      * @return array in the right format to return from the find_courses method.
      */
    protected function too_many_results($search, $count) {
        if ($search) {
            $a = new stdClass();
            $a->count = $count;
            $a->search = $search;
            return [get_string('toomanycoursesmatchsearch', 'local_learnwise', $a) => [],
                get_string('pleasesearchmore') => []];
        } else {
            return [get_string('toomanycoursestoshow', 'local_learnwise', $count) => [],
                get_string('pleaseusesearch') => []];
        }
    }

    /**
     * Output the list of <optgroup>s and <options>s that go inside the select.
     *
     * This method should do the same as the JavaScript method
     * course_selector.prototype.handle_response.
     *
     * @param array $groupedcourses an array, as returned by find_courses.
     * @param string $search
     * @return string HTML code.
     */
    protected function output_options($groupedcourses, $search) {
        $output = '';

        // Ensure that the list of previously selected courses is up to date.
        $this->get_selected_courses();

        // If $groupedcourses is empty, make a 'no matching courses' group. If there is
        // only one selected course, set a flag to select them if that option is turned on.
        $select = false;
        if (empty($groupedcourses)) {
            if (!empty($search)) {
                $groupedcourses = [get_string('nomatchingcourses', 'local_learnwise', $search) => []];
            } else {
                $groupedcourses = [get_string('none') => []];
            }
        } else if (
            $this->autoselectunique && count($groupedcourses) == 1 &&
            count(reset($groupedcourses)) == 1
        ) {
            $select = true;
            if (!$this->multiselect) {
                $this->selected = [];
            }
        }

        // Output each optgroup.
        foreach ($groupedcourses as $groupname => $courses) {
            $output .= $this->output_optgroup($groupname, $courses, $select);
        }

        // If there were previously selected courses who do not match the search, show them too.
        if ($this->preserveselected && !empty($this->selected)) {
            $output .= $this->output_optgroup(
                get_string('previouslyselectedcourses', 'local_learnwise', $search),
                $this->selected,
                true
            );
        }

        // This method trashes $this->selected, so clear the cache so it is rebuilt before anyone tried to use it again.
        $this->selected = null;

        return $output;
    }

    /**
     * Output one particular optgroup. Used by the preceding function output_options.
     *
     * @param string $groupname the label for this optgroup.
     * @param array $courses the courses to put in this optgroup.
     * @param boolean $select if true, select the courses in this group.
     * @return string HTML code.
     */
    protected function output_optgroup($groupname, $courses, $select) {
        if (!empty($courses)) {
            $output = '  <optgroup label="' . htmlspecialchars($groupname) . ' (' . count($courses) . ')">' . "\n";
            foreach ($courses as $course) {
                $attributes = '';
                if (!empty($course->disabled)) {
                    $attributes .= ' disabled="disabled"';
                } else if ($select || isset($this->selected[$course->id])) {
                    $attributes .= ' selected="selected"';
                }
                unset($this->selected[$course->id]);
                $output .= '    <option' . $attributes . ' value="' . $course->id . '">' .
                    $this->output_course($course) . "</option>\n";
                if (!empty($course->infobelow)) {
                    // Poor man's indent  here is because CSS styles do not work in select options, except in Firefox.
                    $output .= '    <option disabled="disabled" class="courseselector-infobelow">' .
                        '&nbsp;&nbsp;&nbsp;&nbsp;' . s($course->infobelow) . '</option>';
                }
            }
        } else {
            $output = '  <optgroup label="' . htmlspecialchars($groupname) . '">' . "\n";
            $output .= '    <option disabled="disabled">&nbsp;</option>' . "\n";
        }
        $output .= "  </optgroup>\n";
        return $output;
    }

    /**
     * Convert a course object to a string suitable for displaying as an option in the list box.
     *
     * @param object $course the course to display.
     * @return string a string representation of the course.
     */
    public function output_course($course) {
        $out = $course->fullname;
        if ($this->extrafields) {
            $displayfields = [];
            foreach ($this->extrafields as $field) {
                $displayfields[] = $course->{$field};
            }
            $out .= ' (' . implode(', ', $displayfields) . ')';
        }
        return $out;
    }

    /**
     * Returns the string to use for the search button caption.
     *
     * @return string the caption for the search button.
     */
    protected function search_button_caption() {
        return get_string('search');
    }


    /**
     * Initialise one of the option checkboxes, either from  the request, or failing that from the
     * course_preferences table, or finally from the given default.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed|null|string
     */
    private function initialise_option($name, $default) {
        $param = optional_param($name, null, PARAM_BOOL);
        if (is_null($param)) {
            return get_user_preferences($name, $default);
        } else {
            set_user_preference($name, $param);
            return $param;
        }
    }

    /**
     * Output one of the options checkboxes.
     *
     * @param string $name
     * @param bool $on
     * @param string $label
     * @return string
     */
    private function option_checkbox($name, $on, $label) {
        if ($on) {
            $checked = ' checked="checked"';
        } else {
            $checked = '';
        }
        $name = 'courseselector_' . $name;
        // For the benefit of brain-dead IE, the id must be different from the name of the hidden form field above.
        // It seems that document.getElementById('frog') in IE will return and element with name="frog".
        $output = '<div class="form-check"><input type="hidden" name="' . $name . '" value="0" />' .
            '<label class="form-check-label" for="' . $name . 'id">' .
            '<input class="form-check-input" type="checkbox" id="' . $name . 'id" name="' . $name .
            '" value="1"' . $checked . ' /> ' . $label .
            "</label>
                   </div>\n";
        return $output;
    }

    /**
     * Initialises JS for this control.
     *
     * @param string $search
     * @return string any HTML needed here.
     */
    protected function initialise_javascript($search) {
        global $USER, $PAGE, $OUTPUT;
        $output = '';

        // Put the options into the session, to allow search.php to respond to the ajax requests.
        $options = $this->get_options();
        $hash = md5(serialize($options));
        $USER->courseselectors[$hash] = $options;

        // Initialise the selector.
        $PAGE->requires->js_init_call(
            'M.local_learnwise.init_course_selector',
            [$this->name, $hash, $this->extrafields, $search],
            false,
            self::$jsmodule
        );
        return $output;
    }

    /**
     * Initialise the JavaScript for AJAX requests.
     */
    public function initialise_javascript_for_ajax() {
        $search = optional_param($this->name . '_searchtext', '', PARAM_RAW);
        $this->initialise_javascript($search);
    }
}
