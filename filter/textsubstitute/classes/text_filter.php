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

namespace filter_textsubstitute;

/**
 * textsubstitute filter
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/filter}
 *
 * @package    filter_textsubstitute
 * @copyright  2024 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /**
     * Filter text
     *
     * @param string $text some HTML content to process.
     * @param array $options options passed to the filters
     * @return string the HTML content after the filtering has been applied.
     */
    public function filter($text, array $options = []) {
        if (!isset($options['originalformat'])) {
            // If the format is not specified, we are probably called by {@see format_string()}
            // in that case, it would be dangerous to replace URL with the link because it could
            // be stripped. therefore, we do nothing.
            return $text;
        }
        if (in_array($options['originalformat'], explode(',', get_config('filter_textsubstitute', 'formats')))) {
            $this->filter_content($text);
        }

        return $text;
    }

    /**
     * Given some text this function substitutes text
     *
     * @param string $text Passed in by reference. Based on urltolink filter.
     */
    private function filter_content(&$text): void {
        $searchterm = get_config('filter_textsubstitute', 'searchterm');
        $replacewith = get_config('filter_textsubstitute', 'substituteterm');
        $text = str_replace($searchterm, $replacewith, $text);
    }
}
