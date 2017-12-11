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

namespace mod_jazzquiz;

defined('MOODLE_INTERNAL') || die();

/**
 * jazzquiz Attempt wrapper class to encapsulate functions needed to individual
 * attempt records
 *
 * @package     mod_jazzquiz
 * @author      John Hoopes <moodle@madisoncreativeweb.com>
 * @copyright   2014 University of Wisconsin - Madison
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jazzquiz_attempt
{
    /** Constants for the status of the attempt */
    const NOTSTARTED = 0;
    const INPROGRESS = 10;
    const ABANDONED = 20;
    const FINISHED = 30;

    /** @var \stdClass The attempt record */
    protected $attempt;

    /** @var questionmanager $questionmanager $the queestion manager for the class */
    protected $questionmanager;

    /** @var \question_usage_by_activity $quba the question usage by activity for this attempt */
    protected $quba;

    /** @var int $qnum The question number count when rendering questions */
    protected $qnum;

    /** @var bool $lastquestion Signifies if this is the last question
     *  Is used during quiz callbacks to help with instructor control
     */
    public $lastquestion;

    /** @var \context_module $context The context for this attempt */
    protected $context;

    /** @var string $response summary HTML fragment of the response summary for the current question */
    public $responsesummary;

    /** @var  array $slotsbyquestionid array of slots keyed by the questionid that they match to */
    protected $slotsbyquestionid;

    /**
     * Sort function function for usort.  Is callable outside this class
     *
     * @param \mod_jazzquiz\jazzquiz_attempt $a
     * @param \mod_jazzquiz\jazzquiz_attempt $b
     * @return int
     */
    public static function sortby_timefinish($a, $b)
    {
        if ($a->timefinish == $b->timefinish) {
            return 0;
        }
        return ($a->timefinish < $b->timefinish) ? -1 : 1;
    }

    /**
     * Construct the class.  if a dbattempt object is passed in set it, otherwise initialize empty class
     *
     * @param questionmanager $questionmanager
     * @param \stdClass
     * @param \context_module $context
     */
    public function __construct($questionmanager, $dbattempt = null, $context = null)
    {
        $this->questionmanager = $questionmanager;
        $this->context = $context;

        if (empty($dbattempt)) {

            // Create new attempt
            $this->attempt = new \stdClass();

            // Create a new quba since we're creating a new attempt
            $this->quba = \question_engine::make_questions_usage_by_activity('mod_jazzquiz', $this->questionmanager->getRTQ()->getContext());
            $this->quba->set_preferred_behaviour('immediatefeedback');

            $attemptlayout = $this->questionmanager->add_questions_to_quba($this->quba);

            // Add the attempt layout to this instance
            $this->attempt->qubalayout = implode(',', $attemptlayout);

        } else {
            // Load it up in this class instance
            $this->attempt = $dbattempt;
            $this->quba = \question_engine::load_questions_usage_by_activity($this->attempt->questionengid);
        }
    }

    /**
     * Get the attempt stdClass object
     *
     * @return null|\stdClass
     */
    public function get_attempt()
    {
        return $this->attempt;
    }

    /**
     * returns a string representation of the "number" status that is actually stored
     *
     * @return string
     * @throws \Exception throws exception upon an undefined status
     */
    public function getStatus()
    {
        switch ($this->attempt->status) {
            case self::NOTSTARTED:
                return 'notstarted';
            case self::INPROGRESS:
                return 'inprogress';
            case self::ABANDONED:
                return 'abandoned';
            case self::FINISHED:
                return 'finished';
            default:
                throw new \Exception('undefined status for attempt');
                break;
        }
    }

    /**
     * Set the status of the attempt and then save it
     *
     * @param string $status
     *
     * @return bool
     */
    public function setStatus($status)
    {
        switch ($status) {
            case 'notstarted':
                $this->attempt->status = self::NOTSTARTED;
                break;
            case 'inprogress':
                $this->attempt->status = self::INPROGRESS;
                break;
            case 'abandoned':
                $this->attempt->status = self::ABANDONED;
                break;
            case 'finished':
                $this->attempt->status = self::FINISHED;
                break;
            default:
                return false;
        }

        // Save the attempt
        return $this->save();
    }

    /**
     * Returns the class instance of the quba
     *
     * @return \question_usage_by_activity
     */
    public function get_quba()
    {
        return $this->quba;
    }

    /**
     * Uses the quba object to render the slotid's question
     *
     * @param int $slot
     * @param bool $review Whether or not we're reviewing the attempt
     * @param string|\stdClass $review_options Can be string for overall actions like "edit" or an object of review options
     * @return string the HTML fragment for the question
     */
    public function render_question($slot, $review = false, $review_options = '')
    {
        $display_options = $this->get_display_options($review, $review_options);
        $question_number = $this->get_question_number();
        $this->add_question_number();
        return $this->quba->render_question($slot, $display_options, $question_number);
    }

    /**
     * @param int $total_tries The total tries
     *
     * @return int The number of tries left
     */
    public function check_tries_left($total_tries)
    {
        if (empty($this->attempt->responded_count)) {
            $this->attempt->responded_count = 0;
        }
        $left = $total_tries - $this->attempt->responded_count;
        return $left;
    }

    /**
     * sets up the display options for the question
     *
     * @return \question_display_options
     */
    protected function get_display_options($review = false, $reviewoptions = '')
    {
        $options = new \question_display_options();
        $options->flags = \question_display_options::HIDDEN;
        $options->context = $this->context;
        $options->marks = \question_display_options::HIDDEN;

        if ($review) {

            // Default display options for review
            $options->readonly = true;
            $options->hide_all_feedback();

            // Special case for "edit" reviewoptions value
            if ($reviewoptions === 'edit') {

                $options->correctness = \question_display_options::VISIBLE;
                $options->marks = \question_display_options::MARK_AND_MAX;
                $options->feedback = \question_display_options::VISIBLE;
                $options->numpartscorrect = \question_display_options::VISIBLE;
                $options->manualcomment = \question_display_options::EDITABLE;
                $options->generalfeedback = \question_display_options::VISIBLE;
                $options->rightanswer = \question_display_options::VISIBLE;
                $options->history = \question_display_options::VISIBLE;

            } else if ($reviewoptions instanceof \stdClass) {

                foreach (\mod_jazzquiz\jazzquiz::$reviewfields as $field => $not_used) {
                    if ($reviewoptions->$field == 1) {
                        if ($field == 'specificfeedback') {
                            $field = 'feedback';
                        }
                        if ($field == 'marks') {
                            $options->$field = \question_display_options::MARK_AND_MAX;
                        } else {
                            $options->$field = \question_display_options::VISIBLE;
                        }

                    }
                }
            }
        } else {

            // Default options for running quiz
            $options->rightanswer = \question_display_options::HIDDEN;
            $options->numpartscorrect = \question_display_options::HIDDEN;
            $options->manualcomment = \question_display_options::HIDDEN;
            $options->manualcommentlink = \question_display_options::HIDDEN;
        }

        return $options;
    }

    /**
     * returns an integer representing the question number
     *
     * @return int
     */
    public function get_question_number()
    {
        // TODO: Why is this returning a string? The annotation says it should return an integer...
        if (is_null($this->qnum)) {
            $this->qnum = 1;
            return (string)1;
        }
        return (string)$this->qnum;
    }

    /**
     * Adds 1 to the current qnum, effectively going to the next question
     *
     */
    protected function add_question_number()
    {
        $this->qnum = $this->qnum + 1;
    }

    /**
     * returns quba layout as an array as these are the "slots" or questionids
     * that the question engine is expecting
     *
     * @return array
     */
    public function getSlots()
    {
        return explode(',', $this->attempt->qubalayout);
    }

    /**
     * Gets the slot for the jazzquiz question
     *
     * @param \mod_jazzquiz\jazzquiz_question $q
     *
     * @return int
     */
    public function get_question_slot(\mod_jazzquiz\jazzquiz_question $question)
    {
        // Build if not available
        if (empty($this->slotsbyquestionid) || !is_array($this->slotsbyquestionid)) {

            // Build an array of slots keyed by the question_id they match to
            $slots_by_question_id = [];

            foreach ($this->getSlots() as $slot) {
                $slots_by_question_id[$this->quba->get_question($slot)->id] = $slot;
            }

            $this->slotsbyquestionid = $slots_by_question_id;
        }

        $question_id = $question->getQuestion()->id;

        if (!empty($this->slotsbyquestionid[$question_id])) {
            return $this->slotsbyquestionid[$question_id];
        }

        return false;
    }

    /**
     * Gets the jazzquiz question class object for the slot id
     *
     * @param int $asked_slot
     *
     * @return \mod_jazzquiz\jazzquiz_question | false
     */
    public function get_question_by_slot($asked_slot)
    {
        // Build if not available
        if (empty($this->slotsbyquestionid) || !is_array($this->slotsbyquestionid)) {

            // Build an array of slots keyed by the question id they match to
            $slots_by_question_id = [];

            foreach ($this->getSlots() as $slot) {
                $question_id = $this->quba->get_question($slot)->id;
                $slots_by_question_id[$question_id] = $slot;
            }

            $this->slotsbyquestionid = $slots_by_question_id;
        }

        $question_id = array_search($asked_slot, $this->slotsbyquestionid);

        if (empty($question_id)) {
            return false;
        }

        foreach ($this->get_questions() as $question) {

            /** @var \mod_jazzquiz\jazzquiz_question $question */
            if ($question->getQuestion()->id == $question_id) {
                return $question;
            }
        }

        return false;
    }

    /**
     * Gets the RTQ questions for this attempt
     *
     * @return array
     */
    public function get_questions()
    {
        return $this->questionmanager->get_questions();
    }

    /**
     *
     *
     * @param int $slot
     *
     * @return array (array of sequence check name, and then the value
     */
    public function get_sequence_check($slot)
    {
        $attempt = $this->quba->get_question_attempt($slot);
        return [
            $attempt->get_control_field_name('sequencecheck'),
            $attempt->get_sequence_check_count()
        ];
    }

    /**
     * Initialize the head contributions from the question engine
     *
     * @return string
     */
    public function get_html_head_contributions()
    {
        $result = '';

        // Get the slots ids from the quba layout
        $slots = explode(',', $this->attempt->qubalayout);

        // Next load the slot headhtml and initialize question engine js
        foreach ($slots as $slot) {
            $result .= $this->quba->render_question_head_html($slot);
        }
        $result .= \question_engine::initialise_js();

        return $result;
    }

    /**
     * saves the current attempt class
     *
     * @return bool
     */
    public function save()
    {
        global $DB;

        // Save the question usage by activity object
        \question_engine::save_questions_usage_by_activity($this->quba);

        // Add the quba id as the questionengid
        // This is here because for new usages there is no id until we save it
        $this->attempt->questionengid = $this->quba->get_id();
        $this->attempt->timemodified = time();

        if (isset($this->attempt->id)) {

            // Update existing record
            try {
                $DB->update_record('jazzquiz_attempts', $this->attempt);
            } catch (\Exception $e) {
                error_log($e->getMessage());

                return false; // return false on failure
            }

        } else {

            // Insert new record
            try {
                $newid = $DB->insert_record('jazzquiz_attempts', $this->attempt);
                $this->attempt->id = $newid;
            } catch (\Exception $e) {
                return false;
            }

        }

        return true;
    }

    /**
     * Saves a question attempt from the jazzquiz question
     *
     * @return bool
     */
    public function save_question()
    {
        global $DB;

        $timenow = time();
        $transaction = $DB->start_delegated_transaction();
        if ($this->attempt->userid < 0) {
            $this->process_anonymous_response($timenow);
        } else {
            $this->quba->process_all_actions($timenow);
        }
        $this->attempt->timemodified = time();
        $this->attempt->responded = 1;

        if (empty($this->attempt->responded_count)) {
            $this->attempt->responded_count = 0;
        }
        $this->attempt->responded_count = $this->attempt->responded_count + 1;

        $this->save();

        $transaction->allow_commit();

        return true;
    }

    protected function process_anonymous_response($timenow)
    {
        foreach ($this->get_slots_in_request() as $slot) {
            if (!$this->quba->validate_sequence_number($slot)) {
                continue;
            }
            $submitteddata = $this->quba->extract_responses($slot);
            //$this->quba->process_action($slot, $submitteddata, $timestamp);
            $qa = $this->quba->get_question_attempt($slot);
            $qa->process_action($submitteddata, $timenow, $this->attempt->userid);
            $this->quba->get_observer()->notify_attempt_modified($qa);
        }

        $this->quba->update_question_flags();
    }

    /**
     * COPY FROM QUBA IN ORDER TO RUN ANONYMOUS RESPONSES
     *
     *
     * Get the list of slot numbers that should be processed as part of processing
     * the current request.
     * @param array $postdata optional, only intended for testing. Use this data
     * instead of the data from $_POST.
     * @return array of slot numbers.
     */
    protected function get_slots_in_request($postdata = null)
    {
        // Note: we must not use "question_attempt::get_submitted_var()" because there is no attempt instance!!!
        if (is_null($postdata)) {
            $slots = optional_param('slots', null, PARAM_SEQUENCE);
        } else if (array_key_exists('slots', $postdata)) {
            $slots = clean_param($postdata['slots'], PARAM_SEQUENCE);
        } else {
            $slots = null;
        }
        if (is_null($slots)) {
            $slots = $this->quba->get_slots();
        } else if (!$slots) {
            $slots = array();
        } else {
            $slots = explode(',', $slots);
        }
        return $slots;
    }

    /**
     * Gets the feedback for the specified question slot
     *
     * If no slot is defined, we attempt to get that from the slots param passed
     * back from the form submission
     *
     * @param int $slot The slot for which we want to get feedback
     * @return string HTML fragment of the feedback
     */
    public function get_question_feedback($slot = -1)
    {
        global $PAGE;

        if ($slot === -1) {
            // attempt to get it from the slots param sent back from a question processing
            $slots = required_param('slots', PARAM_ALPHANUMEXT);

            $slots = explode(',', $slots);
            $slot = $slots[0]; // always just get the first thing from explode
        }

        $questiondef = $this->quba->get_question($slot);

        $questionrenderer = $questiondef->get_renderer($PAGE);

        // get default display options
        $displayoptions = $this->get_display_options();

        return $questionrenderer->feedback($this->quba->get_question_attempt($slot), $displayoptions);
    }

    /**
     * sets last question bool.  is used for help in controlling quiz
     *
     * @param bool $is whether or not it is
     */
    public function islastquestion($is = false)
    {
        $this->lastquestion = $is;
    }

    /**
     * Summarizes a response for us before the question attempt is finished
     *
     * This will get us the question's text and response without the info or other controls
     *
     * @param int $slot
     *
     */
    public function summarize_response($slot)
    {
        global $PAGE;

        $questionattempt = $this->quba->get_question_attempt($slot);
        $question = $this->quba->get_question($slot);

        $rtqQuestion = $this->get_question_by_slot($slot);

        // use the renderer to display just the question text area, but in read only mode
        // basically how the quiz module does it, but we're being much more specific in the output
        // we want.  This also is more in line with the question engine's rendering of specific questions

        // This will display the question text as well for each response, but for a v1 this is ok
        $qrenderer = $question->get_renderer($PAGE);
        $qoptions = $this->get_display_options(true); // get default review options, which is no feedback or anything

        $this->responsesummary = $qrenderer->formulation_and_controls($questionattempt, $qoptions);

        if ($rtqQuestion->getShowHistory()) {
            $this->responsesummary .= $this->question_attempt_history($questionattempt);
        }

        // Bad way of doing things
        // $response = $questionattempt->get_last_step()->get_qt_data();
        // $this->responsesummary = $question->summarise_response($response);
    }

    private function get_steps($slot)
    {
        global $DB;

        // Fetch all steps from the database
        $attempt = $this->quba->get_question_attempt($slot);
        $steps = $DB->get_records('question_attempt_steps', [
            'questionattemptid' => $attempt->get_database_id()
        ], 'sequencenumber desc');

        // Let's filter the steps
        $result = [];
        foreach ($steps as $step) {
            switch ($step->state) {
                case 'gaveup':
                    // The attempt is irrelevant, since it was never completed.
                    return [];
                case 'gradedright':
                    // We don't want the correct answer, which is saved in this step.
                    break;
                default:
                    // This is most likely an input step.
                    $result[] = $step;
                    break;
            }
        }

        // Return the filtered steps
        return $result;
    }

    private function get_step_data($step_id)
    {
        global $DB;
        return $DB->get_records('question_attempt_step_data', [
            'attemptstepid' => $step_id
        ], 'id desc');
    }

    private function get_response_data_multichoice($slot)
    {
        global $DB;

        // Find steps
        $steps = $this->get_steps($slot);
        if (!$steps) {
            return [];
        }

        // Go through all the steps to find the needed data
        $order = [];
        $chosen_answers = [];
        foreach ($steps as $step) {

            // Find step data
            $all_data = $this->get_step_data($step->id);
            if (!$all_data) {
                continue;
            }

            $choices_found = count($chosen_answers) > 0;

            // Keep in mind we're looping backwards.
            // Therefore, the last answer is prioritised.
            foreach ($all_data as $data) {

                if ($data->name === '_order') {

                    if (!$order) {
                        $order = explode(',', $data->value);
                    }

                } else if ($data->name === 'answer') {

                    if (!$choices_found) {
                        $chosen_answers[] = $data->value;
                    }

                } else if (substr($data->name, 0, 6) === 'choice') {

                    if (!$choices_found && $data->value == 1) {
                        $chosen_answers[] = substr($data->name, 6);
                    }

                }

            }

        }

        // Find the answer strings
        $responses = [];

        foreach ($chosen_answers as $chosen_answer) {

            if (isset($order[$chosen_answer])) {
                $option = $DB->get_record('question_answers', [
                    'id' => $order[$chosen_answer]
                ]);
                if ($option) {
                    $responses[] = $option->answer;
                }
            }

        }

        return $responses;

    }

    private function get_response_data_true_or_false($slot)
    {
        // Find steps
        $steps = $this->get_steps($slot);
        if (!$steps) {
            return '';
        }
        $step = reset($steps);

        // Find data
        $data = $this->get_step_data($step->id);
        if (!$data) {
            return '';
        }
        $data = array_shift($data);

        // Return response
        if ($data->value == 1) {
            return 'True';
        }
        return 'False';
    }

    private function get_response_data_stack($slot)
    {
        // Find steps
        $steps = $this->get_steps($slot);
        if (!$steps) {
            return '';
        }
        $step = reset($steps);

        // Find data
        $data = $this->get_step_data($step->id);
        if (!$data) {
            return '';
        }

        // STACK saves two rows for some reason, and it seems impossible to tell apart the answers in a general way.
        if (count($data) > 1) {
            $data_1 = array_shift($data);
            $data_2 = array_shift($data);
            $data = $data_1;
            if (substr($data_1->name, -4, 4) === '_val') {
                $data = $data_2;
            }
        } else {
            $data = array_shift($data);
        }

        return $data->value;
    }

    private function get_response_data_general($slot)
    {
        // Find step
        $steps = $this->get_steps($slot);
        if (!$steps) {
            return '';
        }
        $step = reset($steps);

        // Find data
        $data = $this->get_step_data($step->id);
        if (!$data) {
            return '';
        }
        $data = reset($data);

        // Return response
        return $data->value;
    }

    /**
     * Returns response data as an array
     *
     */
    public function get_response_data($slot)
    {
        $responses = [];

        $question_type = $this->quba->get_question_attempt($slot)->get_question()->get_type_name();

        switch ($question_type) {

            case 'multichoice':
                $responses = $this->get_response_data_multichoice($slot);
                break;

            case 'truefalse':
                $responses[] = $this->get_response_data_true_or_false($slot);
                break;

            case 'stack':
                $responses[] = $this->get_response_data_stack($slot);
                break;

            default:
                $responses[] = $this->get_response_data_general($slot);
                break;
        }

        return $responses;
    }

    /**
     * Returns whether current user has responded
     */
    public function has_responded($slot)
    {
        $steps = $this->get_steps($slot);
        if (!$steps) {
            return false;
        }
        foreach ($steps as $step) {
            if ($step->state === 'gradedright') {
                return true;
            }
            if ($step->state === 'gaveup') {
                return false;
            }
        }
        // There is no "gaveup" step, which means it might be under a different state.
        return true;
    }

    /**
     * Closes the attempt
     *
     * @param \mod_jazzquiz\jazzquiz $rtq
     *
     * @return bool Weather or not it was successful
     */
    public function close_attempt($rtq)
    {
        $this->quba->finish_all_questions(time());
        $this->attempt->status = self::FINISHED;
        $this->attempt->timefinish = time();
        $this->save();

        $params = array(
            'objectid' => $this->attempt->id,
            'context' => $rtq->getContext(),
            'relateduserid' => $this->attempt->userid
        );
        $event = \mod_jazzquiz\event\attempt_ended::create($params);
        $event->add_record_snapshot('jazzquiz_attempts', $this->attempt);
        $event->trigger();

        return true;
    }

    /**
     * This is a copy of the history function in the question renderer class
     * Since the access to that function is protected I cannot access it outside of the renderer class.
     *
     * There are a few changes to this function to facilitate simpler use
     *
     * @param \question_attempt $qa
     * @return string
     */
    public function question_attempt_history($qa)
    {
        $table = new \html_table();
        $table->head = [
            get_string('step', 'question'),
            get_string('time'),
            get_string('action', 'question'),
        ];

        foreach ($qa->get_full_step_iterator() as $i => $step) {
            $stepno = $i + 1;

            $rowclass = '';
            if ($stepno == $qa->get_num_steps()) {
                $rowclass = 'current';
            }

            $user = new \stdClass();
            $user->id = $step->get_user_id();
            $row = [
                $stepno,
                userdate($step->get_timecreated(), get_string('strftimedatetimeshort')),
                s($qa->summarise_action($step)),
            ];

            $table->rowclasses[] = $rowclass;
            $table->data[] = $row;
        }

        $history_title = \html_writer::tag('h4', get_string('responsehistory', 'question'), [
            'class' => 'responsehistoryheader'
        ]);

        $history_table = \html_writer::tag('div', \html_writer::table($table, true), [
            'class' => 'responsehistoryheader'
        ]);

        return $history_title . $history_table;
    }

    /**
     * Magic get method for getting attempt properties
     *
     * @param string $prop The property desired
     *
     * @return mixed
     * @throws \Exception Throws exception when no property is found
     */
    public function __get($prop)
    {
        if (property_exists($this->attempt, $prop)) {
            return $this->attempt->$prop;
        }
        // Otherwise throw a new exception
        throw new \Exception('undefined property(' . $prop . ') on jazzquiz attempt');
    }

    /**
     * magic setter method for this class
     *
     * @param string $prop
     * @param mixed $value
     *
     * @return jazzquiz_attempt
     */
    public function __set($prop, $value)
    {
        if (is_null($this->attempt)) {
            $this->attempt = new \stdClass();
        }
        $this->attempt->$prop = $value;
        return $this;
    }

}
