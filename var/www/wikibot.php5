<?php
  /*
   Wikibot class, version 0.24
   Last updated: 2010-04-07
   Last major edit: 2010-04-07
   Regularly compare the above with http://en.wikipedia.org/wiki/User:LivingBot/Wikibot.php5 and update as applicable
   Documentation is at http://en.wikipedia.org/wiki/User:LivingBot/Wikibot.
   */
  
  class Wikibot {
      //edits per minute
      public $epm;
      //details about the current edit
      public $editdetails;
      //wiki where bot works
      public $wiki;
      //max lag to server
      public $max_lag;
      //username
      public $username;
      public function __construct($username, $password, $wiki = 'en', $epm = 5, $lag = 5)//log in the wiki
      {
          if (!isset($username) || !isset($password)) {
              die("\r<br />\nError: configuration variables not set\r<br />\n");
          }
          $this->wiki = $this->wiki($wiki);
          //set edit per minute
          $this->epm = 60 / $epm;
          //set max lag to server
          $this->max_lag = $lag;
          $this->username = $username;
          $this->login($username, $password, $wiki);
      }
      private function login($username, $password, $wiki) {
          $response = $this->postAPI($wiki, 'api.php', 'action=login&lgname=' . urlencode($username) . '&lgpassword=' . urlencode($password));
          if ($response['login']['result'] == "Success") {
              //Unpatched server, all done. (See bug #23076, April 2010.)
          } elseif ($response['login']['result'] == "NeedToken") {
              //Patched server, going fine
              $token = $response['login']['token'];              
              $newresponse = $this->postAPI($wiki, 'api.php', 'action=login&lgname=' . urlencode($username) . '&lgpassword=' . urlencode($password) . '&lgtoken=' . $token);
              if ($newresponse['login']['result'] == "Success") {
                  //All done
              } else {
                  echo "Forced by server to wait. Automatically trying again.<br />\n";
                  print_r($response);
                  sleep(10);
                  $this->login($username, $password, $wiki);
              }
          } else {
              //Problem
              if (isset($response['login']['wait']) || (isset($response['error']['code']) && $response['error']['code'] == "maxlag")) {
                  echo "Forced by server to wait. Automatically trying again.<br />\n";
                  print_r($response);
                  sleep(10);
                  $this->login($username, $password, $wiki);
              } else {
                  die("Login failed: " . $response . "\r<br />\n");
              }
          }
      }
      public function get_page($page, $wiki = "")//get page's content
      {
          $response = $this->callAPI($wiki, 'api.php?action=query&prop=revisions&titles=' . urlencode($page) . '&rvprop=content');
          if (is_array($response)) {
              $array = $response['query']['pages'];
              $array = array_shift($array);
              $pageid = $array["pageid"];
              return $response['query']['pages'][$pageid]['revisions'][0]["*"];
          } else {
              echo "Unknown get_page error.<br />\n";
              return false;
          }
      }
      public function get_cats_of_page($page, $wiki = "")//get page's categories
      {
          $response = $this->callAPI($wiki, 'api.php?action=query&prop=categories&titles=' . urlencode($page));
          foreach ($response['query']['pages'] as $key => $value) {
              foreach ($value["categories"] as $key2 => $value2) {
                  $cats[] = $value2["title"];
              }
          }
          return $cats;
      }
      public function category($category, $limit = 500, $start = "", $ns = "all", $wiki = "")//get all the pages of a category, NOT RECURSIVE
      {
          sleep(10);
          $url = 'api.php?action=query&list=categorymembers&cmtitle=' . urlencode("Category:" . $category) . '&cmlimit=' . $limit;
          if ($ns != "all") {
              $url .= "&cmnamespace=" . $ns;
          }
          if ($start != "") {
              $url .= "&cmcontinue=" . urlencode($start);
          }
          $result = $this->callAPI($wiki, $url);
          $cm = $result["query"]["categorymembers"];
          $pages = array();
          for ($i = 0; $i < count($cm); $i++) {
              $pages[] = $cm[$i]["title"];
          }
          $next = $result["query-continue"]["categorymembers"]["cmcontinue"];
          if ($next != "") {
              array_push($pages, $next);
          }
          return $pages;
      }
      public function create_page($page, $text, $summary, $minor = false, $bot = false, $wiki = "")//create a new page
      {
          $response = $this->callAPI($wiki, "api.php?action=query&prop=info|revisions&intoken=edit&titles=" . urlencode($page));
          $this->editdetails = $response["query"]["pages"];
          debug($response);
          if (!isset($this->editdetails[-1])) {
              echo "Page $page already exists. Call edit_page instead.<br />\n";
              return false;
          }
          if ($this->put_page($page, $text, $summary, $minor, $bot, $wiki)) {
              return true;
          } else {
              echo "^^^ Error with put_page called from edit_page.<br />\n";
              return false;
          }
      }
      public function edit_page($page, $text, $summary, $minor = false, $bot = true, $wiki = "")//edit a page which already exists
      {
          $response = $this->callAPI($wiki, "api.php?action=query&prop=info|revisions&intoken=edit&titles=" . urlencode($page));
          $this->editdetails = $response["query"]["pages"];          
          if (isset($this->editdetails[-1])) {
              echo "Page $page does not already exist. Call create_page instead.<br />\n";
              return false;
          }
          if ($this->put_page($page, $text, $summary, $minor, $bot, $wiki)) {
              return true;
          } else {
              echo "^^^ Error with put_page called from edit_page.<br />\n";
              return false;
          }
      }
      private function put_page($name, $newtext, $summary, $minor = false, $bot = true, $wiki = "")//edit a page, regardless of whether it exists before or not
      {
          foreach ($this->editdetails as $key => $value) {
              $token = urlencode($value["edittoken"]);
              $sts = $value["starttimestamp"];
              if (isset($this->editdetails[-1])) {
                  $ts = $sts;
                  $extra = "&createonly=yes";
              } else {
                  $ts = $value["revisions"][0]["timestamp"];
                  $extra = "&nocreate=yes";
              }
          }
          $newtext = urlencode($newtext);
          $rawoldtext = $this->get_page($name, $wiki);
          $oldtext = urlencode($rawoldtext);
          $summary = urlencode($summary);
          
          if ($newtext == $oldtext) {
              //the new content is the same, nothing changes
              echo "The new content for " . $name . " is exactly the same as the current content, so the page wasn't edited.<br />\n";
              return false;
          }
          if ($newtext == "") {
              //the new content is void, nothing changes
              echo "Error: you were about to blank the page of " . $name . ".<br />\n";
              return false;
          }
          
          $post = "title=$name&action=edit&basetimestamp=$ts&starttimestamp=$sts&token=$token&summary=$summary$extra&text=$newtext";
          if ($bot) {
              if (!$this->allowBots($rawoldtext)) {
                  echo "Bot edits, or those specifically from this bot, have been blocked on this page.<br />\n";
                  return false;
              }
              $post .= "&bot=yes";
          }
          if ($minor) {
              $post .= "&minor=yes";
          } else {
              $post .= "&amp;notminor=yes";
          }
          $response = $this->postAPI($wiki, 'api.php', $post);
          if ($response["edit"]["result"] == "Success") {
              echo "Successfully edited " . $response["edit"]["title"] . ".<br />\n";
              sleep($epm);
              return true;
          } elseif (preg_match('/^Waiting for (.*) seconds lagged/', $result)) {
              echo "Error: max lag hit, not posted<br />\n";
              return false;
          } elseif (isset($response["error"])) {
              echo "Error - [" . $response["error"]["code"] . "] " . $response["error"]["info"] . "<br />\n";
              return false;
          } else {
              echo "Error - " . $response["edit"]["result"] . "&nbsp;<br />\n";
              return false;
          }
      }
      private function wiki($wiki)//manager wiki different from default wiki
      {
          if ($wiki == "") {
              //if not declarated put default wiki
              return $this->wiki;
          } elseif (strpos($wiki, "://") == false) {
              //if is a mediawiki project the user write only code language
              return "http://" . $wiki . ".wikipedia.org/w/";
          }
          //if it is a other wiki project
          return $wiki;
      }
      private function callAPI($wiki, $url, $format = "php") {
          $wiki = $this->wiki($wiki);
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
          curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
          curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');
          curl_setopt($ch, CURLOPT_URL, ($wiki . $url . "&maxlag=" . $this->max_lag . "&format=$format"));
          $response = curl_exec($ch);
          if (curl_errno($ch)) {
              return curl_error($ch);
          }
          curl_close($ch);
          return unserialize($response);
      }
      private function postAPI($wiki, $url, $postdata = "") {
          $wiki = $this->wiki($wiki);
          $ch = curl_init();
          $url = $wiki . $url;
          if ($postdata !== "") {
              $postdata .= "&";
          }
          $postdata .= "format=php&maxlag=" . $this->max_lag;
          curl_setopt($ch, CURLOPT_URL, $url);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
          curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');
          curl_setopt($ch, CURLOPT_USERAGENT, 'Wikibot 0.24');
          curl_setopt($ch, CURLOPT_POSTFIELDS, $postdata);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded;charset=UTF-8'));
          curl_setopt($ch, CURLOPT_HEADER, false);
          curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
          $response = curl_exec($ch);
          if (curl_errno($ch)) {
              return curl_error($ch);
          }
          curl_close($ch);
          $deserialized = unserialize($response);
          if (!$deserialized) echo "Error in unserializing ".$response;
          return unserialize($response);
      }
      private function allowBots($text) {
          if (preg_match('/\{\{(nobots|bots\|allow=none|bots\|deny=all|bots\|optout=all|bots\|deny=.*?' . preg_quote($this->username, '/') . '.*?)\}\}/iS', $text)) {
              return false;
          }
          return true;
      }
      public function makeValidPagename($page) {
      	$search = array("%20");
      	$replace = array("_");
      	return str_replace($search, $replace, $page);
      } 
  }
?>