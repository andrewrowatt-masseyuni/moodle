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
 * TODO describe file cmitem
 *
 * @package    format_mutopics
 * @copyright  2025 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace format_mutopics\output\courseformat\content\section;

use core_courseformat\output\local\content\section\cmitem as cmitem_base;

class cmitem extends cmitem_base {

    /**
     * Returns the output class template path.
     *
     * This method redirects the default template when the section activity item is rendered.
     */
    public function get_template_name(\renderer_base $renderer): string {
        return 'format_mutopics/local/content/section/cmitem';
    }
}