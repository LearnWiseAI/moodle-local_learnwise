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
 * Class hook_callbacks
 *
 * @package    local_learnwise
 * @copyright  2025 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Callback executed before the standard footer HTML is generated.
     *
     * This method can be used to perform actions or modify data prior to rendering the footer.
     *
     * @param mixed $hook Optional hook parameter for additional context.
     * @return void|string
     */
    public static function before_standard_top_of_body_html_generation($hook = null) {
        global $PAGE, $OUTPUT, $USER, $COURSE;
        if (during_initial_install() || !isloggedin() || isguestuser()) {
            return false;
        }
        if (in_array($PAGE->pagelayout, ['maintenance', 'print', 'redirect'])) {
            // Do not try to show assist UI inside iframe, in maintenance mode,
            // when printing, or during redirects.
            return false;
        }
        $renderer = $OUTPUT;
        if (!empty($hook)) {
            $renderer = $hook->renderer;
        }
        $settings = get_config('local_learnwise');
        if (empty($settings->region)) {
            $settings->region = constants::REGION;
        }
        $html = '';
        if (strpos($PAGE->url->out(false), 'mod/lti/view.php') !== false) {
            $html .= <<<JS
<script>
(function() {
var iframe, iframeclone, iframeheight = innerHeight, iframeid = 'contentframe';
var intvalid = setInterval(() => {
    if (document.readyState != 'complete') {
        iframe = iframe || document.querySelector('iframe#' + iframeid);
        if (iframe && iframe.getAttribute('allow').indexOf('.learnwise.') === -1) {
            iframe = undefined;
        }
        if (iframe) {
            iframeclone = iframeclone || document.createElement('div');
            iframeclone.id = iframeid;
            iframeclone.style.display = 'none';
            iframe.setAttribute('id', iframeid + '1');
            document.body.append(iframeclone);
        }
    } else {
        if (iframe) {
            var navbar = document.querySelector('.navbar.fixed-top');
            var pageWrapper = document.querySelector('#page-wrapper');
            if (navbar) {
                iframeheight -= navbar.clientHeight;
            }
            iframe.setAttribute('id', iframeid);
            iframe.style.height = iframeheight + 'px';
            iframe.style.minHeight = '700px';
            if (pageWrapper && pageWrapper.clientHeight === pageWrapper.scrollHeight) {
                iframe.scrollIntoView({behavior: 'smooth'});
            } else {
                var offsetPosition = iframe.getBoundingClientRect().top;
                if (navbar) {
                    offsetPosition -= navbar.clientHeight;
                }
                scrollTo({top: offsetPosition, behavior: 'smooth'});
            }
            if (iframeclone) {
                iframeclone.setAttribute('id', iframeid + '1');
            }
        }
        clearInterval(intvalid);
    }
}, 1);
})();
</script>
JS;
        }
        if ($PAGE->pagelayout === 'embedded') {
            if ($PAGE->bodyid === 'page-mod-assign-grader' && !empty($settings->aiassessment)) {
                $templatedata = [
                    'host' => util::get_assessmenthosturl(),
                    'feedbacksrc' => util::get_assessmenthosturl(),
                    'assistantid' => $settings->assistantid,
                    'courseid' => $PAGE->course->id,
                    'cmid' => $PAGE->cm->id,
                    'region' => $settings->region,
                    'loggedinuser' => [
                        'id' => $USER->id,
                        'fullname' => fullname($USER),
                        'email' => $USER->email,
                    ],
                ];
                $html .= $renderer->render_from_template('local_learnwise/aiassessmentassistant', $templatedata);
            }
            if (empty($html)) {
                return;
            }
        }
        if (!empty($settings->showassistantwidget) && !empty($settings->assistantid)) {
            $configcourseids = !empty($settings->courseids) ? explode(',', $settings->courseids) : [];
            if (empty($configcourseids) || in_array($PAGE->course->id, $configcourseids)) {
                $html .= $renderer->render_from_template('local_learnwise/assistantwidget', [
                    'assistantid' => $settings->assistantid,
                    'courseid' => $COURSE->id > SITEID ? $COURSE->id : null,
                    'userid' => $USER->id,
                    'userfullname' => fullname($USER),
                    'useremail' => $USER->email,
                    'remotehost' => util::get_remotehosturl(),
                    'chathost' => util::get_ltitoolurl(),
                    'region' => $settings->region,
                ]);
            }
        }
        if (!empty($hook)) {
            $hook->add_html($html);
        } else {
            return $html;
        }
    }
}
