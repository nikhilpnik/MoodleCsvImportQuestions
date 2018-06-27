<?php
defined('MOODLE_INTERNAL') || die();

class qformat_csv extends qformat_default {

    public function provide_import() {
        return true;
    }

    public function mime_type() {
        return 'text/csv';
    }

    public function readquestions($lines) {
	  $questions = array();
	  $i =1;
	   foreach($lines as $line){
		$questionText = $line[0];
 		$option1 = $line[1];
		$option2 = $line[2];
		$option3 = $line[3];
		$option4 = $line[4];
		$correct_answer = $line[5];
		$solution = $line[6];
		$positive_remark = $line[7];
		$negative_remark = $line[8];
		$answer_array = array($option1,$option2,$option3,$option4);	
		if(in_array($correct_answer,$answer_array) and !empty($answer_array[0])){
			$questionNumber = "Q".$i;
			$question = $this->defaultquestion();
			$question->qtype = 'multichoice';
			$question->name  = $questionNumber;				
			$question->questiontext = nl2br(trim($questionText));
			$question->questiontextformat = FORMAT_HTML;
			$question->generalfeedback = nl2br(trim($solution));
                    	$question->generalfeedbackformat = FORMAT_HTML;
                    	$question->single = 1;
			$question->answernumbering = "abc";
			$question->shuffleanswers = true;
			$question->answer[0] = $this->text_field(nl2br(trim($option1)));
			$question->answer[1] = $this->text_field(nl2br(trim($option2)));
			$question->answer[2] = $this->text_field(nl2br(trim($option3)));	
			$question->answer[3] = $this->text_field(nl2br(trim($option4)));
			$question->fraction[0] = ($option1==$correct_answer)?$positive_remark:$negative_remark;
			$question->fraction[1] = ($option2==$correct_answer)?$positive_remark:$negative_remark;
			$question->fraction[2] = ($option3==$correct_answer)?$positive_remark:$negative_remark;
			$question->fraction[3] = ($option4==$correct_answer)?$positive_remark:$negative_remark;
			$question->feedback[0] = $this->text_field('');
			$question->feedback[1] = $this->text_field('');
			$question->feedback[2] = $this->text_field('');
			$question->feedback[3] = $this->text_field('');
			$questions[$i-1] = $question;
			$i++;		
		}
	   }
	return $questions;
    }

    public function importprocess($category) {
        global $USER, $CFG, $DB, $OUTPUT;

        // Raise time and memory, as importing can be quite intensive.
        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        // STAGE 1: Parse the file
        echo $OUTPUT->notification(get_string('parsingquestions', 'question'), 'notifysuccess');

        if (! $lines = $this->readdata_csv($this->filename)) {
            echo $OUTPUT->notification(get_string('cannotread', 'question'));
            return false;
        }

        if (!$questions = $this->readquestions($lines)) {   // Extract all the questions
            echo $OUTPUT->notification(get_string('noquestionsinfile', 'question'));
            return false;
        }

	// check for errors before we continue
        if ($this->stoponerror and ($this->importerrors>0)) {
            echo $OUTPUT->notification(get_string('importparseerror', 'question'));
            return true;
        }

        // get list of valid answer grades
        $gradeoptionsfull = question_bank::fraction_options_full();

        // check answer grades are valid
        // (now need to do this here because of 'stop on error': MDL-10689)
        $gradeerrors = 0;
        $goodquestions = array();
        foreach ($questions as $question) {
            if (!empty($question->fraction) and (is_array($question->fraction))) {
                $fractions = $question->fraction;
                $invalidfractions = array();
                foreach ($fractions as $key => $fraction) {
                    $newfraction = match_grade_options($gradeoptionsfull, $fraction,
                            $this->matchgrades);
                    if ($newfraction === false) {
                        $invalidfractions[] = $fraction;
                    } else {
                        $fractions[$key] = $newfraction;
                    }
                }
                if ($invalidfractions) {
                    echo $OUTPUT->notification(get_string('invalidgrade', 'question',
                            implode(', ', $invalidfractions)));
                    ++$gradeerrors;
                    continue;
                } else {
                    $question->fraction = $fractions;
                }
            }
            $goodquestions[] = $question;
        }
        $questions = $goodquestions;

        // check for errors before we continue
        if ($this->stoponerror && $gradeerrors > 0) {
            return false;
        }

        // count number of questions processed
        $count = 0;

        foreach ($questions as $question) {   // Process and store each question
            $transaction = $DB->start_delegated_transaction();

            // reset the php timeout
            core_php_time_limit::raise();

            // check for category modifiers
            if ($question->qtype == 'category') {
                if ($this->catfromfile) {
                    // find/create category object
                    $catpath = $question->category;
                    $newcategory = $this->create_category_path($catpath);
                    if (!empty($newcategory)) {
                        $this->category = $newcategory;
                    }
                }
                $transaction->allow_commit();
                continue;
            }
            $question->context = $this->importcontext;

            $count++;

            echo "<hr /><p><b>{$count}</b>. ".$this->format_question_text($question)."</p>";

            $question->category = $this->category->id;
            $question->stamp = make_unique_id_code();  // Set the unique code (not to be changed)

            $question->createdby = $USER->id;
            $question->timecreated = time();
            $question->modifiedby = $USER->id;
            $question->timemodified = time();
            $fileoptions = array(
                    'subdirs' => true,
                    'maxfiles' => -1,
                    'maxbytes' => 0,
                );

            $question->id = $DB->insert_record('question', $question);

            if (isset($question->questiontextitemid)) {
                $question->questiontext = file_save_draft_area_files($question->questiontextitemid,
                        $this->importcontext->id, 'question', 'questiontext', $question->id,
                        $fileoptions, $question->questiontext);
            } else if (isset($question->questiontextfiles)) {
                foreach ($question->questiontextfiles as $file) {
                    question_bank::get_qtype($question->qtype)->import_file(
                            $this->importcontext, 'question', 'questiontext', $question->id, $file);
                }
            }
            if (isset($question->generalfeedbackitemid)) {
                $question->generalfeedback = file_save_draft_area_files($question->generalfeedbackitemid,
                        $this->importcontext->id, 'question', 'generalfeedback', $question->id,
                        $fileoptions, $question->generalfeedback);
            } else if (isset($question->generalfeedbackfiles)) {
                foreach ($question->generalfeedbackfiles as $file) {
                    question_bank::get_qtype($question->qtype)->import_file(
                            $this->importcontext, 'question', 'generalfeedback', $question->id, $file);
                }
            }
            $DB->update_record('question', $question);

            $this->questionids[] = $question->id;

            // Now to save all the answers and type-specific options

            $result = question_bank::get_qtype($question->qtype)->save_question_options($question);

            if (isset($question->tags)) {
                core_tag_tag::set_item_tags('core_question', 'question', $question->id, $question->context, $question->tags);
            }

            if (!empty($result->error)) {
                echo $OUTPUT->notification($result->error);
                // Can't use $transaction->rollback(); since it requires an exception,
                // and I don't want to rewrite this code to change the error handling now.
                $DB->force_transaction_rollback();
                return false;
            }

            $transaction->allow_commit();

            if (!empty($result->notice)) {
                echo $OUTPUT->notification($result->notice);
                return true;
            }

            // Give the question a unique version stamp determined by question_hash()
            $DB->set_field('question', 'version', question_hash($question),
                    array('id' => $question->id));
        }
        return true;
   }
   
   protected function readdata_csv($filename) {

        if (is_readable($filename)) {
            $file = fopen($filename,'r');
	    while(! feof($file))
	    {
		$filearray[] = fgetcsv($file);
	    }

            return $filearray;
        }
        return false;
    }
   
   protected function text_field($text) {
        return array(
            'text' => htmlspecialchars(trim($text), ENT_NOQUOTES),
            'format' => FORMAT_HTML,
            'files' => array(),
        );
    } 
   
}
