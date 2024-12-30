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
 * TODO describe module helloworld
 *
 * @module     block_greetings/helloworld
 * @copyright  2024 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import DynamicForm from 'core_form/dynamicform';

const dynamicForm = new DynamicForm(document.querySelector('#formcontainer'), 'block_greetings\\form\\message_form');

export const init = () => {
    dynamicForm.addEventListener(dynamicForm.events.FORM_SUBMITTED, (e) => {
        e.preventDefault();
        const response = e.detail;
        window.console.log(response);
        // It is recommended to reload the form after submission because the elements may change.
        // This will also remove previous submission errors. You will need to pass the same arguments to the form
        // that you passed when you rendered the form on the page.
        dynamicForm.load({arg1: 'val1'});
    });

    window.console.log(`Hello from JavaScript.`);
};