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

namespace local_greetings\output;

use renderable;
use renderer_base;
use templatable;
use stdClass;

/**
 * Class layout_test_page
 *
 * @package    local_greetings
 * @copyright  2024 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class layout_test_page implements renderable, templatable {
    /** @var string $sometext Some text to pass data to a template. */
    private $sometext = null;

    /**
     * Standard constructor.
     *
     * @param string $sometext Default text.
     */
    public function __construct(string $sometext) {
        $this->sometext = $sometext;
    }

    /**
     * Export data to be used as the context for a mustache template.
     *
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $data = new stdClass();
        $data->sometext = $this->sometext;
        $data->grades = [10,99];

        return $data;
    }
}
