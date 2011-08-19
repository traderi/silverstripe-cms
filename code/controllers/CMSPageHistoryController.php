<?php

/**
 * @package cms
 * @subpackage controllers
 */
class CMSPageHistoryController extends CMSMain {

	static $url_segment = 'page/history';
	static $url_rule = '/$Action/$ID/$VersionID/$OtherVersionID';
	static $url_priority = 42;
	static $menu_title = 'History';
	
	static $allowed_actions = array(
		'VersionsForm',
		'version',
		'compare'
	);
	
	public static $url_handlers = array(
		'$Action/$ID/$VersionID/$OtherVersionID' => 'handleAction'
	);
	
	/**
	 * @var array
	 */
	function version() {
		return array(
			'EditForm' => $this->ShowVersionForm(
				$this->request->param('ID')
			)
		);
	}
	
	/**
	 * @var array
	 */
	function compare() {
		return array(
			'EditForm' => $this->CompareVersionsForm(
				$this->request->param('VersionID'), 
				$this->request->param('OtherVersionID')
			)
		);
	}
	
	/**
	 * Returns the read only version of the edit form. Detaches {@link FormAction} 
	 * instances attached since only action relates to revert.
	 *
	 * Permission checking is done at the {@link CMSMain::getEditForm()} level.
	 * 
	 * @param int $id ID of the record to show
	 * @param array $fields optional
	 * @param int $versionID
	 */
	function getEditForm($id = null, $fields = null, $versionID = null) {
		$record = $this->getRecord($id, $versionID);
		
		$form = parent::getEditForm($record, ($record) ? $record->getCMSFields() : null);

		$fields = $form->Fields();
		$fields->removeByName("Status");
		$fields->push(new HiddenField("ID"));
		$fields->push(new HiddenField("Version"));
		
		$fields = $fields->makeReadonly();
		
		foreach($fields->dataFields() as $field) {
			$field->dontEscape = true;
		}
		
		$form->setFields($fields->makeReadonly());
		
		// attach additional information
		$form->loadDataFrom(array(
			"ID" => $id,
			"Version" => $versionID,
		));
		
		$form->setActions(new FieldSet(
			$revert = new FormAction('doRevert', _t('CMSPageHistoryController.REVERTTOTHISVERSION', 'Revert to this version'))
		));
		
		$form->removeExtraClass('cms-content');

		return $form;
	}
	
	/**
	 * Compare version selection form. Displays a list of previous versions
	 * and options for selecting filters on the version
	 * 
	 * @return Form
	 */
	function VersionsForm() {
		$id = $this->currentPageID();
		$page = $this->getRecord($id);
		$versionsHtml = '';

		if($page) {
			$versions = $page->allVersions();

			if($versions) {
				foreach($versions as $k => $version) {
					$version->CMSLink = sprintf('%s/%s/%s',
						$this->Link('version'),
						$version->ID,
						$version->Version
					);
				}
			}
			
			$vd = new ViewableData();
			
			$versionsHtml = $vd->customise(array(
				'Versions' => $versions
			))->renderWith('CMSPageHistoryController_versions');
		}
		
		$form = new Form(
			$this,
			'VersionsForm',
			new FieldSet(
				new CheckboxField(
					'ShowUnpublished',
					_t('CMSPageHistoryController.SHOWUNPUBLISHED','Show unpublished versions')
				),
				new CheckboxField(
					'CompareMode',
					_t('CMSPageHistoryController.COMPAREMODE', 'Compare mode')
				),
				new LiteralField('VersionsHtml', $versionsHtml),
				new HiddenField('ID', false, $id)
			),
			new FieldSet(
				new FormAction(
					'doCompare', _t('CMSPageHistoryController.COMPAREVERSIONS','Compare Versions')
				),
				new FormAction(
					'doShowVersion', _t('CMSPageHistoryController.SHOWVERSION','Show Version') 
				)
			)
		);
		
		$form->loadDataFrom($this->request->requestVars());
		$form->unsetValidator();
		
		return $form;
	}
	
	/**
	 * Process the {@link VersionsForm} compare function between two pages.
	 *
	 * @param array
	 * @param Form
	 *
	 * @return html
	 */
	function doCompare($data, $form) {
		$versions = $data['Versions'];
		if(count($versions) < 2) return null;
		
		$id = $this->currentPageID();
		$version1 = array_shift($versions);
		$version2 = array_shift($versions);

		$form = $this->CompareVersionsForm($version1, $version2);

		// javascript solution, render into template
		if($this->isAjax()) {
			return $this->customise(array(
				"EditForm" => $form
			))->renderWith(array(
				$this->class . '_EditForm', 
				'LeftAndMain_Content'
			));
		}
		
		// non javascript, redirect the user to the page
		$this->redirect(Controller::join_links(
			$this->Link('compare'),
			$version1,
			$version2
		));
	}

	/**
	 * Process the {@link VersionsForm} show version function. Only requires
	 * one page to be selected.
	 *
	 * @param array
	 * @param Form
	 *
	 * @return html
	 */	
	function doShowVersion($data, $form) {
		$versionID = null;
		
		if(isset($data['Versions']) && is_array($data['Versions'])) { 
			$versionID  = array_shift($data['Versions']);
		}
		
		if(!$versionID) return;
		
		if($this->isAjax()) {
			return $this->customise(array(
				"EditForm" => $this->ShowVersionForm($versionID)
			))->renderWith(array(
				$this->class . '_EditForm', 
				'LeftAndMain_Content'
			));
		}

		// non javascript, redirect the user to the page
		$this->redirect(Controller::join_links(
			$this->Link('version'),
			$versionID
		));
	}
	
	/**
	 * Rolls a site back to a given version ID
	 *
	 * @param array
	 * @param Form
	 *
	 * @return html
	 */
	function doRollback($data, $form) {
		//
	}

	/**
	 * @return Form
	 */
	function ShowVersionForm($versionID = null) {
		if(!$versionID) return null;
		
		$id = $this->currentPageID();
		$form = $this->getEditForm($id, null, $versionID);

		return $form;
	}
	
	/**
	 * @return Form
	 */
	function CompareVersionsForm($versionID, $otherVersionID) {
		if($versionID > $otherVersionID) {
			$toVersion = $versionID;
			$fromVersion = $otherVersionID;
		} else {
			$toVersion = $otherVersionID;
			$fromVersion = $versionID;
		}

		if(!$toVersion || !$toVersion) return false;
		
		$id = $this->currentPageID();
		$page = DataObject::get_by_id("SiteTree", $id);
		
		if($page && !$page->canView()) {
			return Security::permissionFailure($this);
		}

		$record = $page->compareVersions($fromVersion, $toVersion);

		$fromVersionRecord = Versioned::get_version('SiteTree', $id, $fromVersion);
		$toVersionRecord = Versioned::get_version('SiteTree', $id, $toVersion);
		
		if(!$fromVersionRecord) {
			user_error("Can't find version $fromVersion of page $id", E_USER_ERROR);
		}
		
		if(!$toVersionRecord) {
			user_error("Can't find version $toVersion of page $id", E_USER_ERROR);
		}

		if($record) {
			$form = $this->getEditForm($id, null, null);
			$form->setActions(new FieldSet());
			$form->loadDataFrom($record);
			
			$form->loadDataFrom(array(
				"ID" => $id,
				"Version" => $fromVersion,
			));
			
			$form->addExtraClass('compare');
			
			return $form;
		}
	}

	/**
	 * Roll a page back to a previous version
	 */
	function rollback($data, $form) {
		$this->extend('onBeforeRollback', $data['ID']);
		
		if(isset($data['Version']) && (bool)$data['Version']) {
			$record = $this->performRollback($data['ID'], $data['Version']);
			$message = sprintf(
			_t('CMSMain.ROLLEDBACKVERSION',"Rolled back to version #%d.  New version number is #%d"),
			$data['Version'],
			$record->Version
		);
		} else {
			$record = $this->performRollback($data['ID'], "Live");
			$message = sprintf(
				_t('CMSMain.ROLLEDBACKPUB',"Rolled back to published version. New version number is #%d"),
				$record->Version
			);
		}
		
		$this->response->addHeader('X-Status', $message);
		$form = $this->getEditForm($record->ID);
		
		return $form->forTemplate();
	}
}