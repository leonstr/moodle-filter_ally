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
 * Filter for processing file links for Ally accessibility enhancements.
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @package   filter_ally
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

use filter_ally\renderables\wrapper;

/**
 * Filter for processing file links for Ally accessibility enhancements.
 * @author    Guy Thomas <gthomas@moodlerooms.com>
 * @package   filter_ally
 * @copyright Copyright (c) 2017 Blackboard Inc.
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class filter_ally extends moodle_text_filter {

    /**
     * Are we on a course page ? (not course settings, etc. The actual course page).
     * @return bool
     * @throws coding_exception
     */
    protected function is_course_page() {
        $path = parse_url(qualified_me())['path'];
        return (bool) preg_match('~/course/view.php$~', $path);
    }

    /**
     * Map file paths to pathname hash.
     * @return array
     * @throws coding_exception
     * @throws moodle_exception
     */
    protected function map_assignment_file_paths_to_pathhash() {
        global $PAGE;
        $map = [];

        if ($PAGE->pagetype === 'mod-assign-view') {
            $cmid = optional_param('id', false, PARAM_INT);
            if ($cmid === false) {
                return $map;
            }
            list($course, $cm) = get_course_and_cm_from_cmid($cmid);
            unset($course);
            /** @var cm_info $cm */
            $cm;
            $fs = get_file_storage();
            $files = $fs->get_area_files($cm->context->id, 'mod_assign', 'introattachment');
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $fullpath = $cm->context->id.'/mod_assign/introattachment/'.
                    $file->get_itemid().'/'.
                    $file->get_filepath().'/'.
                    $file->get_filename();
                $fullpath = str_replace('///', '/', $fullpath);
                $map[$fullpath] = $file->get_pathnamehash();
            }
        }

        return $map;
    }

    /**
     * Map moduleid to pathname hash.
     * @param $course
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function map_moduleid_to_pathhash($course) {
        global $DB;

        if (!$this->is_course_page()) {
            return [];
        }

        $modinfo = get_fast_modinfo($course);
        $modules = $modinfo->get_instances_of('resource');
        if (empty($modules)) {
            return [];
        }
        $contextsbymoduleid = [];
        $moduleidsbycontext = [];
        foreach ($modules as $modid => $module) {
            $contextsbymoduleid[$module->id] = $module->context->id;
            $moduleidsbycontext[$module->context->id] = $module->id;
        }

        list($insql, $params) = $DB->get_in_or_equal($contextsbymoduleid);

        $sql = "contextid $insql AND component = 'mod_resource' AND mimetype IS NOT NULL AND filename != '.'";

        $files = $DB->get_records_select('files', $sql, $params);
        $pathhashbymoduleid = [];
        foreach ($files as $id => $file) {
            $moduleid = $moduleidsbycontext[$file->contextid];
            $pathhashbymoduleid[$moduleid] = $file->pathnamehash;
        }

        return $pathhashbymoduleid;
    }

    /**
     * Set up the filter using settings provided in the admin settings page.
     * Also, get the file resource course module id -> file id mappings.
     *
     * @param $page
     * @param $context
     */
    public function setup($page, $context) {
        global $USER, $COURSE, $CFG;

        // This only requires execution once per request.
        static $jsinitialised = false;
        if (!$jsinitialised) {
            require_once($CFG->libdir.'/filelib.php');

            $modulefilemapping = $this->map_moduleid_to_pathhash($COURSE);
            $assignmentmap = $this->map_assignment_file_paths_to_pathhash();
            $jwt = \filter_ally\local\jwthelper::get_token($USER, $COURSE->id);
            $coursecontext = context_course::instance($COURSE->id);
            $canviewfeedback = has_capability('filter/ally:viewfeedback', $coursecontext);
            $candownload = has_capability('filter/ally:viewdownload', $coursecontext);

            $modulemaps = [
                'file_resources' => $modulefilemapping,
                'assignment_files' => $assignmentmap
            ];
            $json = json_encode($modulemaps);

            $script = <<<EOF
            <script>
                var ally_module_maps = $json;
            </script>
EOF;

            if (!isset($CFG->additionalhtmlfooter)) {
                $CFG->additionalhtmlfooter = '';
            }
            // Note, we have to put the module maps into the footer instead of passing them into the amd module as an
            // argument. If you pass large amounts of data into the amd arguments then it throws a debug error.
            $CFG->additionalhtmlfooter .= $script;

            $amdargs = [$jwt, $canviewfeedback, $candownload];
            $page->requires->js_call_amd('filter_ally/main', 'init', $amdargs);
            $jsinitialised = true;
        }
    }

    /**
     * Filters the given HTML text, looking for links pointing to files so that the file id data attribute can
     * be injected.
     *
     * @param $text HTML to be processed.
     * @param $options
     * @return string String containing processed HTML.
     */
    public function filter($text, array $options = array()) {
        global $PAGE;

        if (strpos($text, 'pluginfile.php') === false) {
            // No plugin files to process, so don't do anything expensive.
            return $text;
        }

        if (!isloggedin()) {
            return $text;
        }

        $fs = get_file_storage();
        $filesbyareakey = [];

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true); // Required for HTML5.
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $text);
        libxml_clear_errors(); // Required for HTML5.
        $elements = [];
        $results = $doc->getElementsByTagName('a');
        foreach ($results as $result) {
            $href = $result->attributes->getNamedItem('href')->nodeValue;
            if (strpos($href, 'pluginfile.php') !== false) {
                $elements[] = (object) [
                    'type' => 'a',
                    'url' => $href,
                    'result' => $result
                ];
            }
        }
        $results = $doc->getElementsByTagName('img');
        foreach ($results as $result) {
            $src = $result->attributes->getNamedItem('src')->nodeValue;
            if (strpos($src, 'pluginfile.php') !== false) {
                $elements[] = (object) [
                    'type' => 'img',
                    'url' => $src,
                    'result' => $result
                ];
            }
        }
        foreach ($elements as $key => $element) {
            $url = $element->url;

            if (strpos($url, 'pluginfile.php') !== false) {

                $regex = '/(?:.*)pluginfile\.php\/(\d*?)\/(.*)$/';
                $matches = [];
                preg_match($regex, $url, $matches);
                $contextid = $matches[1];
                $context = context::instance_by_id($contextid);
                $canviewfeedback = has_capability('filter/ally:viewfeedback', $context);
                $candownload = has_capability('filter/ally:viewdownload', $context);
                if (!$canviewfeedback && !$candownload) {
                    continue;
                }
                $arr = explode('/', $matches[2]);
                if (count($arr) === 3) {
                    $component = $arr[0];
                    $filearea = $arr[1];
                    $itemid = 0;
                    $filename = $arr[2];
                } else if (count($arr) === 4) {
                    $component = $arr[0];
                    $filearea = $arr[1];
                    $itemid = $arr[2];
                    $filename = $arr[3];
                } else {
                    // Not supported.
                    debugging("url not supported - $url");
                    continue;
                }
                $component = urldecode($component);
                $filename = urldecode($filename);
                $filearea = urldecode($filearea);

                $itempath = "/$contextid/$component/$filearea/$itemid";
                $filepath = "$itempath/$filename";

                $areakey = sha1($itempath);
                $pathhash = sha1($filepath);

                if (!isset($filesbyareakey[$areakey])) {
                    $files = $fs->get_area_files($contextid, $component, $filearea, $itemid);
                    $filekeys = array_keys($files);
                    unset($files);
                    $filekeys = array_combine($filekeys, $filekeys);
                    $filesbyareakey[$areakey] = $filekeys;
                }

                $filesbypathhash = $filesbyareakey[$areakey];
                if (!isset($filesbypathhash[$pathhash])) {
                    debugging('Failed to get the file '.$filepath);
                    continue;
                }

                $html = $doc->saveHTML($element->result);
                $type = $element->type;

                /** @var filter_ally_renderer $renderer */
                $renderer = $PAGE->get_renderer('filter_ally');
                $wrapper = new wrapper();
                $wrapper->fileid = $pathhash;
                // Flag html as processed with #P# so that it doesn't get hit again with multiples of the same link or image.
                $wrapper->html = str_replace('<'.$type, '<'.$type.'#P#', $html);
                $wrapper->url = $url;
                $wrapper->candownload = $candownload;
                $wrapper->canviewfeedback = $canviewfeedback;
                $wrapper->isimage = $type === 'img';
                $wrapped = $renderer->render_wrapper($wrapper);

                // Build a regex to cope with void tags closed by /> or >.
                if (substr($html, -2) === '/>') {
                    $stripclosingtag = substr($html, 0, strlen($html) - 2);
                } else {
                    $stripclosingtag = substr($html, 0, strlen($html) - 1);
                }

                $replaceregex = '~'.$stripclosingtag.'(?:\s*|)(?:>|/>)~m';

                $text = preg_replace($replaceregex, $wrapped, $text);
            }
        }

        // Remove temporary processed flags.
        $text = str_replace('#P#', '', $text);

        return $text;
    }
}
