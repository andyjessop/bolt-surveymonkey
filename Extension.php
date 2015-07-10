<?php

namespace Bolt\Extension\AndyJessop\SurveyMonkey;

use League\Flysystem\Filesystem;
use League\Flysystem\Adapter\Local as Adapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Bolt\Application;
use Bolt\BaseExtension;

include_once(dirname(__FILE__) .'/SurveyMonkey.class.php');

class Extension extends BaseExtension
{
   
   /*********************
    * URL ENDPOINTS
    *
    * 1.    GET api/v1/survey/get_surveys
    *       Returns a list of surveys from cache.
    *       Updates cache from remote API if cache older than one hour
    * 
    * 
    * 2.    GET api/v1/survey/get_survey?survey_id=839457398
    *       Returns formatted survey results
    **********************/
   
   /*********************
    * PRIVATE METHODS
    *
    * Methods relating to survey lists:
    * 1. get_cached_surveys()
    * 2. get_remote_surveys()
    * 3. get_surveys_last_updated_time()
    * 4. update_cached_surveys()
    * 5. save_surveys_to_cache($surveys)
    *
    * Methods relating to individual survey:
    * 6. get_cached_survey($id)
    * 7. get_remote_survey($id)
    * 8. get_survey_last_updated_time($id)
    * 9. update_cached_survey($id)
    * 10. save_survey_to_cache($id)
    * 11. get_remote_survey_details($id, $sm)
    * 12. get_remote_survey_url($id, $sm)
    * 13. get_remote_survey_respondent_list($id, $sm)
    * 14. get_remote_survey_responses($id, $respondent_list, $sm)
    * 15. build_survey_object($details, $collector_url, $responses)
    * 16. build_survey_table($details, $responses)
    * 17. get_answer_count($question, $responses, $answer_index)
    *
    * Helper methods
    * 18. get_filesystem_adapter()
    * 92. get_survey_monkey_object()
    * 
    **********************/

    /*
        API constants
        *************
    */
    const API_PREFIX = 'api/v1/';
    const API_BASE_URL = 'https://api.surveymonkey.net/v2/surveys/';
    const API_KEY = '';
    const ACCESS_TOKEN = '';

    public function initialize() {

        /* URL Endpoints
        ****************/

        // GET /survey/get_survey
        $this->app->get(Extension::API_PREFIX . "survey/get_survey", array($this, 'get_survey'))
                  ->bind('get_survey');

        // GET /survey/get_survey_list
        $this->app->get(Extension::API_PREFIX . "survey/get_surveys", array($this, 'get_surveys'))
                  ->bind('get_surveys');

    }

    public function getName()
    {
        return "SurveyMonkey";
    }

    /**
     * Get a list of current surveys
     * Updates from Survey Monkey API if not updated in last hour
     * 
     * @return json     list of surveys
     */
    public function get_surveys()
    {
        // Find out if files have been updated in last hour
        $last_updated = $this->get_surveys_last_updated_time();

        $current_date_time = new \DateTime('now');
        $interval_in_seconds = $current_date_time->getTimestamp() - $last_updated->getTimestamp();

        if ($interval_in_seconds > 3600)
        {
            // Update cached files from SM
            $updated = $this->update_cached_surveys();
        }

        // Cached files are now up to date, so let's get them and return the list
        $list = $this->get_cached_list();

        $response = $this->app->json($list);
        return $response;
    }

    /**
     * Retrieve an individual survey
     * 
     * @param  Request $request     containing survey id
     * @return json
     */
    public function get_survey(Request $request)
    {
        // Get survey_id from request
        $id = $request->get('survey_id');

        // Get survey from cache if exists
        if (! $survey = $this->get_cached_survey($id) )
        {
            $survey = $this->update_cached_survey($id);
        }

        // Find out if file has been updated in last hour
        $last_updated = $this->get_survey_last_updated_time($survey);

        $current_date_time = new \DateTime('now');
        $interval_in_seconds = $current_date_time->getTimestamp() - $last_updated->getTimestamp();

        if ($interval_in_seconds > 3600)
        {
            // Update cached files from SM
            $survey = $this->update_cached_survey($id);
        }

        $response = $this->app->json($survey);
        return $response;
    }

    /**********************************
     * Methods relating to survey lists
     **********************************/
    
    /**
     * 1.  get_cached_surveys()
     *     Retrieves cached survey list from json file
     * 
     * @return array of surveys - use as e.g. $contents[0]->title
     */
    private function get_cached_surveys()
    {
        // Get cache filesystem adapter
        $adapter = $this->get_filesystem_adapter();

        // Get cached survey list
        $path = 'survey_list.json';
        $contents = $adapter->read($path);

        return json_decode($contents);
    }

    /**
     * 2.  get_remote_surveys()
     *     Hits the Survey Monkey API and returns a list of Staff Satisfaction surveys
     *     as filtered by starting with "Staff" or "staff"
     *     
     * @return array list of surveys with survey details
     */
    private function get_remote_surveys()
    {
        $sm = $this->get_survey_monkey_object();

        $params = array(
            "fields" => array(
                "title",
                "analysis_url",
                "date_created",
                "date_modified",
                "question_count",
                "num_responses"
            )
        );

        // Make API call
        $data = $sm->getSurveyList($params);

        // Get array of surveys
        $data = json_encode($data);
        $data = json_decode($data);
        $surveys = $data->data->surveys;

        // Add current timestamp to each survey
        foreach($surveys as $survey)
        {
            $survey->last_updated = date('Y-m-d H:i:s', time());
        }

        // Filter out surveys that don't start with "Staff" or "staff"
        for ($i=0; $i < count($surveys); $i++)
        {
            if ((substr($surveys[$i]->title, 0,5) != "Staff") && (substr($surveys[$i]->title, 0,5) != "staff"))
            {
                unset($surveys[$i]);
            }
        }

        return array_values($surveys);
    }

    /**
     * 3.  get_surveys_last_updated_time()
     *     Gets last time the cached list was updated
     *     
     * @return DateTime
     */
    private function get_surveys_last_updated_time()
    {
        $list = $this->get_cached_surveys();
        return new \DateTime($list[0]->last_updated);
    }

    /**
     * 4.  update_cached_surveys()
     *     Updates cached survey list from Survey Monkey API
     *     
     * @return boolean
     */
    public function update_cached_surveys()
    {
        $list = $this->get_remote_surveys();
        $saved = $this->save_surveys_to_cache($list);

        return true;
    }

    /**
     * 5.  save_surveys_to_cache
     *     Takes list of surveys and saves it to cached file
     *     
     * @param  array $list
     * @return boolean
     */
    private function save_surveys_to_cache($surveys)
    {
        // Get new filesystem adapter
        $adapter = $this->get_filesystem_adapter();

        // Convert to json for storage
        $file = json_encode($list);

        if(!$adapter->has('survey_list.json')){
            $adapter->write('survey_list.json', $file);
        } else {
            $adapter->update('survey_list.json', $file);
        }

        return true;
    }


    /***************************************
     * Methods relating to individual survey
     ***************************************/

    /**
     * 6.  get_cached_survey()
     *     Gets the latest survey from cache
     * 
     * @param  string   $id     Survey ID
     * @return array            Formatted survey
     */
    private function get_cached_survey($id)
    {
        // Get new filesystem adapter
        $adapter = $this->get_filesystem_adapter();
        $path = 'surveys/' . $id . '.json';       

        if (!$adapter->has($path)) {
            return false;
        }

        // Cached copy exists
        $contents = $adapter->read($path);
        return json_decode($contents);
    }

    /**
     * 7.  get_remote_survey($id)
     *     Gets an individual survey from Survey Monkey
     * 
     * @param  string   $id         ID of survey
     * @return stdClass $survey     Survey object
     */
    private function get_remote_survey($id)
    {
        $sm = $this->get_survey_monkey_object();

        // Requirements to get full survey response:
        // 1. Hit remote API for survey details
        // 2. Hit remote API for collector list (to get survey url)
        // 3. Hit remote API for respondent list
        // 4. Hit remote API for survey responses
        // 5. Build survey object from API responses

        $details            = $this->get_remote_survey_details($id, $sm);
        $collector_url      = $this->get_remote_survey_url($id, $sm);
        $respondent_list    = $this->get_remote_survey_respondent_list($id, $sm);
        $responses          = $this->get_remote_survey_responses($id, $respondent_list, $sm);

        return $this->build_survey_object($details, $collector_url, $responses);
    }

    /**
     * 8.  get_survey_last_update_time($survey)
     *     Gets last time an individual survey was updated
     * 
     * @param  $id survey id
     * @return DateTime
     */
    private function get_survey_last_updated_time($survey)
    {
        return new \DateTime($survey->details->last_updated->date);
    }


    /**
     * 9.  update_cached_survey($id)
     *     Gets survey from remote API and saves to cache    
     * 
     * @param  [type] $id [description]
     * @return [type]     [description]
     */
    private function update_cached_survey($id)
    {
        $survey = $this->get_remote_survey($id);
        $saved = $this->save_survey_to_cache($survey);
        return $survey;
    }

    /**
     * 10.  save_survey_to_cache
     *     Saves an individual survey to cache
     *     
     * @param  object $survey
     * @return true
     */
    private function save_survey_to_cache($survey)
    {
        // Get new filesystem adapter
        $adapter = $this->get_filesystem_adapter();

        // Convert to json for storage
        $file = json_encode($survey);
        $path = 'surveys/' . $survey->details->id . '.json';

        if(!$adapter->has($path)){
            $adapter->write($path, $file);
        } else {
            $adapter->update($path, $file);
        }

        return true;
    }

    /**
     * 11. get_remote_survey_details($id, $sm)
     *     Get unformated survey data from remote API
     * 
     * @param  string       $id     Survey ID
     * @param  SurveyMonkey $sm     Survey Monkey Object
     * @return stdClass             unformatted survey data
     */
    private function get_remote_survey_details($id, $sm)
    {
        $request = $sm->getSurveyDetails($id);
        return $request;
    }

    /**
     * 12. get_remote_survey_url($id, $sm)
     *     Gets collector url from remote API
     *     
     * @param  string       $id     Survey ID
     * @param  SurveyMonkey $sm     Survey Monkey Object
     * @return stdClass             Collectors
     */
    private function get_remote_survey_url($id, $sm)
    {
        $params = array('fields'=>array('url'));
        return $sm->getCollectorList($id, $params);
    }

    /**
     * 13.  get_remote_survey_respondent_list($id, $sm)
     *      Gets array of respondents needed to obtain actual answers
     *      (the Survey Monkey API is pretty convoluted...)
     *      
     * @param  string       $id     Survey ID
     * @param  SurveyMonkey $sm     Survey Monkey Object
     * @return array                ids of respondents
     */
    private function get_remote_survey_respondent_list($id, $sm)
    {
        $request = $sm->getRespondentList($id);
        $respondents = $request['data']['respondents'];

        // Strip unneccessary data
        if (count($respondents) > 0){
            $respondent_list = [];
            foreach($respondents as $respondent)
            {
                array_push($respondent_list, $respondent['respondent_id']);
            }
        }

        return $respondent_list;
    }

    /**
     * 14. get_remote_survey_responses($id, $respondent_list, $sm)
     *     Returns array of responses in the form:
     *
     *     $responses = [
     *         {
     *             respondent_id: 1,
     *             questions: [
     *                 {
     *                     question_id: 8566456,
     *                     answer_id:   3898563
     *                 },
     *                 {
     *                     question_id: 2845521,
     *                     answer_id:   9753452
     *                 },   
     *             ]
     *         },
     *         {
     *             respondent_id: 2,
     *             questions: [
     *                 question_id: 8566456,
     *                 answer_id:   2456734,    
     *             ]
     *         },
     *     ]
     * 
     * @param  string       $id                 Survey ID
     * @param  array        $respondent_list    array of respondent ids
     * @param  SurveyMonkey $sm                 Survey Monkey object
     * @return array                            responses
     */
    private function get_remote_survey_responses($id, $respondent_list, $sm)
    {
        $request = $sm->getResponses($id, $respondent_list);
        $data = $request['data'];

        // Clean up data
        $responses = [];
        if ( count($data) > 0)
        {
            foreach($data as $response)
            {
                $obj = new \stdClass();
                $obj->respondent_id = $response['respondent_id'];

                // Add in questions and answers
                $arr = [];
                foreach($response['questions'] as $question)
                {
                    $questionObject = new \stdClass();
                    $questionObject->question_id = $question['question_id'];
                    $questionObject->answer_id = $question['answers'][0]['row'];

                    array_push($arr, $questionObject);
                }
                $obj->questions = $arr;
                array_push($responses, $obj);
            }
        }
        return $responses;
    }

    /**
     * 15. build_survey_object($details, $collector_url, $responses)
     *     Pulls all the various parts together to get coherent survey object 
     *     
     * @param  stdClass     $details   
     * @param  stdClass     $collector_url 
     * @param  array        $responses     responses
     * @return stdClass                    Survey object
     */
    private function build_survey_object($details, $collector_url, $responses)
    {
        $survey = new \stdClass();
        $survey->details = new \stdClass();
        $survey->details->title = $details['data']['title']['text'];
        $survey->details->id = $details['data']['survey_id'];
        $survey->details->num_responses = $details['data']['num_responses'];
        $survey->details->url = $collector_url['data']['collectors'][0]['url'];
        $survey->details->last_updated = new \DateTime();

        $survey->data = $this->build_survey_table($details, $responses);

        return $survey;
    }

    /**
     * 16. build_survey_table($details, $responses)
     *     Builds table format for easy display with JavaScript
     *     
     * @param  stdClass     $details
     * @param  array        $responses
     * @return array
     */
    private function build_survey_table($details, $responses)
    {
        $questions = $details['data']['pages'][0]['questions'];
        $data = [];

        foreach($questions as $question)
        {
            $obj = new \stdClass();
            $obj->title                     = $question['heading'];
            $obj->strongly_agree_count      = $this->get_answer_count($question, $responses, 0);
            $obj->agree_count               = $this->get_answer_count($question, $responses, 1);
            $obj->disagree_count            = $this->get_answer_count($question, $responses, 2);
            $obj->strongly_disagree_count   = $this->get_answer_count($question, $responses, 3);

            array_push($data, $obj);
        }

        return $data;
    }

    /**
     * 17. get_answer_count($question, $responses, $answer_index)
     *     Get's count for each answer for a given question.
     *     Again, Survey Monkey API is pretty convoluted so requires
     *     this sort of manipulation to get useful data.
     *     
     * @param  stdClass     $question
     * @param  array        $responses
     * @param  integer      $answer_index
     * @return integer
     */
    private function get_answer_count($question, $responses, $answer_index)
    {
        $answer_id = $question[$answer_index]['answer_id'];
        $count = 0;

        foreach($responses as $response)
        {
            if ($response->questions[$answer_index]['answer_id'] = $answer_id)
            {
                $count += 1;
            }
        }
        return $count;
    }
  

    /***************************************
     * Helper Methods
     ***************************************/

    /**
     * 18. get_filesystem_adapter()
     * Gets adapter for filesystem
     * @return Adapter 
     */
    private function get_filesystem_adapter()
    {
        return new Filesystem(new Adapter(__DIR__.'/cache'));
    }

    /**
     * 19. get_survey_monkey_object()
     * Gets Survey Monkey object for interacting with API
     * @return SurveyMonkey
     */
    private function get_survey_monkey_object()
    {
        return new SurveyMonkey(Extension::API_KEY , Extension::ACCESS_TOKEN);
    }

}