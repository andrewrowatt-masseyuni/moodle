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
$editanypost = has_capability('local/greetings:editanymessage', $context);

$action = optional_param('action', '', PARAM_TEXT);

if ($action == 'del') {
    // Display standard (unfriendly) Moodle error.
    require_capability('local/greetings:deleteanymessage', $context);

    require_sesskey();

    if ($deleteanypost) {
        $id = required_param('id', PARAM_TEXT);

        $DB->delete_records('local_greetings_messages', ['id' => $id]);

        // Cleans/removes sesskey information from the URL as this is a security vulerability.
        // We are only using this approach for the learning process.
        redirect($PAGE->url);
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
        redirect($PAGE->url);
    }
}

$output = $PAGE->get_renderer('local_greetings');
echo $output->header();

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

$messagesmustache = [];

if ($allowview) {
    $userfields = \core_user\fields::for_name()->with_identity($context);
    $userfieldssql = $userfields->get_sql('u');

    $sql = "SELECT m.id, m.message, m.timecreated, m.userid {$userfieldssql->selects}
            FROM {local_greetings_messages} m
        LEFT JOIN {user} u ON u.id = m.userid
        ORDER BY timecreated DESC";

    $messages = $DB->get_records_sql($sql);

    foreach ($messages as $m) {
        $messagemustache = [
            'id' => $m->id,
            'by' => $m->firstname,
            'message' => format_text($m->message, FORMAT_PLAIN),
            'date' => userdate($m->timecreated),
            'canedit' => $editanypost || $m->userid == $USER->id,
            'candelete' => $deleteanypost,
        ];

        array_push($messagesmustache, $messagemustache);
    }
}

$renderable = new \local_greetings\output\index_posts($messagesmustache, get_config('local_greetings', 'messagecardbgcolor'));
echo $output->render($renderable);

echo $output->footer();
