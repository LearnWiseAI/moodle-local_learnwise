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

use context_course;
use context_module;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_learnwise\util;
use stdClass;

/**
 * Class books
 *
 * @package    local_learnwise
 * @copyright  2026 LearnWise <help@learnwise.ai>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class books extends baseapi {
    /**
     * Summary of route
     * @var string
     */
    public static $route = 'books';

    /**
     * Summary of execute_parameters
     * @return \external_function_parameters
     */
    public static function execute_parameters() {
        return static::base_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'id' => new external_value(PARAM_INT, 'id', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Summary of execute
     * @param int $courseid
     * @param int $id
     * @return array
     */
    public static function execute($courseid, $id) {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/lib/externallib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/mod/book/lib.php');
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        $params = static::validate_parameters(static::execute_parameters(), [
            'courseid' => $courseid,
            'id' => $id,
        ]);

        if ($params['id'] > 0) {
            self::$ids[self::class] = $id;
        }

        $context = context_course::instance($params['courseid']);
        if (static::is_singleoperation()) {
            $context = context_module::instance(static::get_id());
        }
        static::validate_context($context);
        if (static::is_singleoperation()) {
            require_capability('mod/book:read', $context);
        }

        $course = get_course($params['courseid']);
        $cms = get_coursemodules_in_course('book', $course->id, 'm.customtitles,m.revision,m.intro');
        if (!$cms) {
            return [];
        }

        $usesections = course_format_uses_sections($course->format);
        $modinfo = get_fast_modinfo($course);

        if ($usesections) {
            $sections = $modinfo->get_section_info_all();
        }

        $bookinfos = [];
        foreach ($modinfo->instances['book'] as $cm) {
            if (static::skip_record($cm->id)) {
                continue;
            }
            $context = context_module::instance($cm->id);
            if (!has_capability('mod/book:read', $context)) {
                continue;
            }

            if (!isset($cms[$cm->id])) {
                continue;
            }
            $bookcm = $cms[$cm->id];

            $sectionname = null;
            if ($usesections && $cm->sectionnum) {
                $sectionname = get_section_name($course, $sections[$cm->sectionnum]);
            }

            $bookinfo = [
                'id' => $cm->id,
                'name' => $cm->get_formatted_name(),
                'sectionname' => $sectionname,
                'revision' => $bookcm->revision,
                'customtitles' => $bookcm->customtitles,
                'course_id' => $cm->course,
            ];

            $intro = file_rewrite_pluginfile_urls(
                $bookcm->intro,
                'pluginfile.php',
                $context->id,
                'mod_book',
                'intro',
                null
            );
            $bookinfo['description'] = content_to_text($intro, (int) FORMAT_MARKDOWN);
            $bookinfo['descriptionfiles'] = util::extract_pluginfile_urls_from_text(
                $bookcm->intro,
                $context->id,
                'mod_book',
                'intro',
                null
            );

            if (static::is_singleoperation()) {
                $bookchapters = [];

                $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
                $chapters = $DB->get_records('book_chapters', ['bookid' => $book->id], 'pagenum');
                $currentchapterid = 0;
                $canviewhiddenchapters = has_capability('mod/book:viewhiddenchapters', $context);
                $formatoptions = new stdClass();
                $formatoptions->noclean = true;
                $formatoptions->overflowdiv = false;
                $formatoptions->context = $context;

                foreach ($chapters as $chapter) {
                    if ($chapter->hidden && !$canviewhiddenchapters) {
                        continue;
                    }

                    $currentchapter = [
                        'id'            => $chapter->id,
                        'title'         => format_string($chapter->title, true, ['context' => $context]),
                        'level'         => 0,
                        'hassubitems'   => false,
                        'hidden'        => $chapter->hidden,
                    ];

                    $content = file_rewrite_pluginfile_urls(
                        $chapter->content,
                        'pluginfile.php',
                        $context->id,
                        'mod_book',
                        'chapter',
                        $chapter->id
                    );

                    $content = format_text($content, $chapter->contentformat, $formatoptions);

                    $titles = '';
                    if (!$book->customtitles) {
                        $chapters = book_preload_chapters($book);

                        if (!$chapter->subchapter) {
                            $currtitle = book_get_chapter_title($chapter->id, $chapters, $book, $context);
                            $titles = "<h3>{$currtitle}</h3>";
                        } else {
                            $currtitle = book_get_chapter_title($chapters[$chapter->id]->parent, $chapters, $book, $context);
                            $currsubtitle = book_get_chapter_title($chapter->id, $chapters, $book, $context);
                            $titles = "<h3>{$currtitle}</h3>";
                            $titles .= "<h4>{$currsubtitle}</h4>";
                        }
                    }

                    $content = $titles . $content;
                    $currentchapter['content'] = $content;

                    if (!$chapter->subchapter) {
                        // Main chapter.
                        $currentchapterid = $chapter->id;
                        $bookchapters[$currentchapterid] = $currentchapter;
                    } else {
                        // Subchapter.
                        $currentchapter['level']++;
                        $currentchapter['parentid'] = $currentchapterid;
                        $bookchapters[$currentchapterid]['hassubitems'] = true;
                        $bookchapters[$currentchapter['id']] = $currentchapter;
                    }
                }

                if (!empty($bookchapters)) {
                    $bookinfo['chapters'] = $bookchapters;
                }

                if (!self::called_native_endpoint()) {
                    return $bookinfo;
                }
            }

            $bookinfos[] = $bookinfo;
        }
        return $bookinfos;
    }

    /**
     * Summary of execute_returns
     * @return external_single_structure
     */
    public static function single_structure() {
        $structure = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'course module id of book'),
            'name' => new external_value(PARAM_TEXT, 'name of book'),
            'description' => new external_value(PARAM_TEXT, 'Description of book'),
            'descriptionfiles' => new external_multiple_structure(
                new external_value(PARAM_URL, 'Description file url'),
                'URL of description files',
                VALUE_OPTIONAL
            ),
            'sectionname' => new external_value(PARAM_TEXT, 'name of section that book belongs to'),
            'revision' => new external_value(PARAM_INT, 'book revision'),
            'course_id' => new external_value(PARAM_INT, 'course id'),
            'customtitles' => new external_value(PARAM_BOOL, 'book custom titles type'),
        ]);
        if (static::is_singleoperation()) {
            $structure->keys['chapters'] = new external_multiple_structure(
                self::get_chapter_response_structure(),
                'chapters',
                VALUE_OPTIONAL
            );
        }
        return $structure;
    }

    /**
     * Get single book chapter response structure
     * @return external_single_structure
     */
    public static function get_chapter_response_structure() {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'id'),
            'title' => new external_value(PARAM_RAW, 'title'),
            'level' => new external_value(PARAM_INT, 'level'),
            'hassubitems' => new external_value(PARAM_BOOL, 'contains sub levels'),
            'hidden' => new external_value(PARAM_BOOL, 'hidden'),
            'content' => new external_value(PARAM_RAW, 'contents in html format'),
            'parentid' => new external_value(PARAM_INT, 'parent id', VALUE_OPTIONAL),
        ], 'chapter');
    }

    /**
     * Returns the structure for the API response.
     *
     * @return external_multiple_structure|external_single_structure
     */
    public static function execute_returns() {
        $returnstructure = parent::execute_returns();
        if (self::called_native_endpoint() && $returnstructure->content instanceof external_single_structure) {
            $returnstructure->content->keys['chapters'] = new external_multiple_structure(
                self::get_chapter_response_structure(),
                'chapters',
                VALUE_OPTIONAL
            );
        }
        return $returnstructure;
    }
}
