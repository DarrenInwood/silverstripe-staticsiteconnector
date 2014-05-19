<?php

class StaticSiteContentSource extends ExternalContentSource {

	public static $db = array(
		'BaseUrl' => 'Varchar(255)',
		'UrlProcessor' => 'Varchar(255)',
		'ExtraCrawlUrls' => 'Text',
		'UrlExcludePatterns' => 'Text',
	);

	public static $has_many = array(
		"Schemas" => "StaticSiteContentSource_ImportSchema",
		"Pages" => "SiteTree",
		"Files" => "File"
	);

	public $absoluteURL = null;

	/*
	 * Where do we store our items for caching?
	 * Also used by calling logic
	 *
	 * @var string
	 */
	public $staticSiteCacheDir = null;

	/**
	 *
	 * @param array|null $record This will be null for a new database record.
	 * @param bool $isSingleton
	 * @param DataModel $model
	 */
	public function __construct($record = null, $isSingleton = false, $model = null) {
		parent::__construct($record, $isSingleton, $model);
		// We need this in calling logic
		$this->staticSiteCacheDir = "static-site-{$this->ID}";
	}

	/**
	 *
	 * @return FieldList
	 * @throws LogicException
	 */
	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$importRules = $fields->dataFieldByName('Schemas');
		$importRules->getConfig()->removeComponentsByType('GridFieldAddExistingAutocompleter');
		$importRules->getConfig()->removeComponentsByType('GridFieldAddNewButton');
		$addNewButton = new GridFieldAddNewButton('after');
		$addNewButton->setButtonName("Add schema");
		$importRules->getConfig()->addComponent($addNewButton);

		$fields->removeFieldFromTab("Root", "Schemas");
		$fields->removeFieldFromTab("Root", "Pages");
		$fields->removeFieldFromTab("Root", "Files");
		$fields->addFieldToTab("Root.Main", new LiteralField("", "<p>Each import rule will import content for a field"
			. " by getting the results of a CSS selector.  If more than one rule exists for a field, then they will be"
			. " processed in the order they appear.  The first rule that returns content will be the one used.</p>"));
		$fields->addFieldToTab("Root.Main", $importRules);

		$processingOptions = array("" => "No pre-processing");
		foreach(ClassInfo::implementorsOf('StaticSiteUrlProcessor') as $processor) {
			$processorObj = new $processor;
			$processingOptions[$processor] = "<strong>" . Convert::raw2xml($processorObj->getName())
				. "</strong><br>" . Convert::raw2xml($processorObj->getDescription());
		}

		$fields->addFieldToTab("Root.Main", new OptionsetField("UrlProcessor", "URL processing", $processingOptions));


		switch($this->urlList()->getSpiderStatus()) {
			case "Not started":
				$crawlButtonText = _t('StaticSiteContentSource.CRAWL_SITE', 'Crawl site');
				break;

			case "Partial":
				$crawlButtonText = _t('StaticSiteContentSource.RESUME_CRAWLING', 'Resume crawling');
				break;

			case "Complete":
				$crawlButtonText = _t('StaticSiteContentSource.RECRAWL_SITE', 'Re-crawl site');
				break;

			default:
				throw new LogicException("Invalid getSpiderStatus() value '".$this->urlList()->getSpiderStatus().";");
		}

		$crawlButton = FormAction::create('crawlsite', $crawlButtonText)
			->setAttribute('data-icon', 'arrow-circle-double')
			->setUseButtonTag(true);
		$fields->addFieldsToTab('Root.Crawl', array(
			new ReadonlyField("CrawlStatus", "Crawling Status", $this->urlList()->getSpiderStatus()),
			new ReadonlyField("NumURLs", "Number of URLs", $this->urlList()->getNumURLs()),

			new LiteralField('CrawlActions',
			"<p>Before importing this content, all URLs on the site must be crawled (like a search engine does). Click"
			. " the button below to do so:</p>"
			. "<div class='Actions'>{$crawlButton->forTemplate()}</div>")
		));

		/*
		 * @todo use customise() and arrange this using an includes .ss template fragment
		 */
		if($this->urlList()->getSpiderStatus() == "Complete") {
			$urlsAsUL = "<ul>";
			$list = array_unique($this->urlList()->getProcessedURLs());

			foreach($list as $raw => $processed) {
				if($raw == $processed) {
					$urlsAsUL .= "<li>$processed</li>";
				} else {
					$urlsAsUL .= "<li>$processed <em>(was: $raw)</em></li>";
				}
			}
			$urlsAsUL .= "</ul>";

			$fields->addFieldToTab('Root.Crawl',
				new LiteralField('CrawlURLList', "<p>The following URLs have been identified:</p>" . $urlsAsUL)
			);
		}

		$fields->dataFieldByName("ExtraCrawlUrls")
			->setDescription("Add URLs that are not reachable through content scraping, eg: '/about/team'. One per line")
			->setTitle('Additional URLs');
		$fields->dataFieldByName("UrlExcludePatterns")
			->setDescription("URLs that should be excluded (support regular expression). eg: '/about/.*'. One per URL")
			->setTitle('Excluded URLs');

		return $fields;
	}

	/**
	 *
	 */
	public function onAfterWrite() {
		parent::onAfterWrite();

		$urlList = $this->urlList();
		if($this->isChanged('UrlProcessor') && $urlList->hasCrawled()) {
			if($processorClass = $this->UrlProcessor) {
				$urlList->setUrlProcessor(new $processorClass);
			} else {
				$urlList->setUrlProcessor(null);
			}
			$urlList->reprocessUrls();
		}
	}

	/**
	 *
	 * @return StaticSiteUrlList
	 */
	public function urlList() {
		if(!$this->urlList) {
			$this->urlList = new StaticSiteUrlList($this->BaseUrl, "../assets/{$this->staticSiteCacheDir}");
			if($processorClass = $this->UrlProcessor) {
				$this->urlList->setUrlProcessor(new $processorClass);
			}
			if($this->ExtraCrawlUrls) {
				$extraCrawlUrls = preg_split('/\s+/', trim($this->ExtraCrawlUrls));
				$this->urlList->setExtraCrawlUrls($extraCrawlUrls);
			}
			if($this->UrlExcludePatterns) {
				$urlExcludePatterns = preg_split('/\s+/', trim($this->UrlExcludePatterns));
				$this->urlList->setExcludePatterns($urlExcludePatterns);
			}
 		}
		return $this->urlList;
	}

	/**
	 * Crawl the target site
	 * @return StaticSiteCrawler
	 */
	public function crawl($limit=false, $verbose=true) {
		if(!$this->BaseUrl) {
			throw new LogicException("Can't crawl a site until Base URL is set.");
		}
		return $this->urlList()->crawl($limit, $verbose);
	}

	/*
	 * Fetch an appropriate schema for a given item. 
	 * Takes into account URL, MIME type, and HTML content vs CSS selectors.
	 * If no matches are found, boolean false is returned.
	 *
	 * @param string $item (Optional) The HTML content of the item to import.  If present, any CSS Filter rules will be applied.
	 * @return mixed $schema or boolean false if no schema matches are found
	 */
	public function getSchemaForItem($item) {
		$absoluteURL = $item->AbsoluteURL;
		$mimeType = $item->ProcessedMIME;
		$mimeType = StaticSiteMimeProcessor::cleanse($mimeType);
		foreach($this->Schemas() as $schema) {
			$schemaCanParseURL = $this->schemaCanParseURL($schema, $absoluteURL);
			$schemaMimeTypes = StaticSiteMimeProcessor::get_mimetypes_from_text($schema->MimeTypes);
			array_push($schemaMimeTypes, StaticSiteUrlList::$undefined_mime_type);
			if($schemaCanParseURL) {

				if($mimeType && $schemaMimeTypes && (!in_array($mimeType, $schemaMimeTypes))) {
					continue;
				}

				if(!$schema->canParseItemHTML($item)) {
					continue;
				}

				return $schema;
			}
		}
		return false;
	}

	/*
	 * Performs a match on the Schema->AppliedTo field with reference to the URL
	 * of the current iteration
	 *
	 * @param StaticSiteContentSource_ImportSchema $schema
	 * @param string $url
	 * @return boolean
	 */
	public function schemaCanParseURL(StaticSiteContentSource_ImportSchema $schema, $url) {
		$appliesTo = $schema->AppliesTo;
		if(!strlen($appliesTo)) {
			$appliesTo = $schema::$default_applies_to;
		}

		// backslash the delimiters for the reg exp pattern
		$appliesTo = str_replace('@', '\@', $appliesTo);
		if(preg_match("@^$appliesTo@", $url) == 1) {
			return true;
		}
		return false;
	}

	/**
	 * Returns a StaticSiteContentItem for the given URL
	 * Relative URLs are used as the unique identifiers by this importer
	 *
	 * @param $id The URL, relative to BaseURL, starting with "/".
	 * @return DataObject
	 */
	public function getObject($id) {

		if($id[0] != "/") {
			$id = $this->decodeId($id);
			if($id[0] != "/") throw new InvalidArgumentException("\$id must start with /");
		}

		return new StaticSiteContentItem($this, $id);
	}

	public function getRoot() {
		return $this->getObject('/');
	}

	/*
	 * Signals external-content module that we wish to operate on `SiteTree` and `File` objects
	 *
	 * @return array
	 */
	public function allowedImportTargets() {
		return array(
			'sitetree'	=> true,
			'file'		=> true
		);
	}

	/**
	 * Return the root node
	 * @return ArrayList A list containing the root node
	 */
	public function stageChildren($showAll = false) {
		if(!$this->urlList()->hasCrawled()) return new ArrayList;

		return new ArrayList(array(
			$this->getObject("/")
		));

	}

	public function getContentImporter($target=null) {
		return new StaticSiteImporter();
	}

	public function isValid() {
		if(!(boolean)$this->BaseUrl) {
			return false;
		}
		return true;
	}
	public function canImport($member = null) {
		return $this->isValid();
	}
	public function canCreate($member = null) {
		return true;
	}

}

/**
 * A collection of ImportRules that apply to some or all of the content being imported.
 */
class StaticSiteContentSource_ImportSchema extends DataObject {

	/**
	 * Default
	 *
	 * @var string
	 */
	public static $default_applies_to = '.*';

	/**
	 * Default
	 *
	 * @var string
	 */
	public static $default_css_filter = 'body';

	public static $db = array(
		"DataType" => "Varchar", // classname
		"Order" => "Int",
		"AppliesTo" => "Varchar(255)", // regex
		"CssFilter" => "Varchar(255)", // regex
		"MimeTypes" => "Text"
	);
	public static $summary_fields = array(
		"AppliesTo",
		"CssFilter",
		"DataType",
		"Order"
	);
	public static $field_labels = array(
		"AppliesTo" => "URLs applied to",
		"CssFilter" => "CSS filter",
		"DataType" => "Data type",
		"Order" => "Priority",
		"MimeTypes"	=> "Applies rule to these Mime-types"
	);

	public static $default_sort = "Order";

	public static $has_one = array(
		"ContentSource" => "StaticSiteContentSource",
	);

	public static $has_many = array(
		"ImportRules" => "StaticSiteContentSource_ImportRule",
		"ImportProcessors" => "StaticSiteContentSource_ImportProcessor",
	);

	public function getTitle() {
		return $this->DataType.' ('.$this->AppliesTo.')';
	}

	/**
	 *
	 * @return FieldList
	 */
	public function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->removeFieldFromTab('Root.Main', 'DataType');
		$fields->removeByName('ContentSourceID');

		$fields->dataFieldByName('Order')->setDescription(
			'Smaller numbers are run first, so give your specific-case schemas lower '.
			'numbers and your catch-all cases higher numbers. The first schema to match '.
			'each page will be used.'
		);

		$fields->dataFieldByName('AppliesTo')->setDescription(
			'Supports regular expressions. Evaluated as "@^VALUE@" against the entire external site URL, so '.
			'don\'t start with "^"" but do include "http://". Remember to allow for URL nesting.'
		);

		$fields->dataFieldByName('CssFilter')->setDescription(
			'CSS selector to test for. The rule will match if the page has at least one '.
			'element matching the selector.  Leave blank to always match.'
		);

		$dataObjects = ClassInfo::subclassesFor('DataObject');
		array_shift($dataObjects);
		natcasesort($dataObjects);
		$fields->addFieldToTab('Root.Main', new DropdownField('DataType', 'DataType', $dataObjects));

		$mimes = new TextareaField('MimeTypes', 'Mime-types');
		$mimes->setRows(3);
		$mimes->setDescription('Be sure to pick a MIME-type that the DataType supports. Examples of valid entries are e.g text/html, image/png or image/jpeg separated by a newline.');
		$fields->addFieldToTab('Root.Main', $mimes);

		$importRules = $fields->dataFieldByName('ImportRules');
		$fields->removeFieldFromTab('Root', 'ImportRules');

		// File don't use import rules
		if($this->DataType && in_array('File', ClassInfo::ancestry($this->DataType))) {
			return $fields;
		}

		if($importRules) {
			$importRules->getConfig()->removeComponentsByType('GridFieldAddExistingAutocompleter');
			$importRules->getConfig()->removeComponentsByType('GridFieldAddNewButton');
			$addNewButton = new GridFieldAddNewButton('after');
			$addNewButton->setButtonName("Add Rule");
			$importRules->getConfig()->addComponent($addNewButton);
			$fields->addFieldToTab('Root.Main', $importRules);
		}

		$importProcessors = $fields->dataFieldByName('ImportProcessors');
		if ( $importProcessors ) {
			$importProcessors->getConfig()->removeComponentsByType('GridFieldAddExistingAutocompleter');
			$importProcessors->getConfig()->removeComponentsByType('GridFieldAddNewButton');
			$addNewButton = new GridFieldAddNewButton('after');
			$addNewButton->setButtonName("Add Processor");
			$importProcessors->getConfig()->addComponent($addNewButton);
			$fields->removeFieldFromTab('Root', 'ImportProcessors');
			$fields->addFieldToTab('Root.Main', $importProcessors);
			if ( class_exists('GridFieldSortableRows') ) {
				$importProcessors->getConfig()->addComponent(new GridFieldSortableRows('Order'));
			} else if ( class_exists('GridFieldOrderableRows') ) {
				$importProcessors->getConfig()->addComponent(new GridFieldOrderableRows('Order'));
			}

		}

		return $fields;
	}

	public function requireDefaultRecords() {
		foreach(StaticSiteContentSource::get() as $source) {
			if(!$source->Schemas()->count()) {
				Debug::message("Making a schema for $source->ID");
				$defaultSchema = new StaticSiteContentSource_ImportSchema;
				$defaultSchema->Order = 1000000;
				$defaultSchema->AppliesTo = self::$default_applies_to;
				$defaultSchema->CssFilter = self::$default_css_filter;
				$defaultSchema->DataType = "Page";
				$defaultSchema->ContentSourceID = $source->ID;
				$defaultSchema->MimeTypes = "text/html";
				$defaultSchema->write();

				foreach(StaticSiteContentSource_ImportRule::get()->filter(array('SchemaID' => 0)) as $rule) {
					$rule->SchemaID = $defaultSchema->ID;
					$rule->write();
				}
			}
		}
	}

	/**
	 * Return the import rules in a format suitable for configuring StaticSiteContentExtractor.
	 *
	 * @return array A map of field name => array(CSS selector, CSS selector, ...)
	 */
	public function getImportRules() {
		$output = array();

		foreach($this->ImportRules() as $rule) {
			if(!isset($output[$rule->FieldName])) $output[$rule->FieldName] = array();
			$ruleArray = array(
				'selector' => $rule->CSSSelector,
				'attribute' => $rule->Attribute,
				'plaintext' => $rule->PlainText,
				// We can just use a long CSS rule with commas in it.
				//'excludeselectors' => preg_split('/\s+/', trim($rule->ExcludeCSSSelector)),
				'excludeselectors' => explode(',', trim($rule->ExcludeCSSSelector)),
				'outerhtml' => $rule->OuterHTML
			);
			$output[$rule->FieldName][] = $ruleArray;
		}

		return $output;
	}

	/**
	 *
	 * @return \ValidationResult
	 */
	public function validate() {
		$result = new ValidationResult;
		$mime = $this->validateMimes();
		if(!is_bool($mime)) {
			$result->error('Invalid mime-type "'.$mime.'" for DataType "'.$this->DataType.'"');
		}
		return $result;
	}

	/*
	 * Validate user-inputted mime-types until we use some sort of multi-select list in the CMS to select from (@todo).
	 * If we don't validate, then we can be haflway through an import and Upload#oad() wil throw a validation error "Extension is not allowed"
	 *
	 * @return mixed boolean|string Boolean true if all is OK, otherwise the invalid mimeType to be shown in the CMS UI
	 */
	public function validateMimes() {
		$selectedMimes = StaticSiteMimeProcessor::get_mimetypes_from_text($this->MimeTypes);
		$dt = $this->DataType ? $this->DataType : $_POST['DataType']; // @todo
		if(!$dt) {
			return true; // probably just creating
		}
		// This is v.sketchy as it relies on the name of the user-entered DataType having the string we want to match on in its classname = bad
		// prolly just replace this wih a regex..
		switch($dt) {
			case stristr($dt,'image') !== false:
				$type = 'image';
				break;
			case stristr($dt,'file') !== false:
				$type = 'file';
				break;
			case stristr($dt,'video') !== false:
				$type = 'file';
				break;
			case stristr($dt,'page') !== false:
			default:
				$type = 'sitetree';
				break;
		}

		$mimesForSSType = StaticSiteMimeProcessor::get_mime_for_ss_type($type);
		foreach($selectedMimes as $mime) {
			if(!in_array($mime,$mimesForSSType)) {
				return $mime;
			}
		}
		return true;
	}

	/**
	 * Returns whether this Schema will accept a given content item based on
	 * the item's HTML content and the Schema's CSS Filter rule.
	 * $item (StaticSiteContentItem)
	 */
	public function canParseItemHTML($item) {
		if (!strlen($this->CssFilter)) {
			return true;
		}
		$results = $item->getContentExtractor()->getPhpQuery()->find(
			$this->CssFilter
		);
		if ($results->length() > 0) {
			return true;
		}
		return false;
	}


}

/**
 * A single import rule that forms part of an ImportSchema
 */
class StaticSiteContentSource_ImportRule extends DataObject {
	public static $db = array(
		"FieldName" => "Varchar",
		"CSSSelector" => "Text",
		"ExcludeCSSSelector" => "Text",
		"Attribute" => "Varchar",
		"PlainText" => "Boolean",
		"OuterHTML" => "Boolean",
	);

	public static $summary_fields = array(
		"FieldName",
		"CSSSelector",
		"Attribute",
		"PlainText",
		"OuterHTML"
	);

	public static $field_labels = array(
		"FieldName" => "Field Name",
		"CSSSelector" => "CSS Selector",
		"Attribute" => "Element attribute",
		"PlainText" => "Convert to plain text",
		"OuterHTML" => "Use the outer HTML"
	);

	public static $has_one = array(
		"Schema" => "StaticSiteContentSource_ImportSchema",
	);

	public function getTitle() {
		return ($this->FieldName)?$this->FieldName:$this->ID;
	}

	public function getAbsoluteURL() {
		return ($this->URLSegment)?$this->URLSegment:$this->Filename;
	}

	/**
	 *
	 * @return FieldList
	 */
	public function getCMSFields() {
		$fields = parent::getCMSFields();

		$dataType = $this->Schema()->DataType;
		if($dataType) {
			$fieldList = singleton($dataType)->inheritedDatabaseFields();
			$fieldList = array_combine(array_keys($fieldList),array_keys($fieldList));
			unset($fieldList->ParentID);
			unset($fieldList->WorkflowDefinitionID);
			unset($fieldList->Version);

			$fieldNameField = new DropdownField("FieldName", "Field Name", $fieldList);
			$fieldNameField->setEmptyString("(choose)");
			$fields->insertBefore($fieldNameField, "CSSSelector");
		} else {
			$fields->replaceField('FieldName', $fieldName = new ReadonlyField("FieldName", "Field Name"));
			$fieldName->setDescription('Save this rule before being able to add a field name');
		}

		$fields->removeFieldByName('Order');

		return $fields;
	}
}


/**
 * Allows adding PHP processor functionality to an import
 */
class StaticSiteContentSource_ImportProcessor extends DataObject {

	private static $db = array(
		'Name' => 'Text',
		'Order' => 'Int',
	);

	private static $has_one = array(
		"Schema" => "StaticSiteContentSource_ImportSchema",
	);

	private static $default_sort = 'Order';

	public function getCMSFields() {
		$fields = new FieldList();

		$map = array();
		foreach( ClassInfo::implementorsOf('StaticSiteContentSource_ImportProcess') as $class ) {
			$map[$class] = $class;
		}

		$fields->push(
			new DropdownField('Name', 'Select a processor to apply:', $map)
		);		

		$fields->removeFieldByName('Order');

		return $fields;
	}

}

/**
 * The interface used to process an item.
 * Processing is run after the import rules have completed.
 * The $item has handy function getContentExtractor() which in turn has handy function getContent()
 * which will return the content of the HTML page; 
 */
interface StaticSiteContentSource_ImportProcess {

	// Inside your function, you can use eg:
	// $elements = $item->getContentExtractor()->getPhpQuery()->find('.css-selector');
	// // I find it easier to use for and eq() than foreach, as the elements remain phpQuery objects
	// for ( $i = 0; $i < $elements->size(); $i++ ) {
	//     $element = $elements->eq($i);
	//     $content = $object->Content;
	//     $content = phpQuery::newDocument('<div id="munged-content">'.$content.'</div>');
	//     // Note that we already replaced all preceding ones, so it's always eq(0)
	//     $content->find('#'.$element->attr('id'))->eq(0)->replaceWith("\n[shortcode id=".$element->attr('id')."]\n");
	//     $object->Content = $content->find('#munged-content')->html();
	// }
	// $object->write();
	// $object->publish('Stage', 'Live');
	public static function process(StaticSiteContentItem $item, DataObject $object, $parentObject, $duplicateStrategy);

}
