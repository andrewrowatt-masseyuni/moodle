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

namespace block_greetings\form;

use context;
use context_system;
use moodle_url;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Class message_form
 *
 * @package     block_greetings
 * @copyright  2022 Your name <your@email>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class message_form extends \core_form\dynamic_form {
    /**
     * Define the form.
     */
    public function definition() {
        $mform = $this->_form; // Don't forget the underscore!

        $mform->addElement('textarea', 'message', get_string('yourmessage', 'block_greetings')); // Add elements to your form.
        $mform->setType('message', PARAM_TEXT); // Set type of element.

        // If editing the form, load data from db.
        if (isset($this->_customdata['message'])) {
            $message = $this->_customdata['message'];

            $mform->addElement('hidden', 'id', $message->id);
            $mform->setType('id', PARAM_INT); // Set type of element.

            $mform->setDefault('message', $message->message);
        }

        $submitlabel = get_string('submit');
        $mform->addElement('submit', 'submitmessage', $submitlabel);
    }

    /**
     * Returns context where this form is used
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $context = context_system::instance();
        return $context;
    }

    /**
     * Checks if current user has access to this form, otherwise throws exception
     */
    protected function check_access_for_dynamic_submission(): void {
        $context = context_system::instance();
        require_capability('block/greetings:postmessages', $context);
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     */
    public function process_dynamic_submission() {
        global $DB, $USER;

        // $this->get_data()

        // throw new moodle_exception('nopermissions', '', $this->page->url->out(), 'AJR');

        if ($data = $this->get_data()) {
            $context = context_system::instance();

            require_capability('local/greetings:postmessages', $context);

            // $message = required_param('message', PARAM_TEXT);

            if (!empty($data->message)) {
                $record = new stdClass();
                $record->message = $data->message;
                $record->timecreated = time();
                $record->userid = $USER->id;

                $DB->insert_record('block_greetings_messages', $record);
            }
        }
    }

    /**
     * Load in existing data as form defaults
     */
    public function set_data_for_dynamic_submission(): void {
        $record = [
            'message' => 'New message here!',
            'timecreated' => time(),
            'userid' => 1,
        ];

        $this->set_data($record);
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/my');
    }
}
