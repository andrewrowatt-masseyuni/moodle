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

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/greetings/index.php'));
$PAGE->set_pagelayout('standard');


$PAGE->set_title(get_string('pluginname', 'local_greetings'));
$PAGE->set_heading(get_string('pluginname', 'local_greetings'));
require_login();

if (isguestuser()) {
    throw new moodle_exception('noguest');
}

$allowpost = has_capability('local/greetings:postmessages', $context);
$allowview = has_capability('local/greetings:viewmessages', $context);
$deleteanypost = has_capability('local/greetings:deleteanymessage', $context);

$action = optional_param('action', '', PARAM_TEXT);

if ($action == 'del') {
    require_capability('local/greetings:deleteanymessage', $context);
    
    if ($deleteanypost) {
        $id = required_param('id', PARAM_TEXT);

        $DB->delete_records('local_greetings_messages', ['id' => $id]);
    }
}

$messageform = new \local_greetings\form\message_form();

if ($data = $messageform->get_data()) {
    require_capability('local/greetings:postmessages', $context);
    $message = required_param('message', PARAM_TEXT);

    if (!empty($message)) {
        $record = new stdClass;
        $record->message = $message;
        $record->timecreated = time();
        $record->userid = $USER->id;

        $DB->insert_record('local_greetings_messages', $record);
    }
}

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

if ($allowpost) {
    $messageform->display();
}

if ($allowview) {
    $userfields = \core_user\fields::for_name()->with_identity($context);
    $userfieldssql = $userfields->get_sql('u');

    $sql = "SELECT m.id, m.message, m.timecreated, m.userid {$userfieldssql->selects}
            FROM {local_greetings_messages} m
        LEFT JOIN {user} u ON u.id = m.userid
        ORDER BY timecreated DESC";

    $messages = $DB->get_records_sql($sql);

    echo $OUTPUT->box_start('card-columns');

    foreach ($messages as $m) {
        echo html_writer::start_tag('div', ['class' => 'card']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('p', format_text($m->message, FORMAT_PLAIN), ['class' => 'card-text']);
        echo html_writer::tag('p', get_string('postedby', 'local_greetings', $m->firstname), ['class' => 'card-text']);
        echo html_writer::start_tag('p', ['class' => 'card-text']);
        echo html_writer::tag('small', userdate($m->timecreated), ['class' => 'text-muted']);
        echo html_writer::end_tag('p');
        echo html_writer::end_tag('div');

        if ($deleteanypost) {
            echo html_writer::start_tag('p', ['class' => 'card-footer text-center']);
            echo html_writer::link(
                new moodle_url(
                    '/local/greetings/index.php',
                    ['action' => 'del', 'id' => $m->id]
                ),
                $OUTPUT->pix_icon('t/delete', '') . get_string('delete')
            );
            echo html_writer::end_tag('p');
        }

        echo html_writer::end_tag('div');
    }

    echo $OUTPUT->box_end();
}

echo $OUTPUT->footer();
