<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace format_mutopics\output;

use core_courseformat\base as format_base;
use core_courseformat\output\section_renderer;
use moodle_page;

/**
 * MU Topics content class.
 *
 * @package     format_mutopics
 * @copyright   2025 Andrew Rowatt A.J.Rowatt@massey.ac.nz
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class renderer extends section_renderer {
    /**
    * Generate the section title, wraps it in a link to the section page if page is to be displayed on a separate page.
    *
    * This method is required to enable the inplace section title editor.
    *
    * @param section_info|stdClass $section The course_section entry from DB
    * @param stdClass $course The course entry from DB
    * @return string HTML to output.
    */
    public function section_title($section, $course) {
    return $this->render(format_base::instance($course)->inplace_editable_render_section_name($section));
    }

    /**
    * Generate the section title to be displayed on the section page, without a link.
    *
    * This method is required to enable the inplace section title editor.
    *
    * @param section_info|stdClass $section The course_section entry from DB
    * @param int|stdClass $course The course entry from DB
    * @return string HTML to output.
    */
    public function section_title_without_link($section, $course) {
        return $this->render(format_base::instance($course)->inplace_editable_render_section_name($section, false));
    }
}