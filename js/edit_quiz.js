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
 * Edit quiz javascript to implement drag and drop on the page
 *
 * @package    mod_jazzquiz
 * @author     Sebastian S. Gundersen <sebastsg@stud.ntnu.no>
 * @copyright  2015 University of Wisconsin - Madison
 * @copyright  2018 NTNU
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Submit the question order to the server. An empty array will delete all questions.
 * @param {Array.<number>} order
 */
function submitQuestionOrder(order) {
    jQuery.post('edit.php', {
        id: jazzquiz.quiz.courseModuleId,
        action: 'order',
        order: JSON.stringify(order)
    }, function() {
        // TODO: Correct locally instead, but for now just refresh.
        location.reload();
    });
}

/**
 * @returns {Array} The current question order.
 */
function getQuestionOrder() {
    let order = [];
    jQuery('.questionlist li').each(function() {
        order.push(jQuery(this).data('question-id'));
    });
    return order;
}

/**
 * Move a question up or down by a specified offset.
 * @param {number} questionId
 * @param {number} offset Negative to move down, positive to move up
 * @returns {Array}
 */
function offsetQuestion(questionId, offset) {
    let order = getQuestionOrder();
    let originalIndex = order.indexOf(questionId);
    if (originalIndex === -1) {
        return order;
    }
    for (let i = 0; i < order.length; i++) {
        if (i + offset === originalIndex) {
            order[originalIndex] = order[i];
            order[i] = questionId;
            break;
        }
    }
    return order;
}

window.addEventListener('load', function() {
    jazzquiz.decodeState();

    // TODO: Timeout because jQuery is not loaded yet when this runs. Modules should be used later on.
    setTimeout(function() {
        jQuery('.edit-question-action').on('click', function() {
            const action = jQuery(this).data('action');
            const question_id = jQuery(this).data('question-id');
            let order = [];
            switch (action) {
                case 'up':
                    order = offsetQuestion(question_id, 1);
                    break;
                case 'down':
                    order = offsetQuestion(question_id, -1);
                    break;
                case 'delete':
                    order = getQuestionOrder();
                    const index = order.indexOf(question_id);
                    if (index !== -1) {
                        order.splice(index, 1);
                    }
                    break;
                default:
                    return;
            }
            submitQuestionOrder(order);
        });
    }, 500);

    let questionList = document.getElementsByClassName('questionlist')[0];
    Sortable.create(questionList, {
        handle: '.dragquestion',
        onSort: function() {
            const order = getQuestionOrder();
            submitQuestionOrder(order);
        }
    });
});
