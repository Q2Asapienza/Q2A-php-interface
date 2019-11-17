<?php
require_once(__DIR__.'/Requests/library/Requests.php');
require_once(__DIR__.'/simplehtmldom/simple_html_dom.php');
Requests::register_autoloader();

function last($array){
    return end($array);
}

class Keys{
    #KEYS FOR DICTIONARIES
    #types
    const TYPE            = 'types';
    const TYPE_QUESTIONS  = 'questions';
    const TYPE_ANSWERS    = 'answers';
    const TYPE_COMMENTS   = 'comments';

    #general data
    const ID              = 'id';
    const TEXT            = 'text';
    const PARENT          = 'parent';
    #edits
    const CREATED         = 'created';
    const LAST_EDIT       = 'last_edit';

    const EDIT            = 'edit';
    const WHAT            = Keys::EDIT;

    const TIMESTAMP       = 'timestamp';
    const WHEN            = Keys::TIMESTAMP;

    const USER            = 'user';
    const WHO             = Keys::USER;


    #question data
    const TITLE           = 'title';
    #answer data
    const BEST            = 'best';
    #like data
    const VOTED           = 'voted';
}
#urls
const URL_BASE        = "https://q2a.di.uniroma1.it/";
const URL_QUESTIONS   = "https://q2a.di.uniroma1.it/questions/";
const URL_LOGIN       = "https://q2a.di.uniroma1.it/login?to=";
const URL_USER        = "https://q2a.di.uniroma1.it/user";
const URL_USERS       = "https://q2a.di.uniroma1.it/users";
const URL_ACTIVITIES  = "https://q2a.di.uniroma1.it/activity/";

function Q2ADictToSerializable($q2a_dict){
    $q2a_dict = is_object($q2a_dict) ? (array)$q2a_dict : $q2a_dict;
    if (is_array($q2a_dict)) {
        $new = [];
        foreach ($q2a_dict as $key => $value) {
            #$key = str_replace(get_class($orig_obj), '', $key);
            if($key == Keys::PARENT){
                $new[$key] = Q2ADictToSerializable($value->{Keys::ID});
            }else{
                $new[$key] = Q2ADictToSerializable($value);
            }
        }
    } else {#not an array, just a value
        return $q2a_dict;
    }
    return $new;
}

class Q2A{
    #region HIDDEN METHODS
    function __construct($username = null, $password = null, $session_file = null, $category = "fondamenti-di-programmazione-19-20"){
        #shit
        $this->LIKE_HEADERS = [
            "sec-fetch-mode" => "cors",
            "sec-fetch-site" => "same-origin",
            "x-requested-with" => "XMLHttpRequest",
            "origin" => URL_BASE,
        ];

        #initializing class attributes
        $this->cache  = [];
        $this->category = $category;
        $this->username = $username;
        $this->password = $password;
        $this->session = null;

        #PREPARING SESSION
        #if there is a session file i load it
        if ($session_file != null){
            $this->sessionLoad($session_file);
        }
        #if i don't have a session loaded
        if ($this->session == null){
            #if i have a password i create it, otherwise i start a blank session
            if ($password != null){
                $this->sessionCreate();
            }else{
                $this->session = new Requests_Session(URL_BASE);
                $this->session->headers['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/78.0.3904.87 Safari/537.36';
            }
        }
    }

    #region INNER UTILITIES
    private function getHTMLFromURL($url, $cache=True){
        if (!$cache || !array_key_exists($url, $this->cache)){
            $this->cache[$url] = str_get_html($this->session->get($url)->body);
        }
        return $this->cache[$url];
    }

    private static function getCode($tree){
        return $tree->find('input[name="code"]',0)->value;
    }

    private static function userID($userUrl){
        return last(explode('/',$userUrl));
    }

    private static function firstEdit($tree){
        $edit_timestamp = $tree->find('.updated .value-title',0)->attr['title'];
        $edit_who = Q2A::userID($tree->find('.author a',0)->attr['href']);
        $edit_what = $tree->find('.qa-q-view-what, .qa-a-item-what, .qa-c-item-what',0)->innertext;
        return [
            Keys::USER      => $edit_who,
            Keys::TIMESTAMP => $edit_timestamp,
            Keys::EDIT      => $edit_what,
        ];
    }

    private static function lastEdit($tree){
        $edit_timestamp = last($tree->find('.updated .value-title'))->attr['title'];
        $edit_who = Q2A::userID(last($tree->find('.author a'))->attr['href']);
        $edit_what = last($tree->find('.qa-q-view-what, .qa-a-item-what, .qa-c-item-what'))->innertext;
        return [
            Keys::USER      => $edit_who,
            Keys::TIMESTAMP => $edit_timestamp,
            Keys::EDIT      => $edit_what,
        ];
    }

    private function questionsFromURL($url){
        $tree = $this->getHTMLFromURL($url);
        $questions = [];
        $pageQuestions = $tree->find('.qa-part-q-list .qa-q-list-item');
        foreach ($pageQuestions as $questionDiv){
            $id = substr($questionDiv->attr['id'],1);
            #navigating to question to get inner data
            $questionInnerDiv = $this->getHTMLFromURL(URL_BASE.$id)->find('div.question',0);

            #CREATING QUESTION
            $questions[$id] = new stdClass();

            #getting question data
            $questions[$id]->{Keys::TYPE}            = Keys::TYPE_QUESTIONS;
            $questions[$id]->{Keys::ID}            = $id;
            $questions[$id]->{Keys::TITLE}         = $questionDiv->find(".qa-q-item-title span",0)->innertext;
            $questions[$id]->{Keys::CREATED}       = Q2A::firstEdit($questionInnerDiv);
            $questions[$id]->{Keys::LAST_EDIT}     = Q2A::lastEdit($questionInnerDiv);
            $questions[$id]->{Keys::TEXT}          = $questionInnerDiv->find(".entry-content",0)->plaintext;
        }
        return $questions;
    }
    #endregion
    #endregion

    #region SESSION management
    function sessionCreate(){
        /**
         *Create a new requests session for comunicating with Q2A::
         *Creating a session is necessary for acting as a logged in user
         *WARNING: SAVING CURRENT SESSION TO A FILE IS INSECURE AND AKIN TO SAVING PASSWORD TO A TEXT FILE, BE CAREFUL!
         *
         *Params:
         *filename(str): the path of the file in wich the session will be written
         */

        # Get csrf token
        $this->getHTMLFromURL(URL_BASE,false);

        $loginPOSTBody = [
            "emailhandle"   => $this->username, 
            "password"      => $this->password,
            "remember"      => '1',
            "code"          => Q2A::getCode(tree),
            "dologin"       => "Login",
        ];

        # Perform login
        $this->session->post(URL_LOGIN,[],$loginPOSTBody);

        if(!$this->logged_in()){
            throw Exception("CAN'T LOGIN");
        }
    }

    #region sessionfile
    function sessionLoad($filename = "./data/q2a.ses"){
        /**
         *Save current session to a file.
         *WARNING: SAVING CURRENT SESSION TO A FILE IS INSECURE AND AKIN TO SAVING PASSWORD TO A TEXT FILE, BE CAREFUL!
         *
         *Params:
         *filename(str): the path of the file in wich the session will be written
         *
         *Return:
         *True if the session was loaded and still valid, False otherwise
         */
        try{
            // store $s somewhere where page2.php can find it.
            $this->session = unserialize(file_get_contents($filename));
        }catch (Exception $e) {
            return False;
        }

        return $this->logged_in();
    }

    function sessionSave($filename = "./data/q2a.ses"){
        /**
         *Save current session to a file.
         *WARNING: SAVING CURRENT SESSION TO A FILE IS INSECURE AND AKIN TO SAVING PASSWORD TO A TEXT FILE, BE CAREFUL!
         *
         *Params:
         *filename(str): the path of the file in wich the session will be written
         */
        file_put_contents($filename, serialize($this->session));
    }
    #endregion

    #endregion

    #region PROFILE INFO
    function logged_in(){
        return strpos($this->session->get(URL_USER)->url, $this->username) !== false;
    }

    function profileInfo(){
        return null;
    }
    #endregion

    #region Utility for user
    function getQuestions($category = null){
        /**
         *Get questions from all the pages.
         *
         *Params:
         *category(str): The id of the category
         *
         *Return:
         *A list containing the Questions found
         */
        $page = 1;
        $questions = [];
        while(True){
            $added = $this->getQuestionsFromPage($page,$category);
            #going to next page
            if(count($added) == 0){
                break;
            }
            $questions = array_merge($questions,$added);
            $page++;
        }
        return $questions;
    }
    
    function getQuestionsFromPage($page = 1, $category = null){
        /**
         *Get questions from a page.
         *
         *Params:
         *page(int): The number of the page
         *category(str): The id of the category
         *
         *Return:
         *A list containing the Questions found
         */
        if($category == null){
            $category = $this->category;
        }

        return $this->questionsFromURL(URL_QUESTIONS . $category . "?start=" . ((page-1)*20));
    }
    
    function getQuestionsFromActivities($category = null){
        /**
         *Get questions from activities.
         *
         *Params:
         *page(int): The number of the page
         *category(str): The id of the category
         *
         *Return:
         *A list containing the Questions found
         */
        if($category == null){
            $category = $this->category;
        }
        return $this->questionsFromURL(URL_ACTIVITIES . $category);
    }
    function getAnswersFromQuestions($questions, $update=True){
        $answers = [];
        foreach($questions as $id => $question){
            $answers = array_merge($answers,$this->getAnswersFromQuestion($questions[$id], $update));
        }
        return $answers;
    }
    function getAnswersFromQuestion($question, $update=True){
        $pageAnswers = $this->getHTMLFromURL(URL_BASE . $question->{Keys::ID})->find("div.answer");
        $answers = [];
        foreach($pageAnswers as $answerDiv){
            $id = substr($answerDiv->attr['id'],1);
           
            #this is necessary otherwise it will get the last edit from the last comment inside the answer
            $editDiv = $answerDiv->find(".qa-a-item-wrapper",0);

            #CREATING ANSWER
            $answers[$id] = new stdClass();

            #setting answer data
            $answers[$id]->{Keys::TYPE}         = Keys::TYPE_ANSWERS;
            $answers[$id]->{Keys::ID}           = $id;
            $answers[$id]->{Keys::CREATED}      = Q2A::firstEdit($editDiv);
            $answers[$id]->{Keys::LAST_EDIT}    = Q2A::lastEdit($editDiv);
            $answers[$id]->{Keys::TEXT}         = $answerDiv->find(".entry-content",0)->plaintext;
            $answers[$id]->{Keys::PARENT}       = $question;
            
            #setting parent best answer if this is the best answer
            if(count($answerDiv->find('.qa-a-selected')) != 0){
                $question ->{Keys::BEST} =  $id;
            }
        }
        if($update){
            $question->{Keys::TYPE_ANSWERS} = $answers;
        }
        return $answers;
    }

    function getCommentsFromAnswers($answers, $update=True){
        $comments = [];
        foreach ($answers as $id => $answer) {
            $comments = array_merge($comments,$this->getCommentsFromAnswer($answers[$id],$update));
        } 
        return $comments;
    }

    function getCommentsFromAnswer($answer, $update=True){
        $answerComments = $this->getHTMLFromURL(URL_BASE . $answer->{Keys::PARENT}->{Keys::ID})->find('#a'.$answer->{Keys::ID}.' .comment');
        $comments = [];
        foreach($answerComments as $commentDiv){
            $id = substr($commentDiv->attr['id'],1);
            #CREATING COMMENT
            $comments[$id] = new stdClass();

            #getting comment data
            $comments[$id]->{Keys::TYPE}          = Keys::TYPE_COMMENTS;
            $comments[$id]->{Keys::ID}            = $id;
            $comments[$id]->{Keys::CREATED}       = Q2A::firstEdit($commentDiv);
            $comments[$id]->{Keys::LAST_EDIT}     = Q2A::lastEdit($commentDiv);
            $comments[$id]->{Keys::TEXT}          = $commentDiv->find(".entry-content",0)->plaintext;
            $comments[$id]->{Keys::PARENT}        = $answer;
        }
        if($update){
            $answer->{Keys::TYPE_COMMENTS} = $comments;
        }
        return $comments;
    }

    function sendVote($like, $upVote = True){

        #navigate to Question otherwise site goes MAD (and meanwhile i update code)
        $question_id = $like['question']['id'];
        $like_id = $like['id'];

        $tree = $this->getHTMLFromURL(URL_BASE.question_id,FALSE);
        $form = null;
        foreach($tree->find('form') as $formDom){
            foreach($form->find('#voting_'.$like_id) as $likeBtn){
                $form = $formDom;
                break 2;
            }
        }
        if($form == null){
            return "LIKE NOT FOUND!!!";
        }

        $headers = $this->LIKE_HEADERS;#.copy()
        $headers['referer'] = $question_id;

        $postData = [
            "postid" => $like_id,
            "vote" => (int)$upVote,
            "code" => Q2A::getCode($form),
            "qa" => "ajax",
            "qa_operation" => "vote",
            "qa_root" => "./",
            "qa_request" => $question_id,
        ];


        $result = explode('\n',$this->session->post(URL_BASE, $headers, $postData)->body);
        if ($result[1] == '1'){
            return True;
        }
        
        return $result[2];
    }
    #endregion

    function getLikesFromQuestions($questions){
        $likes = [];
        foreach ($questions as $id => $question) {
            $likes = array_merge($likes,$this->getLikesFromQuestion($questions[$id]));
        } 
        return $likes;
    }

    function getLikesFromQuestion($question){
        $likes = [];
        $tree = $this->getHTMLFromURL(URL_BASE.$question->{Keys::ID});
        foreach($tree->find(".qa-voting") as $like){
            $name = $like->find(".qa-vote-one-button",0)->attrib['name'];
            $voted = ($name == null)? $name : (explode('_',$name)[2] == '0');
            $likes[] = [
                Keys::ID    => explode('_',$like['id'])[1],
                Keys::VOTED => $voted
            ];
        }
        return $likes;
    }

}
?>