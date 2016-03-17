<?php



class listBasedBlacklist extends phplistPlugin{

    public $coderoot = PLUGIN_ROOTDIR . '/list-based-blacklist/';
    public static $plugin_folder = 'list-based-blacklist';

	public $settings = array(
		"listBasedBlacklist_newsletters" => array (
			'value' => "E-News\nEAA-Anchor\nOther",
			'description' => 'Add Newsletters (1 per each line)',
			'type' => "textarea",
			'allowempty' => 0,
			"max" => 1000,
			"min" => 0,
			'category'=> 'Newsletter',
			),
	);

    function __construct()
    {
    	if(!is_dir($coderoot))
    	{
    		$this->coderoot = dirname(__FILE__) . '/list-based-blacklist/';
    	}    	
     
        parent::__construct();
    }

    ////
    //	This is our Black list checker
    //	it checks to make sure this email hasn't been blacklisted specifically from the newsletter
    //  being sent
    ////
    public function canSend($messagedata, $subscriberdata)
    {
    	$selected_newsletter = isset($messagedata['newsletter']) ? $messagedata['newsletter'] : null;

    	if($selected_newsletter == 'other')
    	{
    		return true;
    	}

    	try{    		
	    	if(isset($subscriberdata['id']) && intval($subscriberdata['id']) > 0)
	    	{	    	
	    		$attributes = getUserAttributeValues('', $subscriberdata['id']);
	    		if(isset($attributes['list_blacklist']) && !empty($attributes['list_blacklist']))
	    		{	    			
	    			$list_blacklists = explode(',', $attributes['list_blacklist']);
	    			if(!is_array($list_blacklists))
	    			{
	    				throw new Exception("Can't access user attribute 'list_blacklist', let phplist decide to send natively");
	    			}
	    				    			
	    			if(in_array($selected_newsletter, $list_blacklists))
	    			{
	    				return false;	    				
	    			}
	    		}
	    	}
	    	else{
	    		throw new Exception("Can't access user attributes, let phplist decide to send natively");
	    	}
	    }
	    catch(Exception $e)
	    {	    	
	    }

	    return true;
    }

    ////
    //  Check to make sure the message has a NEWSLETTER selected (E-News, EAA, other)
    ////
    public function allowMessageToBeQueued($messagedata)
    {    	
    	$newsletter_values = self::getNewsletterValues();
    	
    	if(!isset($messagedata['newsletter']) || !in_array($messagedata['newsletter'], $newsletter_values))
    	{
    		return "You must select a valid newsletter in step 1!";
    	}

    	return '';
    }

    ////
    //  Add a new tab that selects the message type
    ////
    public function sendMessageTab($messageid = 0, $messagedata = array())
	{

		$selected_newsletter = isset($messagedata['newsletter']) ? $messagedata['newsletter'] : null;

		$newsletters = self::getNewsletters();
		$newsletter_values = self::getNewsletterValues();

		$admin_address = getConfig('admin_address');

		$newsletter_selection_html = '

		<div class="field">

			<label for="subject">
				Which Newsletter Are You Sending?
			</label>
			<p style="color: #666"> It is important to choose the proper newsletter this campaign belongs to.  If not, those who have unsubscribed to this newsletter may receive it.  If you have any questions, please contact the Admin at <a href="mail:' . $admin_address . '">' . $admin_address . '</a>
			</p>
			<select name="newsletter" id="newsletterinput">
				<option value=""> -- Select One -- </option>
		';

		foreach($newsletters as $key => $newsletter)
		{
			$newsletter_value = $newsletter_values[$key];		
			$selected = $newsletter_value == $selected_newsletter ? 'selected' : '';			
			$newsletter_selection_html .= '<option value="' . $newsletter_value . '"' . $selected . '> ' . $newsletter . ' </option> ';
		}

		$newsletter_selection_html .= "
			</select>

		</div>

		";

		return $newsletter_selection_html;
	}

  	public function sendMessageTabTitle($messageid = 0)
    {
    	return 'Newsletter';
    }

    public function sendMessageTabInsertBefore()
    {
        return 'Format';
    }	

    ////
    //	APPEND UNSUBSCRIBE LINK TO TEXT MESSAGE
    ////
	public function parseOutgoingTextMessage($messageid, $content, $destination, $userdata = null)
	{
		$unsubscribe_url = self::getUnsubscribeUrl($messageid, $userdata);
		$preferences_url = self::getPreferencesUrl($userdata);

		$content .= <<<EOD
		The College of Engineering at The University of Utah.  For Privacy Statement please visit http://www.utah.edu/privacy/.  To unsubscribe from this newsletter please visit $unsubscribe_url.  To update your newsletter preferences please visit $preferences_url
EOD;

	  	return $content;
	}

	////
	//	APPEND UNSUBSCRIBE LINK TO HTML MESSAGE
	////
	public function parseOutgoingHTMLMessage($messageid, $content, $destination, $userdata = null)
	{

		$unsubscribe_url = self::getUnsubscribeUrl($messageid, $userdata);
		$preferences_url = self::getPreferencesUrl($userdata);

		$content .= <<<EOD
<div>
	<p style="text-align: center; color: #444444; font-family: 'Helvetica', 'Helvetica Neue', 'Arial', sans-serif; font-weight: normal; line-height: 19px; font-size: 14px; margin: 0 0 10px; padding: 0;" align="center">
	    <a href="http://www.coe.utah.edu" style="color: #cc0000; text-decoration: none;">The College of Engineering</a> at  
	    <a href="http://www.utah.edu" style="color: #cc0000; text-decoration: none;">The University of Utah</a>: 
	    <a href="http://www.utah.edu/privacy/" style="color: #cc0000; text-decoration: none;">Privacy</a> | 
	    <a href="$preferences_url" style="color: #cc0000; text-decoration: none;">Update Preferences</a> |
	    <a href="$unsubscribe_url" style="color: #cc0000; text-decoration: none;">Unsubscribe</a>	    
	</p>
</div>
EOD;
	  	return $content;
	}	

	////
	//	Unsubscribe the user
	////
	public function unsubscribePage($email)
	{		
		global $tables;
		global $usertable_prefix;
		//set this to nothing since the core lib seems to be confused.
		$usertable_prefix = '';
	
		try{
			//get unsubscribe page from WP so that it is updated with headers and what not.
			//try to get from cache if possible
			$unsubscribe_page = self::getFromCache('unsubscribe-page');
			$admin_address = getConfig('admin_address');
			$userdata = Sql_Fetch_Array_Query(sprintf('select email,id,blacklisted from %s where uniqid = "%s"', $tables['user'], sql_escape($_GET['uid'])));
			
			if(empty($unsubscribe_page))
			{			
				$website = getConfig('website');
				$unsubscribe_page = file_get_contents("http://" . $website . "/unsubscribe-newsletter");			
				self::cacheContents('unsubscribe-page', $unsubscribe_page);
			}

			//get the users current blacklists			
			$attributes = getUserAttributeValues($email);
			$list_blacklist = explode(',', $attributes['list_blacklist']);	
			$blacklist_unsubscribe_url = self::getBlacklistUrl($_GET['uid']);


			if(isset($_GET['p']) && $_GET['p'] == 'blacklist')
			{
				return false;
			}

			if(isset($_GET['newsletter']))
			{

				$newsletters = self::getNewsletterKeysValues();
				$selected_newsletter = array_search($_GET['newsletter'], $newsletters);
				if(in_array($_GET['newsletter'], $list_blacklist))
				{
					$unsubscribe_page = preg_replace('/^~~blank_page_content~~/m', "<p>Thanks, <i>$email</i> has already been unsubscribed from the <strong>{$selected_newsletter} </strong> newsletter.  If you think this is an error please contact <a href=\"mailto:{$admin_address}\"> {$admin_address} </a>.</p>", $unsubscribe_page);	
					echo $unsubscribe_page;
					return true;
				}

				if(empty($selected_newsletter))
				{
					throw new Exception("Invalid Newsletter ({$_GET['newsletter']}) selected to unsubscribe $email from ");
				}

				$unsubscribe_page = preg_replace('/^~~blank_page_content~~/m', "<p>Sorry to see you go.  We have unsubscribed <i>$email</i> from the <strong>{$selected_newsletter} </strong> newsletter.  If you encounter any issues with this process please contact <a href=\"mailto:{$admin_address}\"> {$admin_address} </a>.</p><p> To unsubscribe from all communications from the College of Engineering please <a href=\"{$blacklist_unsubscribe_url}\"> click here </a></p>", $unsubscribe_page);		
			}

			else{
				throw new Exception("No newsletter selected for unsubscribing $email");
			}
				
			if(empty($list_blacklist[0]))
			{
				$list_blacklist[0] = $_GET['newsletter'];
			}
			else
			{
				$list_blacklist[] = $_GET['newsletter'];
			}
		
			$attr_id = getAttributeIDbyName('list_blacklist');
			$attr_data = implode(',', $list_blacklist);
			saveUserAttribute($userdata['id'],$attr_id,$attr_data);
		}

		catch(Exception $e)
		{
			error_log("Email Unsubscribe: " . $e->getMessage());			
			$unsubscribe_page = preg_replace('/^~~blank_page_content~~/m', "<p>Oops, there was an error unsubscribing you.  Please contact the administration at <a href=\"mailto:{$admin_address}\"> {$admin_address} </a>.</p>", $unsubscribe_page);	

			str_replace('~~blank_page_content~~', "Oops, there was an error unsubscribing you.  Please contact the administration at $admin_address", $unsubscribe_page);	
		}

		echo $unsubscribe_page;
	  	return true;
	}



    /** HELPER FUNCTIONS **/
    public static function getUnsubscribeUrl($messageid = null, $userdata)
    {
		$unsubscribe_url = getConfig('unsubscribeurl');

		if(empty($messageid) || empty($userdata['uniqid']))
		{
			return $unsubscribe_url;
		}

		$messagedata = loadMessageData($messageid);
		$selected_newsletter = is_array($messagedata) && isset($messagedata['newsletter']) ? $messagedata['newsletter'] : null;

		if(!empty($selected_newsletter) && $selected_newsletter != 'other')
		{
			$unsubscribe_url .= preg_match('/\?/', $unsubscribe_url) ? "&newsletter=$selected_newsletter" : "?newsletter=$selected_newsletter";
			$unsubscribe_url .= "&uid={$userdata['uniqid']}";
		}		

		return $unsubscribe_url;
    }

    public static function getPreferencesUrl($userdata)
    {
		$preferences_url = getConfig('preferencesurl');

		if(empty($userdata['uniqid']))
		{
			return $preferences_url;
		}
	
		$preferences_url .= preg_match('/\?/', $preferences_url) ? "&uid={$userdata['uniqid']}" : "?uid={$userdata['uniqid']}";
	

		return $preferences_url;
    }

    public static function getBlacklistUrl($uid)
    {
		$blacklist_url = getConfig('blacklisturl');

		if(empty($uid))
		{
			return $blacklist_url;
		}
	
		$blacklist_url .= preg_match('/\?/', $blacklist_url) ? "&uid={$uid}" : "?uid={$uid}";
	

		return $blacklist_url;
    }
    public static function getNewsletters()
    {
    	$newsletters = getConfig('listBasedBlacklist_newsletters');
		$newsletters = preg_split('/\R/', $newsletters);
		return $newsletters;
    }

    public static function getNewsletterValues()
    {
    	$newsletters = self::getNewsletters();
    	foreach($newsletters as &$newsletter)
    	{
    		$newsletter = strtolower(str_replace(' ', '', $newsletter));	
    	}

    	unset($newsletter);
    	return $newsletters;
    }

    public static function getNewsletterKeysValues()
    {
    	$newsletters = self::getNewsletters();
    	$keys_values = array();
    	foreach($newsletters as $newsletter)
    	{
    		$keys_values[$newsletter] = strtolower(str_replace(' ', '', $newsletter));	
    	}    	
    	return $keys_values;
    }

    public static function getFromCache($key, $ttl = 60*60*24*14)
    {    	
    	$cache_dir = dirname(__FILE__) . '/' . self::$plugin_folder . '/cache/';
    	if(!is_dir($cache_dir))
    	{
    		mkdir($cache_dir, 775, true);
    	}

    	$cached_file_path = "{$cache_dir}{$key}.cachefile";    	    	    	
    	if(file_exists($cached_file_path) && filemtime($cached_file_path) > time() - $ttl)
    	{    		
    		return file_get_contents($cached_file_path);
    	}
    	return null;
    }

    public static function cacheContents($key, $contents)
    {
    	$cache_dir = dirname(__FILE__) . '/' . self::$plugin_folder . '/cache/';
    	if(!is_dir($cache_dir))
    	{    		
    		mkdir($cache_dir, 775, true);
    	}
    	$cached_file_path = "{$cache_dir}{$key}.cachefile";    	
    	file_put_contents($cached_file_path, $contents);    	
    }


}