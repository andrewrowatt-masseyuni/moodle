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
 * Library of functions for local_dbapis
 *
 * @package     local_dbapis
 * @copyright   2023 Your name <your@email>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Insert a link to index.php on the site front page navigation menu.
 *
 * @param navigation_node $frontpage Node representing the front page in the navigation tree.
 */
function local_dbapis_extend_navigation_frontpage(navigation_node $frontpage) {
    if (isloggedin() && !isguestuser()) {
        $frontpage->add(
            get_string('pluginname', 'local_dbapis'),
            new moodle_url('/local/dbapis/'),
            navigation_node::TYPE_CUSTOM,
        );
    }
}

/**
 * Add a post to the database.
 *
 * @param string $message text of the message.
 */
function addpost(string $message): void {
    global $USER;
    global $DB;

    $userid = $USER->id;

    $record = new stdClass;
    $record->message = $message;
    $record->timecreated = time();
    $record->userid = $USER->id;

    $DB->insert_record('local_dbapis', $record);
}

/**
 * Search the database.
 *
 * @param string $searchterm text to find in the messages.
 */
function searchposts(string $searchterm): array {
    global $DB;

    $likesearchterm = $DB->sql_like('message',':searchterm');

    $result = $DB->get_records_sql("select m.*, u.firstname,u.lastname from {local_dbapis} m LEFT JOIN {user} u ON u.id = m.userid where {$likesearchterm}",['searchterm' => '%'.$searchterm.'%']);
    
    return $result;
}

/**
 * Delete a message
 *
 * @param int $id ID of message to delete.
 */
function deletepost(int $id): void {
    global $DB;

    $DB->delete_records('local_dbapis',['id' => $id]);
}

