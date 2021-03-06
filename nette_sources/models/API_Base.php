<?php

class API_Base extends API implements iAPI
{
	public $format = "application/json";
	protected $user_id = null;
	protected $partner_id = null;
	protected $slow_connection = false;
	
	public function __construct()
	{
		// block repeated login failures
		if (StaticModel::checkLoginFailures() === false) {
			return array(
				'result' => false,
				'error' => 'ip_blocked'
			);
		}

		if ((isset($_GET['PASS']) && isset($_GET['USER'])) || (isset($_POST['PASS']) && isset($_POST['USER']))) {
			$user = NEnvironment::getUser();
			try {
				if (isset($_GET['USER'])) {
					$username = $_GET['USER'];
					$password = $_GET['PASS'];
				} else {
					$username = $_POST['USER'];
					$password = $_POST['PASS'];
				}
				$user->login($username, $password);
				
				if ($user->getIdentity()->firstLogin()) {
					$user->getIdentity()->registerFirstLogin();
					$user->getIdentity()->setLastActivity();
				} else {
					$user->getIdentity()->setLastActivity();
				}
				$this->isLoggedIn();
			}
			catch (Exception $e) {
				if (defined('LOG_REQUESTS') && LOG_REQUESTS) $this->writeLog('Failed login with username '.$username.' and password '.$password);
			}
		}
		if (defined('LOG_REQUESTS') && LOG_REQUESTS) $this->writeLog();
		$this->setFormat();
		$this->setSpeed();
	}


	/**
	 *	Adds entry into log file
	 *
	 */
	protected function writeLog($text = null)
	{
		if (!defined('LOG_DIR')) return;

		// If no text is given, output all parameters apart from username and password
		if (empty($text)) {
			$parameters = $_POST;
			if (isset($parameters['PASS'])) $parameters['PASS'] = '*******';
			// try to prevent flooding
			if (count($parameters) > 20) {
				$text = 'Too many parameters: ('.count($parameters).')';
			} else {

				foreach ($parameters as $key => $value) {
					if (strlen(json_encode($value))>500) {
						$parameter[$key] = null;
					}
				}

				$text = print_r($parameters, true);
			}
			
		}
		
		$ip = StaticModel::getIpAddress();
//		$text = preg_replace('/(\s)+/', '', $text);
		$data = date('r')."\n".$ip."\n".$_SERVER['REQUEST_URI']."\n".$text."\n\n";
		file_put_contents(LOG_DIR.'/API.log', $data, FILE_APPEND|LOCK_EX);
	}


	/**
	 *	Sets format for returned data
	 *
	 */
	protected function setFormat()
	{
		if (!empty($_GET['format'])) {
			$this->format = $_GET['format'];
		}
		if (!empty($_POST['format'])) {
			$this->format = $_POST['format'];
		}
		
	}


	/**
	 *	mobile app can report low connection speed so that smaller content size will be delivered
	 *	reads parameter speed = 0|1 (0: low speed; 1: high speed; default: high)
	 *
	 */
	protected function setSpeed()
	{
		if (isset($_GET['speed']) && $_GET['speed'] == 0) {
			$this->slow_connection = true;
		}
		if (isset($_POST['speed']) && $_POST['speed'] == 0) {
			$this->slow_connection = true;
		}
	}
	
	protected function checkTime($time)
	{
		$lowest_time  = strtotime("- 2 years"); //date('U',mktime(0,0,0,1,1,2000));
		$highest_time = strtotime("+ 1 day");
		
		if (!is_numeric($time)) {
			$time = strtotime($time);
		}
		if (!$time || $time < $lowest_time || $time > $highest_time) {
			return false;
		}
		return $time;
	}


	protected function isLoggedIn()
	{
		$user_o = NEnvironment::getUser()->getIdentity();
		if (empty($user_o)) {
			return false;
		}
		
		if (!NEnvironment::getUser()->getIdentity()->isActive()) {
			
			return false;
		}
		$this->userId = $user_o->getUserId();
		
		$request = array_merge($_GET, $_POST);

		return true;
	}


	/**
	 * Comment
	 *
	 * @url	POST	/Login
	 */
	public function getLogin()
	{
		if (!$this->isLoggedIn()) {
			$error   = "unknown_user";
			$user = NEnvironment::getUser()->getIdentity();
			if (!empty($user)) {
				if (!$user->isActive()) {
					$error = "not_active";
				}
			}
			return array(
				'result' => false,
				'error' => $error
			);
		}
		

		
		$storage = new NFileStorage(TEMP_DIR);
		$cache = new NCache($storage, 'API');
		$cache->clean();
		$cache_key = 'Login-'.$this->userId;
		if ($cache->offsetExists($cache_key)) {
			$data = $cache->offsetGet($cache_key);
		} else {
		
			$user = User::create($this->userId);
			$data = $user->getUserData();
		
			// don't need here
			$data['user_portrait'] = null;
		
			$data['user_language_iso_639_3'] = Language::getLanguageCode($data['user_language']);
	//		unset($data['user_language']);
			$settings = array(NCache::EXPIRE => time()+120);
			$cache->save($cache_key, $data, $settings);
		}
		
		return array(
			'result' => true,
			'user_id' => $this->userId,
			'data' => $data
		); //Allready checked by common authorize function in rest server
	}


	/**
	 * Comment
	 *
	 * @url	POST	/Data
	 */
	public function getData()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		if (!empty($_POST['type'])) {
			foreach ($_POST['type'] as $t) {
				$types[] = $t;
			}
		} else {
			$types = array(
				ListerControlMain::LISTER_TYPE_USER,
				ListerControlMain::LISTER_TYPE_GROUP,
				ListerControlMain::LISTER_TYPE_RESOURCE
			);
		}
		$filter = array();
		$allowed_filters = array('language_iso_639_3', 'language', 'type', 'limit', 'count', 'trash', 'opened', 'user_id', 'group_id', 'resource_id', 'tags', 'mapfilter', 'status', 'name', 'all_members_only');
		if (!empty($_POST['filter'])) {
			$filter = $_POST['filter'];
			
			foreach ($filter as $key => $value) {
				if (!in_array($key, $allowed_filters)) {
					unset($filter[$key]);
					$filter = array_values($filter);
				}
			}
		}


		if ($this->slow_connection) {
			$items_per_page = 10;
		} else {
			$items_per_page = 20;
		}
		// Translate language_iso_639_3 to language id
		if (!empty($filter['language_iso_639_3'])) {
			$filter['language'] = Language::getIdFromCode($filter['language_iso_639_3']);
			unset ($filter['language_iso_639_3']);
		}
		
		if ((isset($filter['type']) && $filter['type'] == 'all') || (!isset($filter['type']))) {
				$filter['type'] = array(
					2,
					3,
					4,
					5,
					6
				);
			}
		
		$is_message = false;
		
		$storage = new NFileStorage(TEMP_DIR);
		$cache = new NCache($storage, 'API');
		$cache->clean();
	
		$speed_key = $this->slow_connection ? 'slow' : 'fast';
		$user_o = NEnvironment::getUser()->getIdentity();
		if (!empty($user_o)) {
			$user_id = $user_o->getUserId();
		} else {
			$user_id = 0;
		}

		if (isset($filter['type'])) {
			if ((is_array($filter['type']) && count(array_intersect($filter['type'], array(1,8,9,10)))) || in_array($filter['type'], array(1,8,9,10))) {
				$is_message = true;
					$filter['order_by'] = 'ORDER BY `resource`.`resource_creation_date` ASC';
//				$filter['order_by'] = 'ORDER BY `resource`.`resource_creation_date` DESC';

				if (isset($filter['trash']) && $filter['trash'] != 0) {
					$filter['trash'] = '1';
				} else {
					if ($filter['type'] != 8) {
						$filter['trash'] = '0';
					}
				}
				
				if (isset($filter['opened']) && $filter['opened'] != 0) {
					$filter['opened'] = '0';
				}
				
				if (isset($filter['all_members_only'])) {
					unset($filter['opened']);
				}
			}
		}
		
		// limiting number of returned items
		
		$filter_retrieve_all = $filter;
		$filter['limit'] = 0;
		if (empty($filter['count'])) {
			$filter['count'] = $items_per_page;
		}
//		if (defined('LOG_REQUESTS') && LOG_REQUESTS) $this->writeLog('count: '.$filter['count']);
			
		$cache_key = $user_id.'-'.$speed_key.'-'.md5(json_encode($types).json_encode($filter));

		$more_available = false;
		
		if ($cache->offsetExists($cache_key)) {
			$data = $cache->offsetGet($cache_key);
		} else {
			
			if ($is_message) {
	
				// get number of items
				$count = Administration::getData($types, $filter_retrieve_all, true);
			

				$filter['limit'] = $count-$filter['count'];
				if ($filter['limit'] < 0) $filter['limit'] = 0;
				
				if ($filter['limit'] > 0) {
					$more_available = true;
				} else {
					$more_available = false;
				}

/*
				if ($count > $filter['limit'] + $filter['count']) {
					$more_available = true;
				} else {
					$more_available = false;
				}

*/	
			} else {

				// get number of items
				$count = Administration::getData($types, $filter_retrieve_all, true);

				if ($count > $filter['limit'] + $filter['count']) {
					$more_available = true;
				} else {
					$more_available = false;
				}
			}

			$data = Administration::getData($types, $filter, false, $this->slow_connection);		
			$logged_user = NEnvironment::getUser()->getIdentity();
			$logged_user_language = Language::getFlag($logged_user->getLanguage());
			_t_set($logged_user_language);
			
			// check permissions, remove items that user may not view
			// cannot use unset for invisible items (error in app)
			foreach ($data as $key => $data_row) {
				if ($data_row['type_name'] == "user") {
					if (Auth::isAuthorized(1,$data_row['id']) == Auth::UNAUTHORIZED) {
						$data[$key]['name'] = _t('hidden user');
						unset($data[$key]['avatar']);
						unset($data[$key]['description']);
						unset($data[$key]["registered_resources"]);
						unset($data[$key]["last_activity"]);
						unset($data[$key]["date"]);
						unset($data[$key]['now_online']);
						$data[$key]['hidden'] = '1';
					} else {
						$user_id = $data_row['id'];
						$user = User::create($user_id);
						if (!empty($user)) {
							$user_data = $user->getUserData();
							$data[$key]['user_position_x'] = $user_data['user_position_x'];
							$data[$key]['user_position_y'] = $user_data['user_position_y'];
							$data[$key]['user_logged_user'] = $user->friendsStatus($logged_user->getUserId());
							$data[$key]['logged_user_user'] = $logged_user->friendsStatus($user_id);
							$data[$key]['tags'] = $user_data['tags'];
							$format_date_time = _t("j.n.Y");
							$last_activity = User::getRelativeLastActivity($user_id, $format_date_time);
							$data[$key]['last_activity'] = $last_activity['last_seen'];
							if ($last_activity['online']) {
								$data[$key]['now_online'] = "1";
							} else {
								$data[$key]['now_online'] = "0";
							}
						}
						$data[$key]['hidden'] = '0';
					}
					unset($data[$key]['description']);
					unset($data[$key]["registered_resources"]);
					unset($data[$key]["last_activity"]);
					unset($data[$key]["date"]);
					unset($data[$key]['viewed']);
					unset($data[$key]['status']);
				}
				if ($data_row['type_name'] == "group") {
					if (Auth::isAuthorized(2,$data_row['id']) == Auth::UNAUTHORIZED) {
						$data[$key]['name'] = _t('hidden group');
						unset($data[$key]['avatar']);
						unset($data[$key]['description']);
						unset($data[$key]["registered_resources"]);
						unset($data[$key]["last_activity"]);
						unset($data[$key]["date"]);
						$data[$key]['hidden'] = '1';
					} else {
						$group_id = $data_row['id'];
						$group = Group::create($group_id);
						if (!empty($group)) {
							$group_data = $group->getGroupData();
							$data[$key]['group_position_x'] = $group_data['group_position_x'];
							$data[$key]['group_position_y'] = $group_data['group_position_y'];
							if ($group->userIsRegistered($logged_user->getUserId())) {
								$data[$key]['logged_user_member'] = 1;
							} else {
								$data[$key]['logged_user_member'] = 0;
							}
							$data[$key]['tags'] = $group_data['tags'];
						}
						$data[$key]['hidden'] = '0';
					}
					unset($data[$key]['description']);
					unset($data[$key]["registered_resources"]);
					unset($data[$key]["last_activity"]);
					unset($data[$key]["date"]);
					unset($data[$key]['viewed']);
					unset($data[$key]['links']);
					unset($data[$key]['access_level']);
					unset($data[$key]['status']);
				}
				if ($data_row['type_name'] == "resource") { 
					if (Auth::isAuthorized(3,$data_row['id']) == Auth::UNAUTHORIZED) {
						$data[$key]['name'] = _t('hidden resource');
						unset($data[$key]['description']);
						unset($data[$key]["registered_resources"]);
						unset($data[$key]["last_activity"]);
						unset($data[$key]["date"]);
						unset($data[$key]['resource_data']['text_information']);
						unset($data[$key]['resource_data']['organization_information']);
						unset($data[$key]['resource_data']['event_description']);
						unset($data[$key]['resource_data']['media_link']);
						unset($data[$key]['resource_data']['event_url']);
						unset($data[$key]['resource_data']['event_timestamp']);
						unset($data[$key]['resource_data']['event_alert']);
						unset($data[$key]['resource_data']['organization_url']);
						unset($data[$key]['resource_data']['text_information_url']);
						unset($data[$key]['resource_data']['other_url']);
						$data[$key]['resource_data']['message_text'] = 'not allowed';
						$data[$key]['hidden'] = '1';
					} else {
						$resource_id = $data_row['id'];
						$resource = Resource::create($resource_id);
						if (!empty($resource)) {
							$resource_data = $resource->getResourceData();
							$data[$key]['resource_position_x'] = $resource_data['resource_position_x'];
							$data[$key]['resource_position_y'] = $resource_data['resource_position_y'];
							if ($resource->userIsRegistered($logged_user->getUserId())) {
								$data[$key]['logged_user_member'] = 1;
							} else {
								$data[$key]['logged_user_member'] = 0;
							}
							$data[$key]['tags'] = $resource_data['tags'];
							$data[$key]['owner_portrait'] = null;
							if ($is_message) {
								switch ($data[$key]['type']) {
								case 1: // private message
									// add additional info
									$author_username = User::getUserLogin($resource_data['resource_author']);
									if ($resource_data['resource_author'] == $logged_user->getUserId()) {
										// add recipient name
										$all_recipient_data = $resource->getAllMembers(array('resource_id'=>$resource_id));
										$recipient_data = $all_recipient_data[0];
										$recipient_id = $recipient_data['member_id'];
										$recipient_name = User::getUserLogin($recipient_id);
									
									
									} else {
										// add own name
										$recipient_name = User::getUserLogin($logged_user->getUserId());
									}
									$date=(array)$resource_data['resource_creation_date'];
									date_default_timezone_set($date['timezone']);
									$date_formatted = strftime('%e.%m.%Y %H:%M:%S',strtotime($date['date']));
									$data[$key]['resource_data']['message_text'] = '<small><b>'.$author_username." → ".$recipient_name.", ".$date_formatted.":</b></small>".$data[$key]['resource_data']['message_text'];
								
									$owner = User::create($resource_data['resource_author']);
									if (!empty($owner)) {
										if ($this->slow_connection) {
											$image = $owner->getIcon();
										} else {
											$image = $owner->getLargeIcon();
										}
										$data[$key]['owner_portrait'] = ($image) ? $image : null;
									}
								break;
								case 8: // group chat message
									$author_username = User::getUserLogin($resource_data['resource_author']);
									$date=(array)$resource_data['resource_creation_date'];
									date_default_timezone_set($date['timezone']);
									$date_formatted = strftime('%e.%m.%Y %H:%M:%S',strtotime($date['date']));
									$data[$key]['resource_data']['message_text'] = '<small><b>'.$author_username.", ".$date_formatted.":</b></small>".$data[$key]['resource_data']['message_text'];
								
									$owner = User::create($resource_data['resource_author']);
									if (!empty($owner)) {
										if ($this->slow_connection) {
											$image = $owner->getIcon();
										} else {
											$image = $owner->getLargeIcon();
										}
										$data[$key]['owner_portrait'] = ($image) ? $image : null;
									}
								break;
								case 9: // system message
									$data[$key]['resource_data']['message_text'] = "<small><b>System message:</b></small></div>".$data[$key]['resource_data']['message_text'];
								break;
								case 10: // friendship request
									$data[$key]['resource_data']['message_text'] = "<small><b>Friendship request:</b></small></div>".$data[$key]['resource_data']['message_text'];
								break;
								}
							} else {
								// $data[$key]['resource_data']['message_text'] = "";
							}
							
							// mark all messages as read if they have been displayed on the phone
							$resource->setOpened($logged_user->getUserId());
							
						}
						$data[$key]['hidden'] = '0';
					}
					unset($data[$key]['description']);
					unset($data[$key]["registered_resources"]);
					unset($data[$key]["last_activity"]);
					unset($data[$key]['text_information']);
					unset($data[$key]['organization_information']);
					unset($data[$key]['event_description']);
					unset($data[$key]['media_link']);
					unset($data[$key]['event_url']);
					unset($data[$key]['event_timestamp']);
					unset($data[$key]['event_alert']);
					unset($data[$key]['organization_url']);
					unset($data[$key]['text_information_url']);
					unset($data[$key]['other_url']);

/* can we remove that without app crashing?
					unset($data[$key]['resource_data']['text_information']);
					unset($data[$key]['resource_data']['organization_information']);
					unset($data[$key]['resource_data']['event_description']);
					unset($data[$key]['resource_data']['media_link']);
					unset($data[$key]['resource_data']['event_url']);
					unset($data[$key]['resource_data']['event_timestamp']);
					unset($data[$key]['resource_data']['event_alert']);
					unset($data[$key]['resource_data']['organization_url']);
					unset($data[$key]['resource_data']['text_information_url']);
					unset($data[$key]['resource_data']['other_url']);
*/
// temporary solution: set all to null if we don't need information in the lists

					$data[$key]['resource_data']['text_information'] = null;
					$data[$key]['resource_data']['organization_information'] = null;
					$data[$key]['resource_data']['event_description'] = null;
					$data[$key]['resource_data']['media_link'] = null;
					$data[$key]['resource_data']['event_url'] = null;
					$data[$key]['resource_data']['event_timestamp'] = null;
					$data[$key]['resource_data']['event_alert'] = null;
					$data[$key]['resource_data']['organization_url'] = null;
					$data[$key]['resource_data']['text_information_url'] = null;
					$data[$key]['resource_data']['other_url'] = null;
// keep message text

					unset($data[$key]['viewed']);
					unset($data[$key]['links']);
					unset($data[$key]['access_level']);
					unset($data[$key]['status']);
				}
			}

			if ($more_available) {
				$last['id'] = 0;

/*
				if ($this->slow_connection) {
					$plus_icon = "/9j/4AAQSkZJRgABAQEASABIAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8IAEQgAFAAUAwERAAIRAQMRAf/EABgAAAIDAAAAAAAAAAAAAAAAAAYHBAUI/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEAMQAAABKStDsBCSLkfAhzYh/8QAGhAAAgMBAQAAAAAAAAAAAAAABAYCAwUHJP/aAAgBAQABBQL39E1s9LBMrV3WB2al58DMpQULAL4KRT0c6q4Z1SlAl6PEEpAG/8QAFBEBAAAAAAAAAAAAAAAAAAAAMP/aAAgBAwEBPwEf/8QAFBEBAAAAAAAAAAAAAAAAAAAAMP/aAAgBAgEBPwEf/8QAJxAAAgIBAgUEAwEAAAAAAAAAAgQBAwUAERIhIkFRBhNh4SQxcfD/2gAIAQEABj8CcAHLcfgE7Jp/HnY2C78/H1p6304+/jXk2CXkrLOkzHz8f7bRRlZFPIr2FReE9yjvp/05a81j3knCMpVs9uwx8/MfWsi45kcmnWs/YUQd3BXeEbdZ8urfvOsll0zmpW1o4r35cUedFlRK5TIrDuLCx8BT/dGnl8k7erXO/tRbtBbedVrr1jTTXHCID+o1/8QAHxAAAgICAwADAAAAAAAAAAAAAREAITFBYZGxgdHh/9oACAEBAAE/IbbVSm/0WwjR4a0XMvJQZNi+yxDxZrACLAcvsHU1IvamGVv32JZg2+MBGi+ggSxMsV0vn6DMZADkKBK/eVNmYFNgVfsGfOHQJ//aAAwDAQACAAMAAAAQkkkkH//EABQRAQAAAAAAAAAAAAAAAAAAADD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAADD/2gAIAQIBAT8QH//EABwQAQEAAwEAAwAAAAAAAAAAAAERACExQVFhof/aAAgBAQABPxBdr9HvNdAiQOipc2TU4QV6tCvuLbRvikmZYQE4Q8YrbYzFO2oVYeWlEwRUQi0AAKKcmMbZTEyiF2j8AdjjJUzyQrIB0kBrwwRvkWYSj57oWiO8hgISfAP1Xaqqq5//2Q==";
				} else {
					$plus_icon = "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";
				}
*/
				if ($is_message) {
					$last['type'] = 1;
					$last['resource_data']['message_text'] = _t('load older messages...');
					$last['name'] = _t('load older messages...');
					$last['owner_portrait'] = null;// $plus_icon;
					$last['author'] = 0;
				} else {
					$last['name'] = _t('load more...');
					$last["type"] = 0;
				}
					
				$last['description'] = null;
				$last["last_activity"] = null;
				$last["registered_resources"] = null;
				$last["date"] = null;
				$last["now_online"] = null;
				
				if (isset($_POST['type']) && is_array($_POST['type']) && in_array(1,$_POST['type'])) {
					$last["type_name"] = 'user';
				}
				if (isset($_POST['type']) && is_array($_POST['type']) && in_array(2,$_POST['type'])) {
					$last["type_name"] = 'group';
				}
				if (isset($_POST['type']) && is_array($_POST['type']) && in_array(3,$_POST['type'])) {
					$last["type_name"] = 'resource';
				}

//				$last['new_count'] = $filter['count'] + $items_per_page;


				if ($is_message) {
					array_unshift($data, $last);
//					array_push($data, $last);
				} else {
					array_push($data, $last);
				}
				
			}

			if ($is_message) {
				$settings = array(NCache::EXPIRE => time()+20);
			} elseif ($this->slow_connection) {
				$settings = array(NCache::EXPIRE => time()+300);
			} else {
				$settings = array(NCache::EXPIRE => time()+120);
			}
			$cache->save($cache_key, $data, $settings);

		}

		return $data;
	}


	/**
	 * Comment
	 *
	 * @url  POST   /UserData
	 */
	public function getUserData()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		if (!empty($_POST['user_id'])) {
			$user_id = $_POST['user_id'];
		}
		
		if (Auth::isAuthorized(1, $user_id) > Auth::UNAUTHORIZED) {
			$user = User::create($user_id);
			$data = $user->getUserData();
			if (empty($data)) {
				throw new RestException('402', null);
			}
			$logged_user = NEnvironment::getUser()->getIdentity();
			
			// hide some data of other users
			if ($logged_user->getUserId() != $user_id) {
				$data['user_send_notifications'] = null;
				$data['user_registration_confirmed'] = null;
				$data['user_creation_rights'] = null;
				$data['user_phone_imei'] = null;
			}
			
			$user                     = User::create($user_id);
			$friend_user_relationship = $user->friendsStatus($logged_user->getUserId());
			$user_friend_relationship = $logged_user->friendsStatus($user_id);
			
			$data['logged_user_user'] = $user_friend_relationship;
			$data['user_logged_user'] = $friend_user_relationship;
			
			$format_date_time = _t("j.n.Y");
			$last_activity = User::getRelativeLastActivity($user_id, $format_date_time);
			$data['last_activity'] = $last_activity['last_seen'];
			if ($last_activity['online']) {
				$data['now_online'] = "1";
			} else {
				$data['now_online'] = "0";
			}

			$data['user_language_iso_639_3'] = Language::getLanguageCode($data['user_language']);
			unset($data['user_language']);
			
			// Serve small image as portrait on slow connections
			if ($this->slow_connection) {
				$data['user_portrait'] = $user->getLargeIcon();
			}
			
			return $data;
			
		} else {
			return array(
				'result' => false,
				'error' => 'permission_denied'
			);
		}
		
	}


	/**
	 * Comment
	 *
	 * @url  POST   /GroupData
	 */
	public function getGroupData()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		if (!empty($_POST['group_id'])) {
			$group_id = $_POST['group_id'];
		}
//		if (Auth::isAuthorized(2, $group_id) == Auth::ADMINISTRATOR || Auth::isAuthorized(2, $group_id) != Auth::UNAUTHORIZED) {
		if (Auth::isAuthorized(2, $group_id) > Auth::UNAUTHORIZED) {
			$logged_user = NEnvironment::getUser()->getIdentity();
			
			$group = Group::create($group_id);
			$data  = $group->getGroupData();
			if (empty($data)) {
				throw new RestException('402', null);
			}
			
			if ($group->userIsRegistered($logged_user->getUserId())) {
				$data['logged_user_member'] = 1;
			} else {
				$data['logged_user_member'] = 0;
			}
			
			$data['group_language_iso_639_3'] = Language::getLanguageCode($data['group_language']);

			
			// Serve small image as portrait on slow connections
			if ($this->slow_connection) {
				$data['group_portrait'] = $group->getLargeIcon();
			}
			
			return $data;
			
		} else {
			return array(
				'result' => false,
				'error' => 'permission_denied'
			);
		}
		
	}
	
	/**
	 * Comment
	 *
	 * @url  POST   /ResourceData
	 */
	public function getResourceData()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		if (!empty($_POST['resource_id'])) {
			$resource_id = $_POST['resource_id'];
		}
//		if (Auth::isAuthorized(3, $_POST['resource_id']) == Auth::ADMINISTRATOR || Auth::isAuthorized(3, $_POST['resource_id']) != Auth::UNAUTHORIZED) {
		if (Auth::isAuthorized(3, $resource_id) > Auth::UNAUTHORIZED) {
			$logged_user = NEnvironment::getUser()->getIdentity();
			
			$resource = Resource::create($resource_id);
			$data     = $resource->getResourceData();
			if (empty($data)) {
				throw new RestException('402', null);
			}
			if ($resource->userIsRegistered($logged_user->getUserId())) {
				$data['logged_user_member'] = 1;
			} else {
				$data['logged_user_member'] = 0;
			}
			/* begin changed */
			if (isset($data['resource_type']) && $data['resource_type'] != 5) {
				$data['media_type'] = "";
			}
			
			if (isset($data['resource_type']) && $data['resource_type'] == 2) {
				// todo: make $data['event_timestamp'] more readable
			} else {
				$data['event_timestamp'] = null;
			}
			
			if (isset($data['media_type']) && $data['media_type'] == 'media_youtube') {
				$data['media_link'] = $data['media_link']; // Android YouTube library wants only ID
			}
			if (isset($data['media_type']) && $data['media_type'] == 'media_vimeo') {
				$data['media_link'] = 'http://vimeo.com/' . $data['media_link'];
			}
			if (isset($data['media_type']) && $data['media_type'] == 'media_soundcloud') {
				$data['media_link'] = 'http://w.soundcloud.com/player/?url=www.soundcloud.com/tracks/' . $data['media_link'];
			}
			if (isset($data['media_type']) && $data['media_type'] == 'media_bambuser') {
				$data['media_link'] = 'http://bambuser.com/v/' . $data['media_link'];
			}
			
			$data['resource_language_iso_639_3'] = Language::getLanguageCode($data['resource_language']);
						
			/* end changed */
			
			
			
			return $data;
		} else {
			return array(
				'result' => false,
				'error' => 'permission_denied'
			);
		}
	}
	
	/**
	 * Comment
	 *
	 * @url  POST   /Tags
	 */
	public function getTags()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		
		return array(
			'result' => true,
			'tags' => Tag::getTreeArray()
		);
	}
	
	/**
	 * Comment
	 *
	 * @url  POST   /Subscribe
	 */
	public function setSubscription()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		if (!empty($_POST['objectType']) && !empty($_POST['objectId']) && isset($_POST['objectAction'])) {
			
			$logged_user = NEnvironment::getUser()->getIdentity();
			
			if ($_POST['objectType'] == "user") {
				$user = User::create($_POST['objectId']);
				if ($_POST['objectAction'] == 1) {
					$logged_user->updateFriend($_POST['objectId'], array());
				} else if ($_POST['objectAction'] == 0) {
					$logged_user->removeFriend($_POST['objectId']);
				}
				$friend_user_relationship = $user->friendsStatus($logged_user->getUserId());
				$user_friend_relationship = $logged_user->friendsStatus($user->getUserId());
				$res                      = $user_friend_relationship . "" . $friend_user_relationship;
			} else if ($_POST['objectType'] == "group") {
				$group = Group::create($_POST['objectId']);
				if ($_POST['objectAction'] == 1) {
					$group->updateUser($logged_user->getUserId(), array());
				} else if ($_POST['objectAction'] == 0) {
					
					$group->removeUser($logged_user->getUserId());
				}
				
				if ($group->userIsRegistered($logged_user->getUserId())) {
					$res = "1";
				} else {
					$res = "0";
				}
				
			} else if ($_POST['objectType'] == "resource") {
				$resource = Resource::create($_POST['objectId']);
				if ($_POST['objectAction'] == 1) {
					$resource->updateUser($logged_user->getUserId(), array());
				} else if ($_POST['objectAction'] == 0) {
					$resource->removeUser($logged_user->getUserId());
				}
				
				if ($resource->userIsRegistered($logged_user->getUserId())) {
					$res = "1";
				} else {
					$res = "0";
				}
				
			}
		}
		
		return array(
			'result' => true,
			'result_str' => $res
		);
		
	}
	
	/**
	 * Comment
	 *
	 * @url POST	/Register
	 */
	public function postRegister()
	{
		if (StaticModel::checkLoginFailures() === false) {
			return array(
				'result' => false,
				'error' => 'ip_blocked'
			);
		}
		
		if (!empty($_POST['login']) && !empty($_POST['email']) && !empty($_POST['password'])) {
			if (User::loginExists($_POST['login'])) {
				return array(
					'result' => false,
					'error' => 'login_exists'
				);
			}
			
			if (User::emailExists($_POST['email'])) {
				return array(
					'result' => false,
					'error' => 'email_exists'
				);	
			}
			
			if (StaticModel::isSpamEmail($_POST['email'])) {
				return array(
					'result' => false,
					'error' => 'spam_email'
				);
			}
			
			if (!StaticModel::validEmail($_POST['email'])) {
				return array(
					'result' => false,
					'error' => 'invalid_email'
				);
			}
			
			
			$new_user = User::create();
			;
			
			$password = User::encodePassword($_POST['password']);
			$hash     = User::generateHash();
			
			if (!empty($_POST['language_iso_639_3'])) {
				$language = Language::getIdFromCode($_POST['language_iso_639_3']);
			} else {
				$language = dibi::fetchSingle("SELECT `language_id` FROM `language` WHERE `language_code` = %s", $_POST['language']);
				if (!$language) $language = 1;
			}
			
			
			$values = array(
				'user_login' => $_POST['login'],
				'user_email' => $_POST['email'],
				'user_password' => $password,
				'user_hash' => $hash,
				'user_language' => $language
			);
			
			$new_user->setUserData($values);
			$new_user->save();
			
			$new_user->setRegistrationDate();
			
			$link = $new_user->sendConfirmationEmail();
			
			return array(
				'result' => true
			);
		}
	}
	
	/**
	 * Comment
	 *
	 * @url POST	/Userexists
	 */
	public function postUserexists()
	{
		if (!empty($_POST['username'])) {
			if (User::loginExists($_POST['username'])) {
				return array(
					'result' => false,
					'error' => 'login_exists'
				);
			} else {
				return array(
					'result' => true
				);
			}
		}
	}
	
	/**
	 * Comment
	 *
	 * @url POST	/Hasmessage
	 */
	public function postHasmessage()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		
		if (!empty($this->userId)) {
			$user = User::create($this->userId);
			$user->setLastActivity();
		}

		return array(
			'result' => true,
			'message_count' => Resource::getUnreadMessages()
		);
	}
	
	
	/**
	 * Comment
	 *
	 * @url POST	/SendMessage
	 */
	public function postSendMessage()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		if (!empty($_POST['objectType']) && !empty($_POST['objectId']) && !empty($_POST['message'])) {
			$logged_user = NEnvironment::getUser()->getIdentity();
			
			$storage = new NFileStorage(TEMP_DIR);
			$cache = new NCache($storage);

			if ($_POST['objectType'] == 'user') {
				$resource                          = Resource::create();
				$data                              = array();
				$data['resource_author']           = $logged_user->getUserId();
				$data['resource_type']             = 1;
				$data['resource_visibility_level'] = 3;
				$data['resource_name']             = '<PM>';//$_POST['message'];
				/* begin changed */
				//      				$data['resource_data'] = json_encode(array('message_text'=>$_POST['message']));
				$data['resource_data']             = json_encode(array(
					'message_text' => '<p>' . nl2br($_POST['message']) . '</p>'
				));
				$check                             = $resource->check_doublette($data, $logged_user->getUserId(), 1);

				if ($check === true) {
					return array(
						'result' => false
					);
				}
				/* end changed */
				$resource->setResourceData($data);
				$resource->save();
				$resource->updateUser($_POST['objectId'], array(
					'resource_user_group_access_level' => 1
				));
				
				$resource->updateUser($logged_user->getUserId(), array(
					'resource_user_group_access_level' => 1,
					'resource_opened_by_user' => 1
				));

				$cache->clean(array(NCache::TAGS => array("user_id/".$logged_user->getUserId(), "name/pmwidget")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$_POST['objectId'], "name/pmwidget")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$logged_user->getUserId(), "name/pmwidgetslim")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$_POST['objectId'], "name/pmwidgetslim")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$logged_user->getUserId(), "name/messagelisteruser")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$_POST['objectId'], "name/messagelisteruser")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$logged_user->getUserId(), "name/pmabstract")));
				$cache->clean(array(NCache::TAGS => array("user_id/".$_POST['objectId'], "name/pmabstract")));
			} else if ($_POST['objectType'] == "group") {
				$resource                = Resource::create();
				$data                    = array();
				$data['resource_author'] = $logged_user->getUserId();
				$data['resource_type']   = 8;
				$data['resource_name']   = '<chat>';//$_POST['message'];
				/* begin changed */
				//      				$data['resource_data'] = json_encode(array('message_text'=>$_POST['message']));
				$data['resource_data']   = json_encode(array(
					'message_text' => '<p>' . nl2br($_POST['message']) . '</p>'
				));
				$check                   = $resource->check_doublette($data, $logged_user->getUserId(), 1);
				if ($check === true) {
					return array(
						'result' => false
					);
				}
				/* end changed */
				$resource->setResourceData($data);
				$resource->save();
				$group = Group::Create($_POST['objectId']);
				$group->setLastActivity();
				$resource->updateUser($logged_user->getUserId(), array(
					'resource_user_group_access_level' => 1
				));
				$resource->updateGroup($_POST['objectId'], array(
					'resource_user_group_access_level' => 1
				));
				
				$cache->clean(array(NCache::TAGS => array("group_id/".$_POST['objectId'], "name/chatwidget")));
				
			} else if ($_POST['objectType'] == "resource") {
				$object_resource         = Resource::Create($_POST['objectId']);
				$resource                = Resource::create();
				$data                    = array();
				$data['resource_author'] = $logged_user->getUserId();
				$data['resource_type']   = 8;
				$data['resource_name']   = '<comment>';//$_POST['message'];
				/* begin changed */
				//      				$data['resource_data'] = json_encode(array('message_text'=>$_POST['message']));
				$data['resource_data']   = json_encode(array(
					'message_text' => '<p>' . nl2br($_POST['message']) . '</p>'
				));
				
				$check = $resource->check_doublette($data, $logged_user->getUserId(), 1);
				if ($check === true) {
					return array(
						'result' => false
					);
				}
				/* end changed */
				$resource->setResourceData($data);
				$resource->setParent($object_resource->getResourceId());
				
				$resource->save();
				$object_resource->setLastActivity();
				$resource->updateUser($logged_user->getUserId(), array(
					'resource_user_group_access_level' => 1
				));

				$cache->clean(array(NCache::TAGS => array("resource_id/".$object_resource->getResourceId(), "name/chatlisterresource")));

				
			} else {
				return array(
					'result' => false
				);			
			}
			return array(
				'result' => true
			);
		} else {
			return array(
				'result' => false
			);
		}
		
	}
	
	/**
	 * Comment
	 *
	 * @url POST	/ChangeProfile
	 */
	public function postChangeProfile()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		
		if (!empty($_POST['firstName'])) $values['user_name']             = $_POST['firstName'];
		if (!empty($_POST['lastName'])) $values['user_surname']          = $_POST['lastName'];
		if (!empty($_POST['user_send_notifications'])) $values['user_send_notifications']          = $_POST['user_send_notifications'];
		if (!empty($_POST['url'])) $values['user_url']          = $_POST['url'];
		if (!empty($_POST['visibility'])) $values['user_visibility_level'] = $_POST['visibility'];
		if (!empty($_POST['description'])) $values['user_description']      = $_POST['description'];
		if (!empty($_POST['position_gpsx'])) $values['user_position_x']       = $_POST['position_gpsx'];
		if (!empty($_POST['position_gpsy'])) $values['user_position_y']       = $_POST['position_gpsy'];
		if (!empty($_POST['image'])) {
			$image                   = $_POST['image'];
			$image                   = preg_replace("/[-]/", "+", $image);
			$image                   = preg_replace("/[_]/", "/", $image);
			$values['user_portrait'] = $image;
		}
		
		if (!empty($_POST['language_iso_639_3'])) {
			// Translate language_iso_639_3 to language id
			$values['user_language'] = Language::getIdFromCode($filter['language_iso_639_3']);
		}

		$logged_user = NEnvironment::getUser()->getIdentity();
		$user        = User::create($logged_user->getUserId());
		$data        = $user->getUserData();

		if (!empty($_POST['email'])) {
			$email = $_POST['email'];
			
			if ($data['user_email'] != $email && User::emailExists($email)) {
				return array(
					'result' => false,
					'error' => 'email_exists'
				);
			}
			if ($data['user_email'] != $email && !StaticModel::validEmail($email)) {
				return array(
					'result' => false,
					'error' => 'invalid_email'
				);
			}
			if ($data['user_email'] != $email) {
				$access = $user->getAccessLevel();
				if ($access < 2) {
					$values['user_email_new'] = $email;
					$values['user_email']     = $data['user_email'];
				
					$user->sendEmailchangeEmail();
				}
			}
		}
		$user->setUserData($values);
		$user->save();
		
		/* begin added */
		if (!empty($_POST['image'])) {
			$image_o = Image::createimage($logged_user->getUserId(), 1);
			$image_o->remove_cache()->fill_canvas()->crop(0, 0, 160, 200)->save_data()->create_cache();
		}
		Activity::addActivity(Activity::USER_UPDATED, $logged_user->getUserId(), 1);
		/* end added */
		
		return array(
			'result' => true
		);
		
	}
	
	
	/**
	 *Comment
	 * @url POST     /ChangeProfileTag
	 */
	public function postChangeProfileTag()
	{
		
		$logged_user = NEnvironment::getUser()->getIdentity();
		$tag_id      = $_POST['tagId'];
		if ($_POST['tagStatus'] == "true") {
			$logged_user->insertTag($tag_id);
		} else {
			$logged_user->removeTag($tag_id);
		}
		
		/* begin added */
		Activity::addActivity(Activity::USER_UPDATED, $logged_user->getUserId(), 1);
		/* end added */
		
		return array(
			'result' => true
		);
		
	}
	
	/**
	 *Comment
	 * @url POST	/RequestPasswordChange
	 */
	public function postRequestPasswordChange()
	{
		$user = User::getEmailOwner($_POST['user_email']);
		if (!empty($user)) {
			$user->sendLostpasswordEmail();
			return array(
				'result' => true
			);
			
		} else {
			return array(
				'result' => false
			);
		}
	}
	
	/**
	 *Comment
	 * @url POST     /EmptyTrash
	 */
	public function postEmptyTrash()
	{
		$user = NEnvironment::getUser()->getIdentity();
		Resource::emptyTrash();
		
		$storage = new NFileStorage(TEMP_DIR);
		$cache = new NCache($storage);
		$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmwidget")));
		$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmwidgetslim")));
		$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/messagelisteruser")));
		$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmabstract")));
		return array(
			'result' => true
		);
		
	}
	
	/**
	 *Comment
	 * @url POST     /MoveToTrash
	 */
	public function postMoveToTrash()
	{
		$user        = NEnvironment::getUser()->getIdentity();
		$resource_id = $_POST['message_id'];
		$resource    = Resource::create($resource_id);
		if (!empty($resource)) {
			if (!empty($user)) {
				if ($resource->userIsRegistered($user->getUserId())) {
					Resource::moveToTrash($resource_id);
					$storage = new NFileStorage(TEMP_DIR);
					$cache = new NCache($storage);
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmwidget")));
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmwidgetslim")));
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/messagelisteruser")));
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmabstract")));
				}
			}
		}
		return array(
			'result' => true
		);
		
	}
	
	
	/**
	 *Comment
	 * @url POST     /MoveFromTrash
	 */
	public function postMoveFromTrash()
	{
		$user        = NEnvironment::getUser()->getIdentity();
		$resource_id = $_POST['message_id'];
		$resource    = Resource::create($resource_id);
		if (!empty($resource)) {
			if (!empty($user)) {
				if ($resource->userIsRegistered($user->getUserId())) {
					Resource::moveFromTrash($resource_id);
					$storage = new NFileStorage(TEMP_DIR);
					$cache = new NCache($storage);
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmwidget")));
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmwidgetslim")));
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/messagelisteruser")));
					$cache->clean(array(NCache::TAGS => array("user_id/".$user->getUserId(), "name/pmabstract")));
				}
			}
		}
		return array(
			'result' => true
		);
		
	}
	
	/**
	 * Comment
	 * @url POST     /AcceptFriendship
	 */
	public function postAcceptFriendship()
	{
		$user        = NEnvironment::getUser()->getIdentity();
		$friend_id   = $_POST['friend_id'];
		$resource_id = $_POST['message_id'];
		$resource    = Resource::create($resource_id);
		if (!empty($resource)) {
			if (!empty($user)) {
				$user->updateFriend($friend_id, array());
				
				if ($resource->userIsRegistered($user->getUserId())) {
					Resource::moveToTrash($resource_id);
				}
			}
		}
		return array(
			'result' => true
		);
		
	}
	
	/**
	 * Comment
	 * @url POST     /DeclineFriendship
	 */
	public function postDeclineFriendship()
	{
		$user        = NEnvironment::getUser()->getIdentity();
		$friend_id   = $_POST['friend_id'];
		$resource_id = $_POST['message_id'];
		$resource    = Resource::create($resource_id);
		if (!empty($resource)) {
			if (!empty($user)) {
				$user->removeFriend($friend_id, array());
				
				if ($resource->userIsRegistered($user->getUserId())) {
					Resource::moveToTrash($resource_id);
				}
			}
		}
		
		return array(
			'result' => true
		);
		
	}
	
	/**
	 *Comment
	 * @url POST     /CreateGroup
	 */
	public function postCreateGroup()
	{
		$user = NEnvironment::getUser()->getIdentity();
		
		$group_name        = $_POST['name'];
		$group_description = $_POST['description'];
		$group_visibility  = $_POST['visibility'];
		$group_gpsx        = $_POST['position_gpsx'];
		$group_gpsy        = $_POST['position_gpsy'];
		$group_tags        = $_POST['tags'];
		if (!empty($_POST['language_iso_639_3'])) {
			$group_language = Language::getIdFromCode($_POST['language_iso_639_3']);
		} else {
			$group_language    = $_POST['language'];
		}
		$tags              = explode(',', $group_tags);

		if (NEnvironment::getVariable("APK_GROUP_CREATE_MIN_ROLE") > 0) {
			$min_access_level = NEnvironment::getVariable("APK_GROUP_CREATE_MIN_ROLE");
		} else {
			$min_access_level = Auth::USER;
		}

		if (Auth::MODERATOR > $user->getAccessLevel()) {
			if ($min_access_level > $user->getAccessLevel() || !$user->hasRightsToCreate()) {
				return array(
					'result' => false,
					'message' => 'no_rights'
				);
			}
		}
		
		$group = Group::create();
		
		if (empty($group_name) || empty($group_description) || empty($group_visibility) || empty($group_language)) {
			return array(
				'result' => false,
				'message' => 'empty_fields'
			);
		}
		$values['group_name']             = $group_name;
		$values['group_description']      = $group_description;
		$values['group_visibility_level'] = $group_visibility;
		$values['group_position_x']       = $group_gpsx;
		$values['group_position_y']       = $group_gpsy;
		
		$values['group_author']   = $user->getUserId();
		$values['group_language'] = $group_language;
		$group->setGroupData($values);
		$group->save();
		$group->setLastActivity();
		$group->updateUser($user->getUserId(), array(
			'group_user_access_level' => 3
		));
		$group_id = $group->getGroupId();
		
		foreach ($tags as $tag_id) {
			$group->insertTag($tag_id);
		}
		
		/* begin added */
		Activity::addActivity(Activity::GROUP_CREATED, $group_id, 2);
		/* end added */
		
		return array(
			'result' => true,
			'group_id' => $group_id
		);
		
	}
	

	/**
	 * Comment
	 *
	 * @url POST    /UnreadMessages
	 */
	public function getUnreadMessages()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}
		$messages = Resource::getUnreadMessages();
		
		return array(
			'result' => true,
			'unread_messages' => $messages
		); //Allready checked by common authorize function in rest server
	}


	/**
	 * Comment
	 *
	 * @url POST    /Deployment
	 */
	public function getDeploymentData()
	{
		// also available if not logged in
				
		$db_version = dibi::fetchSingle("SELECT `value` FROM `system` WHERE `name`= 'database_version'");
		
		$data = array(
			'name' => NEnvironment::getVariable("PROJECT_NAME"),
			'description' => NEnvironment::getVariable("PROJECT_DESCRIPTION"),
			'db_version' => $db_version,
			'terms_url' => NEnvironment::getVariable("TC_URL"),
			'privacy_url' => NEnvironment::getVariable("PP_URL"),
			'support_url' => NEnvironment::getVariable("SUPPORT_URL"),
			'languages' => Language::getArrayAPI(),
			'logo_url' => NEnvironment::getVariable("URI") . '/images/logo.png'
		);
		
		if (!empty($this->userId)) {
			$user = User::create($this->userId);
			$user->setLastActivity();
		}
		
		return $data;
	}
	
	
	/**
	 * Comment
	 *
	 * @url POST    /CanCreateGroups
	 */
	public function getGroupCreationRights()
	{
		if (!$this->isLoggedIn()) {
			throw new RestException('401', null);
		}

		$user = NEnvironment::getUser()->getIdentity();
		
		if (NEnvironment::getVariable("APK_GROUP_CREATE_MIN_ROLE") > 0) {
			$min_access_level = NEnvironment::getVariable("APK_GROUP_CREATE_MIN_ROLE");
		} else {
			$min_access_level = Auth::USER;
		}

		if ((Auth::MODERATOR > $user->getAccessLevel()) && ($min_access_level > $user->getAccessLevel() || !$user->hasRightsToCreate())) {
			$group_creation_rights = false;
		} else {
			$group_creation_rights = true;
		}

		return array(
			'result' => true,
			'group_creation_rights' => $group_creation_rights
		);
	}
}
