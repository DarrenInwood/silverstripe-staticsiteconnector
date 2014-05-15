<?php
/*
 * URL transformer specific to SilverStripe's `File` object for use within the import functionality.
 *
 * This both creates SilverStripe's database representation of the fetched-file and also creates a copy of the file itself
 * on the local filesystem.
 *
 * @see {@link StaticSitePageTransformer}
 */
class StaticSiteFileTransformer implements ExternalContentTransformer {

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

	/**
	 * @var Object
	 *
	 * $mimeTypeProcessor
	 */
	public $mimeProcessor;

	public function __construct() {
		$this->utils = singleton('StaticSiteUtils');
		$this->mimeProcessor = singleton('StaticSiteMimeProcessor');
	}

	/**
	 *
	 * @param type $item
	 * @param type $parentObject
	 * @param type $duplicateStrategy
	 * @return boolean|\StaticSiteTransformResult
	 * @throws Exception
	 */
	public function transform($item, $parentObject, $duplicateStrategy) {
		$this->utils->log("START transform for: ",$item->AbsoluteURL, $item->ProcessedMIME);

		$item->runChecks('file');
		if($item->checkStatus['ok'] !== true) {
			$this->utils->log($item->checkStatus['msg']." for: ",$item->AbsoluteURL, $item->ProcessedMIME);
			return false;
		}

		$source = $item->getSource();

		// Cleanup StaticSiteURLs
		//$this->utils->resetStaticSiteURLs($item->AbsoluteURL, $source->ID, 'File');

		// Sleep for 10ms to reduce load on the remote server
		usleep(10*1000);

		// Extract remote location of File
		// Also sets $this->tmpName for use in this->writeToFs()
		$contentFields = $this->getContentFieldsAndSelectors($item);

		// Default value for Title
		if(empty($contentFields['Filename'])) {
			$contentFields['Filename'] = array('content' => $item->externalId);
		}

		$schema = $source->getSchemaForURL($item->AbsoluteURL, $item->ProcessedMIME);
		if(!$schema) {
			$this->utils->log("Couldn't find an import schema for: ",$item->AbsoluteURL,$item->ProcessedMIME);
			return false;
		}

		// @todo need to create the filter on schema on a mime-by-mime basis
		$dataType = $schema->DataType;
		$this->utils->log("Matched ".$schema->AppliesTo." - using data type '".$dataType."'");

		if(!$dataType) {
			$this->utils->log("DataType for migration schema is empty for: ",$item->AbsoluteURL,$item->ProcessedMIME);
			throw new Exception('DataType for migration schema is empty!');
		}

		// Find the folder we're importing into.  Falls back to ASSETS_DIR
		$rootFolderID = (int) Controller::curr()->getRequest()
			->requestVar('FileMigrationTarget');
		if ( $rootFolderID == 0 ) {
			$rootFolderPath = ASSETS_DIR;
		} else {
			$rootFolderPath = rtrim(File::get()->byID($rootFolderID)->getRelativePath(), '/');
		}
		$this->utils->log("Root folder: '$rootFolderPath'");

		// Look for an already-imported file via static file URL first
		$file = File::get()->filter('StaticSiteURL', $item->AbsoluteURL)->first();
		if ( $file ) {
			// We found an existing file
			if( $duplicateStrategy === 'Overwrite' ) {
				$this->utils->log("Found duplicate file: ".$file->Filename);
				if( get_class($file) !== $dataType ) {
					$file->ClassName = $dataType;
				}
			} elseif( $duplicateStrategy === 'Skip' ) {
				$this->utils->log("Skipped duplicate file: ".$file->Filename);
				return false;
			}
		} else {
			// If not, check for an existing folder with the same name inside the target directory.
			// This is required for sites that return 404 pages for folders above where their files are kept;
			// first the crawl will find the filename from other content, then it will scan containing folders
			// and list their URLs in the url list.  We may already have created the folders when
			// we imported the file.			
			$filepath = $rootFolderPath . parse_url($item->AbsoluteURL, PHP_URL_PATH);
			$folder = Folder::get()->filter('Filename', $filepath);
			if ( $folder->Count() > 0 ) {
				// It's an existing folder
				$this->utils->log("This URL is actually a folder: ".$folder->Filename);
				return false;
			}

			// We didn't find a file already imported, and it's not an already-created folder.
			// Create the file.

			// @todo
			// - Do we really want to rely on user-input to ascertain the correct container class?
			// - Should it be detected based on Mime-Type(s) first and if none found, _then_ default to user-input?
			$file = new $dataType(array());
		}

		$isImage = $this->mimeProcessor->IsOfImage($item->ProcessedMIME);
		$filename = parse_url($item->AbsoluteURL);

		// Use the original folder path inside our selected folder
		// Found what we submitted, remove the base assets path ASSETS_DIR (generally 'assets')
		$path = Controller::join_links(
			str_replace(ASSETS_DIR, '', $rootFolderPath),
			dirname($filename['path'])
		);
		$path = trim($path, '/');
		$parentFolder = Folder::find_or_make($path);
		$this->utils->log("Created folder: '$path'");

		$file->Filename = $path . DIRECTORY_SEPARATOR . basename($filename['path']);
		$file->Name = basename($filename['path']);
		$file->ParentID = $parentFolder->ID;

		$this->utils->log("Wrote file: '".$file->Filename."'");

		try {
			$this->write($file, $item, $source, $contentFields['tmp_path']);
		} catch ( Exception $e ) {
			// guzzle can throw 404 exceptions
			return false;
		}

		$this->utils->log("END transform for: ",$item->AbsoluteURL, $item->ProcessedMIME);

		return new StaticSiteTransformResult($file, $item->stageChildren());
	}

	/**
	 *
	 * @param File $file
	 * @param StaticSiteContentItem $item
	 * @param StaticSiteContentSource $source
	 * @param string $tmpPath
	 * @return boolean
	 */
	public function write(File $file, $item, $source, $tmpPath) {
		$file->StaticSiteContentSourceID = $source->ID;
		$file->StaticSiteURL = $item->AbsoluteURL;

		if(!$file->write()) {
			$uploadedFileMsg = "!! {$item->AbsoluteURL} not imported";
			$this->utils->log($uploadedFileMsg , $file->Filename, $item->ProcessedMIME);
			return false;
		}

		rename($tmpPath, BASE_PATH . DIRECTORY_SEPARATOR . $file->Filename);
	}

	/**
	 * Get content from the remote file if $item->AbsoluteURL represents a File-ish object
	 *
	 * @param  StaticSiteContentItem $item The item to extract
	 * @return array A map of field name => array('selector' => selector, 'content' => field content) etc
	 */
	public function getContentFieldsAndSelectors($item) {
		// Get the import rules from the content source
		$importSchema = $item->getSource()->getSchemaForURL($item->AbsoluteURL,$item->ProcessedMIME);
		if(!$importSchema) {
			$this->utils->log("Couldn't find an import schema for ",$item->AbsoluteURL,$item->ProcessedMIME,'WARNING');
			return null;
		}
		$importRules = $importSchema->getImportRules();

 		// Extract from the remote file based on those rules
		$contentExtractor = new StaticSiteContentExtractor($item->AbsoluteURL,$item->ProcessedMIME);
		$extraction = $contentExtractor->extractMapAndSelectors($importRules,$item);
		$extraction['tmp_path'] = $contentExtractor->getTmpFileName();
		return $extraction;
	}
}