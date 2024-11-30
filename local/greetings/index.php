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
 * TODO describe file index
 *
 * @package    local_greetings
 * @copyright  2024 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot. '/local/greetings/lib.php');
require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/greetings/index.php'));
$PAGE->set_pagelayout('standard');


$PAGE->set_title(get_string('pluginname', 'local_greetings'));
$PAGE->set_heading(get_string('pluginname', 'local_greetings'));

$messageform = new \local_greetings\form\message_form();

echo $OUTPUT->header();

if (isloggedin()) {
    echo html_writer::tag('h3', local_greetings_get_greeting($USER), [
        'class' => 'ajr_class',
    ]);
} else {
    echo html_writer::tag('h3', get_string('greetinguser', 'local_greetings'), [
        'class' => 'ajr_class',
    ]);
}

$messageform->display();

if ($data = $messageform->get_data()) {
    // Useful debug technique! var_dump($data);.

    $message = required_param('message', PARAM_TEXT);

    echo $OUTPUT->heading($message, 4);
}

echo $OUTPUT->footer();
