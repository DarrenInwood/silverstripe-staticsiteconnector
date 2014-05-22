<?php
/*
 * URL transformer specific to SilverStripe's `SiteTree` object for use within the import functionality.
 *
 * @see {@link StaticSiteFileTransformer}
 */
class StaticSitePageTransformer extends Object implements ExternalContentTransformer {

	/*
	 * @var Object
	 *
	 * Holds the StaticSiteUtils object on construct
	 */
	protected $utils;

	/**
	 * Set this by using the yml config system
	 *
	 * Example:
	 * <code>
	 * StaticSiteContentExtractor:
     *    log_file:  ../logs/import-log.txt
	 * </code>
	 *
	 * @var string
	 */
	private static $log_file = null;

	public function __construct() {
		$this->utils = singleton('StaticSiteUtils');
	}

	/**
	 *
	 * @param StaticSiteContentItem $item
	 * @param type $parentObject
	 * @param type $duplicateStrategy
	 * @return boolean|\StaticSiteTransformResult
	 * @throws Exception
	 */
	public function transform($item, $parentObject, $duplicateStrategy) {
		// Force us to consider Stage mode records.  When searching for duplicates, this mode
		// will return the latest Live mode one - unless there's actually a staged version, which
		// we also want anyway
		$origMode = Versioned::get_reading_mode(); 
		$this->utils->log('Versioned reading mode was: '.$origMode);
		Versioned::set_reading_mode('Stage.Stage');

		$this->utils->log("START transform for: ",$item->AbsoluteURL, $item->ProcessedMIME);
		$this->utils->log('Parent object: '.$parentObject->class.':'.$parentObject->ID.' - '.$parentObject->Title);

		$item->runChecks('sitetree');
		if($item->checkStatus['ok'] !== true) {
			$this->utils->log($item->checkStatus['msg']." for: ",$item->AbsoluteURL, $item->ProcessedMIME);
			return false;
		}

		$source = $item->getSource();

		// Cleanup StaticSiteURLs
		//$this->utils->resetStaticSiteURLs($item->AbsoluteURL, $source->ID, 'SiteTree');

		// Check to see if we need to exclude this for CSS reasons
		$excludeCssLines = explode("\n", $source->UrlExcludeCss);
		$content = $item->getContentExtractor()->getPhpQuery();
		foreach( $excludeCssLines as $excludeCss ) {
			if ( $content->find($excludeCss)->length() > 0 ) {
				$this->utils->log("Excluded via CSS ".$excludeCss,$item->AbsoluteURL,$item->ProcessedMIME);
				Versioned::set_reading_mode($origMode);
				return false;
			}
		}

		// Sleep for 100ms to reduce load on the remote server
		usleep(100*1000);

		// Extract content from the page
		$contentFields = $this->getContentFieldsAndSelectors($item);

		// Default value for Title
		if(empty($contentFields['Title'])) {
			$contentFields['Title'] = array('content' => $item->Name);
		}

		// Default value for URL segment
		if(empty($contentFields['URLSegment'])) {
			$urlSegment = str_replace('/','', $item->Name);
			$urlSegment = preg_replace('/\.[^.]*$/','',$urlSegment);
			$urlSegment = str_replace('.','-', $item->Name);
			$contentFields['URLSegment'] = array('content' => $urlSegment);
		}

		$schema = $source->getSchemaForItem($item);
		if(!$schema) {
			$this->utils->log("Couldn't find an import schema for: ",$item->AbsoluteURL,$item->ProcessedMIME);
			Versioned::set_reading_mode($origMode);
			return false;
		}

		$pageType = $schema->DataType;

		if(!$pageType) {
			$this->utils->log("DataType for migration schema is empty for: ",$item->AbsoluteURL,$item->ProcessedMIME);
			Versioned::set_reading_mode($origMode);
			throw new Exception('Pagetype for migration schema is empty!');
		}

		// Create a page with the appropriate fields
		$page = new $pageType(array());
		$this->utils->log("Chose $pageType for ",$item->AbsoluteURL,$item->ProcessedMIME);

		// Try without leading slash
		$staticSiteURL = Controller::join_links(
			$source->BaseUrl,
			$item->ProcessedURL
		);
		$existingPage = SiteTree::get()->filter('StaticSiteURL', $staticSiteURL)->First();
		if ( $existingPage ) {
			$this->utils->log("Found existing page ".$existingPage->Title.":".$existingPage->ID.' getExternalId:'.$item->getExternalId());
		} else {
			$this->utils->log('No existing page for StaticSiteURL:'.$item->AbsoluteURL.' getExternalId:'.$item->getExternalId());
		}

		if($existingPage && $duplicateStrategy === 'Overwrite') {
			if(get_class($existingPage) !== $pageType) {
				$existingPage->ClassName = $pageType;
				$existingPage->write();
			}
			$page = $existingPage;
 			$this->utils->log('Overwrote: ',$item->AbsoluteURL,$item->ProcessedMIME);
		}

		$page->StaticSiteContentSourceID = $source->ID;
		$page->StaticSiteURL = $staticSiteURL;

		$page->ParentID = $parentObject ? $parentObject->ID : 0;

		foreach($contentFields as $k => $v) {
			$page->$k = $v['content'];
		}

		$page->write();
		$page->publish('Stage', 'Live');
		$page->flushCache();

		// Run any processors
		foreach( $schema->ImportProcessors() as $processorLink ) {
			$processorName = $processorLink->Name;
			$this->utils->log("Using $processorName to process page ");
			call_user_func_array(
				array(
					$processorName, 
					'process'
				),
				array(
					$item,
					$page,
					$parentObject,
					$duplicateStrategy
				)
			);
		}

		$page->write();
		$page->publish('Stage', 'Live');
		$page->flushCache();

		$aliases = $item->getSource()->urlList()->getURLAliases($item->AbsoluteURL);
		if($aliases) {
			StaticSiteURLAlias::set_object($page, $aliases);
		}

		Versioned::set_reading_mode($origMode);
		return new StaticSiteTransformResult($page, $item->stageChildren());
	}

	/**
	 * Get content from the remote host
	 *
	 * @param  StaticSiteeContentItem $item The item to extract
	 * @return array A map of field name => array('selector' => selector, 'content' => field content)
	 */
	public function getContentFieldsAndSelectors($item) {
		// Get the import rules from the content source
		$importSchema = $item->getSource()->getSchemaForItem($item);
		if(!$importSchema) {
			return null;
			throw new LogicException("Couldn't find an import schema for URL: {$item->AbsoluteURL} and Mime: {$item->ProcessedMIME}");
		}
		$importRules = $importSchema->getImportRules();

 		// Extract from the remote page based on those rules
		$contentExtractor = $item->getContentExtractor();

		return $contentExtractor->extractMapAndSelectors($importRules, $item);
	}
}