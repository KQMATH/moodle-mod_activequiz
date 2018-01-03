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
 * Simple callback page to handle the many hits for quiz status when running
 *
 * This is used so the javascript can act accordingly to the instructor's actions
 *
 * @package   mod_jazzquiz
 * @author    Sebastian S. Gundersen <sebastsg@stud.ntnu.no>
 * @copyright 2014 University of Wisconsin - Madison
 * @copyright 2018 NTNU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_jazzquiz;

define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_sesskey();

function print_json($array)
{
    echo json_encode($array);
}

// If they've passed the sesskey information grab the session info
$session_id = required_param('sessionid', PARAM_INT);

// First determine if we get a session.
$session = $DB->get_record('jazzquiz_sessions', [ 'id' => $session_id ]);
if (!$session) {
    print_json([
        'status' => 'error',
        'message' => "Invalid session $session_id"
    ]);
    exit;
}

// Next we need to get the JazzQuiz object and course module object to make sure a student can log in for the session asked for
$jazzquiz = $DB->get_record('jazzquiz', [ 'id' => $session->jazzquizid ]);
if (!$jazzquiz) {
    print_json([
        'status' => 'error',
        'message' => "Invalid JazzQuiz $session->jazzquizid"
    ]);
    exit;
}

try {
    $course = $DB->get_record('course', [ 'id' => $jazzquiz->course ], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('jazzquiz', $jazzquiz->id, $course->id, false, MUST_EXIST);
    require_login($course->id, false, $cm, false, true);
} catch (\Exception $e) {
    print_json([
        'status' => 'error',
        'message' => 'Did not find course ' . $jazzquiz->course
    ]);
    exit;
}

// Check if the session is open
if ($session->sessionopen == 0) {
    print_json([
        'status' => 'sessionclosed',
        'message' => 'The specified session is closed'
    ]);
    exit;
}

switch ($session->status) {

    // Just a generic response with the state
    case 'notrunning':
        $jazzquiz = new jazzquiz($cm->id);
        if ($jazzquiz->is_instructor()) {
            $session = new jazzquiz_session($jazzquiz, $session);
            $attempts = $session->get_all_attempts(false, 'open');
            print_json([
                'status' => $session->data->status,
                'student_count' => count($attempts)
            ]);
            exit;
        }
        // fall-through
    case 'preparing':
    case 'reviewing':
        print_json([
            'status' => $session->status,
        ]);
        exit;

    // TODO: Not send options here. Quizdata should probably take care of that.
    case 'voting':
        $vote_options = $DB->get_records('jazzquiz_votes', ['sessionid' => $session_id]);
        $options = [];
        $html = '<div class="jazzquiz-vote">';
        $i = 0;
        foreach ($vote_options as $vote_option) {
            $options[] = [
                'text' => $vote_option->attempt,
                'id' => $vote_option->id,
                'question_type' => $vote_option->qtype,
                'content_id' => "vote_answer_label_$i"
            ];
            $html .= '<label>';
            $html .= '<input type="radio" name="vote" value="' . $vote_option->id . '" onclick="jazzquiz.vote_answer = this.value;">';
            $html .= '<span id="vote_answer_label_' . $i . '">' . $vote_option->attempt . '</span>';
            $html .= '</label><br>';
            $i++;
        }
        $html .= '</div>';
        $html .= '<button class="btn" onclick="jazzquiz.save_vote(); return false;">Save</button>';
        print_json([
            'status' => 'voting',
            'html' => $html,
            'options' => $options
        ]);
        exit;

    // Send the currently active question
    case 'running':
        print_json([
            'status' => 'running',
            'question_time' => $session->currentquestiontime,
            'delay' => $session->nextstarttime - time()
        ]);
        exit;

    // This should not be reached, but if it ever is, let's just assume the quiz is not running.
    default:
        print_json([
            'status' => 'notrunning',
            'message' => 'Unknown error. State: ' . $session->status
        ]);
        exit;
}
