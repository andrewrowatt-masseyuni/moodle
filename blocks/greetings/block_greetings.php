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

/**
 * Block greetings is defined here.
 *
 * @package     block_greetings
 * @copyright   2023 Your Name <you@example.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/greetings/lib.php');

class block_greetings extends block_base {

    /**
     * Initializes class member variables.
     */
    public function init() {
        // Needed by Moodle to differentiate between blocks.
        $this->title = get_string('pluginname', 'block_greetings');
    }

    /**
     * Returns the block contents.
     *
     * @return stdClass The block contents.
     */
    public function get_content() {
        global $CFG, $DB, $OUTPUT, $USER;

        if ($this->content !== null) {
            return $this->content;
        }

        if (empty($this->instance)) {
            $this->content = '';
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->items = [];
        $this->content->icons = [];
        $this->content->footer = '';

        if (!empty($this->config->text)) {
            $this->content->text = $this->config->text;
        } else {
            $context = $this->page->context;
            $text = block_greetings_get_greeting($USER);

            $allowpost = has_capability('block/greetings:postmessages', $context);
            $deletepost = has_capability('block/greetings:deleteownmessage', $context);
            $deleteanypost = has_capability('block/greetings:deleteanymessage', $context);

            $action = optional_param('action', '', PARAM_TEXT);

            if ($action == 'del') {
                require_sesskey();

                $id = required_param('id', PARAM_TEXT);

                if ($deleteanypost || $deletepost) {
                    $params = array('id' => $id);

                    // Users without permission should only delete their own post.
                    if (!$deleteanypost) {
                        $params += ['userid' => $USER->id];
                    }

                    // TODO: Confirm before deleting.
                    $DB->delete_records('block_greetings_messages', $params);

                    redirect($CFG->wwwroot . '/my'); // Reload this page to remove visible sesskey.
                }
            }

            $messageform = new \block_greetings\form\message_form();

            if ($data = $messageform->get_data()) {
                require_capability('local/greetings:postmessages', $context);

                $message = required_param('message', PARAM_TEXT);

                if (!empty($message)) {
                    $record = new stdClass;
                    $record->message = $message;
                    $record->timecreated = time();
                    $record->userid = $USER->id;

                    $DB->insert_record('block_greetings_messages', $record);

                    redirect($CFG->wwwroot . '/my'); // Reload this page to load empty form.
                }
            }

            if ($allowpost) {
                $text .= $messageform->render();
            }

            if (has_capability('local/greetings:viewmessages', $context)) {
                $userfields = \core_user\fields::for_name()->with_identity($context);
                $userfieldssql = $userfields->get_sql('u');

                $sql = "SELECT m.id, m.message, m.timecreated, m.userid {$userfieldssql->selects}
                        FROM {block_greetings_messages} m
                        LEFT JOIN {user} u ON u.id = m.userid
                        ORDER BY timecreated DESC";

                $messages = $DB->get_records_sql($sql);

                $text .= $OUTPUT->box_start('card-columns');

                // Card background colour.
                // Use value from block instance, if set. Otherwise use global value.
                $cardbackgroundcolor = (isset($this->config->messagecardbgcolor) && !empty($this->config->messagecardbgcolor))
                                        ? $this->config->messagecardbgcolor
                                        : get_config('block_greetings', 'messagecardbgcolor');

                foreach ($messages as $m) {
                    $text .= html_writer::start_tag('div', array('class' => 'card', 'style' => "background: $cardbackgroundcolor"));
                    $text .= html_writer::start_tag('div', array('class' => 'card-body'));
                    $text .= html_writer::tag('p', format_text($m->message, FORMAT_PLAIN), array('class' => 'card-text'));
                    $text .= html_writer::tag('p', get_string('postedby', 'block_greetings', $m->firstname),
                                                array('class' => 'card-text'));
                    $text .= html_writer::start_tag('p', array('class' => 'card-text'));
                    $text .= html_writer::tag('small', userdate($m->timecreated), array('class' => 'text-muted'));
                    $text .= html_writer::end_tag('p');

                    // Wrapping this within the "Delete" capability check for simplicity.
                    // You can also create another capability for "Edit messages" if you want.
                    if ($deleteanypost || ($deletepost && $m->userid == $USER->id)) {
                        $text .= html_writer::start_tag('p', array('class' => 'card-footer text-center'));

                        $text .= html_writer::link(
                            new moodle_url(
                                '/my/',
                                ['id' => $m->id]
                            ),
                            $OUTPUT->pix_icon('i/edit', get_string('edit')),
                            ['role' => 'button']
                        );

                        $text .= html_writer::link(
                            new moodle_url(
                                '/my/',
                                ['action' => 'del', 'id' => $m->id, 'sesskey' => sesskey()]
                            ),
                            $OUTPUT->pix_icon('t/delete', get_string('delete')),
                            ['role' => 'button']
                        );
                        $text .= html_writer::end_tag('p');
                    }

                    $text .= html_writer::end_tag('div');
                    $text .= html_writer::end_tag('div');
                }

                $text .= $OUTPUT->box_end();
            }

            $this->content->text = $text;
        }

        return $this->content;
    }

    /**
     * Defines configuration data.
     *
     * The function is called immediately after init().
     */
    public function specialization() {

        // Load user defined title and make sure it's never empty.
        if (empty($this->config->title)) {
            $this->title = get_string('pluginname', 'block_greetings');
        } else {
            $this->title = $this->config->title;
        }
    }

    /**
     * Enables global configuration of the block in settings.php.
     *
     * @return bool True if the global configuration is enabled.
     */
    public function has_config() {
        return true;
    }

    /**
     * Sets the applicable formats for the block.
     *
     * @return string[] Array of pages and permissions.
     */
    public function applicable_formats() {
        return ['my' => true];
    }
}
