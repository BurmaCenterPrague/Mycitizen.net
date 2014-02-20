<?php
/**
 * mycitizen.net - Social networking for civil society
 *
 *
 * @author http://mycitizen.org
 * @copyright  Copyright (c) 2013, 2014 Burma Center Prague (http://www.burma-center.org)
 * @link http://mycitizen.net
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3
 *
 * @package mycitizen.net
 */
 

class ResourceMemberListerControl extends ListerControl {
	protected $resource = null;
	protected $resource_id = null;

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function __construct($parent,$name,$resource_id,$options) {
		$this->resource = Resource::create($resource_id);	
      $this->resource_id = $resource_id;

		parent::__construct($parent,$name,$options);
		$this->setRefreshPath("Resource:edit",array('resource_id'=>$this->resource_id));
   }


/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function render() {
		$this->renderFilter();
		$this->renderBody();
   }

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function renderFilter() {
		parent::renderFilter();
		$template = $this->template;
      $template->setFile(dirname(__FILE__) . '/ResourceMemberListerControl_filter.phtml');
      $template->render();

	}

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function renderBody() {
		parent::renderBody();
		$template = $this->template;
      $template->setFile(dirname(__FILE__) . '/ResourceMemberListerControl.phtml');
      $template->render();

	}

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function createComponentListItem($data_row)
    {
		$params = NEnvironment::getHttpRequest()->getQuery("lister-page");
      $form = new NAppForm($this,"userform".$data_row['member_type']."x".$data_row['member_id']);
      $form->addHidden('member_id');
		$form->addHidden('member_type');
		$access_level = array(
								'1'=>'Normal user',
								'2'=>'Moderator',
								'3'=>'Administrator'
							 );
        $form->addSelect('resource_user_group_access_level',null,$access_level);
        $form->addCheckbox('resource_user_group_status');
        $form->addSubmit('send', 'Update');
        $form->addProtection(_t('Error submitting form.'));
        $form->onSubmit[] = array($this, 'adminUserFormSubmitted');
        $form->setDefaults(array('member_id'=>$data_row['member_id'],'member_type'=>$data_row['member_type'],'resource_user_group_access_level'=>$data_row['resource_user_group_access_level'],'resource_user_group_status'=>$data_row['resource_user_group_status']));
        return $form;
    }

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
    public function adminUserFormSubmitted(NAppForm $form)
    {
        $values = $form->getValues();
			$member_id = $values['member_id'];
			$member_type = $values['member_type'];
        unset($values['member_id']);
		  unset($values['member_type']);
			if($member_type == 1) {
		  		$this->resource->updateUser($member_id,$values);
			} else {
				$this->resource->updateGroup($member_id,$values);
			}
        $this->getPresenter()->redirect($this->refresh_path, $this->refresh_path_params);
    }

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function createComponentFilter() {
		$form = new NAppForm($this,"filter");
		$form->addText('name','Name');
		$access_level = array(
								'null'=>'All',
                                '1'=>'Normal user',
                                '2'=>'Moderator',
                                '3'=>'Administrator'
                             ); 
        $form->addSelect('resource_user_group_access_level','User permissions',$access_level);
		$enabled = array(
							'null'=>'All',
							'1'=>'Active',
							'0'=>'Inactive'
						);
        $form->addSelect('resource_user_group_status','User status',$enabled);
        $form->addSubmit('filter', 'Apply filter');
        $form->addProtection(_t('Error submitting form.'));
        $form->onSubmit[] = array($this, 'filterFormSubmitted');
		$form->setDefaults($this->getFilterArray());
		return $form;
	}

/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function filterFormSubmitted(NAppForm $form)
    {
       	$values = $form->getValues();
		$filter = $this->getFilterArray();
		$new_filter = array_merge($filter,$values);
		
		$this->setFilterArray($new_filter);
		$this->getPresenter()->redirect($this->refresh_path, $this->refresh_path_params);
    }


/**
 *	@todo ### Description
 *	@param
 *	@return
*/
	public function getDataCount($filter) {
		$data = $this->resource->getAllMembers($filter);
		return count($data);
	}

	/**
	 *	@todo ### Description
	 *	@param
	 *	@return
	 */
	public function getPageData($filter) {
		if(!isset($filter['page'])) {
         $filter['page'] = 1;
      }
		$limit = $this->pageTolimit($filter['page']);
		$filter['limit']=$limit['from'];
		$filter['count']=$limit['count'];
		$data = $this->resource->getAllMembers($filter);
		return $data;	
	}
}
