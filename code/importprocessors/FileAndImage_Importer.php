<?php

class FileAndImage_Importer implements StaticSiteContentSource_ImportProcess {
	
	public static function process(StaticSiteContentItem $item, DataObject $object, $parentObject, $duplicateStrategy) {
		// Download any images embedded in the content area
		self::processImages($item, $object, $parentObject, $duplicateStrategy);
		// Download any files linked to in the content area
		self::processFiles($item, $object, $parentObject, $duplicateStrategy); 
	}

	// Import images in content
	protected static function processImages(StaticSiteContentItem $item, DataObject $object, $parentObject, $duplicateStrategy) {
		$utils = singleton('StaticSiteUtils');

		$imgs = $item->getContentExtractor()->getPhpQuery()->find("#content-area img");
		if ( $imgs->size() == 0 ) {
			// No image
			return;
		}

		for ( $i = 0; $i < $imgs->size(); $i++ ) {
			$img = $imgs->eq($i);
			$imageRelativeUrl = trim($img->eq(0)->attr('src'));
			if (!strlen($imageRelativeUrl)) {
				continue; // empty image - it does happen
			}
			$imageAbsoluteUrl = Controller::join_links(
				$item->getSource()->BaseUrl,
				$imageRelativeUrl
			);
			$image = File::get()->filter('StaticSiteURL', $imageAbsoluteUrl)->First();
			if ( !$image ) {
				// We need to import the item as it wasn't caught by the crawler.
				$mimeType = HTTP::get_mime_type($imageAbsoluteUrl);
				$utils->log('Importing image file '.$imageAbsoluteUrl.' with MIME type '.$mimeType);
				try {
					// Add the URL to the crawl list
					$item->getSource()->urlList()->addAbsoluteURL($imageAbsoluteUrl, 'image/jpeg', sha1($imageAbsoluteUrl));
					// Set up the image to import
					$importImage = $item->getSource()->getObject($imageRelativeUrl);
				} catch (Exception $e) {
					// Usually means "not in URL list"
					continue;
				}
				$transformer = new StaticSiteFileTransformer;
				$result = $transformer->transform(
					$importImage,
					$parentObject,
					$duplicateStrategy
				);
				$image = File::get()->filter('StaticSiteURL', $imageAbsoluteUrl)->First();
				if ( !$image ) {
					$utils->log('Failed :-(');
						return;
				}
				$utils->log('Imported image - File:'.$image->ID);
			} else {
				$utils->log('Found image - File:'.$image->ID);
			}
			// Change the image tag to point to our internal image
			$img->attr('src', $image->Filename);
		}
	}

	// Import files in content
	protected static function processFiles(StaticSiteContentItem $item, DataObject $object, $parentObject, $duplicateStrategy) {
		$utils = singleton('StaticSiteUtils');

		$files = $item->getContentExtractor()->getPhpQuery()->find("#content-area a[href*=\".\"]");
		if ( $files->size() == 0 ) {
			// No image
			return;
		}

		for ( $i = 0; $i < $files->size(); $i++ ) {
			$f = $files->eq($i);
			// Turn into an absolute URL
			$fileRelativeUrl = trim($f->eq(0)->attr('href'));
			if (!strlen($fileRelativeUrl)) {
				continue; // empty link - it does happen
			}
			$fileAbsoluteUrl = Director::is_absolute_url($fileRelativeUrl) ?
				$fileRelativeUrl :
				Controller::join_links(
					$item->getSource()->BaseUrl,
					$fileRelativeUrl
				);
			// Is this on the site's domain?
			if ( strpos($item->getSource()->BaseUrl, $fileAbsoluteUrl) === false ) {
				continue;
			}
			// Is this a file with an extension?
			if ( pathinfo($fileAbsoluteUrl, PATHINFO_EXTENSION) === '' ) {
				continue;
			}
			$file = File::get()->filter('StaticSiteURL', $fileAbsoluteUrl)->First();
			if ( !$file ) {
				// We need to import the item as it wasn't caught by the crawler.
				$mimeType = HTTP::get_mime_type($fileAbsoluteUrl);
				$utils->log('Importing linked file '.$fileAbsoluteUrl.' with MIME type '.$mimeType);
				try {
					// Add the URL to the crawl list
					$item->getSource()->urlList()->addAbsoluteURL($fileAbsoluteUrl, $mimeType, sha1($fileAbsoluteUrl));
					// Set up the file to import
					$importFile = $item->getSource()->getObject($fileRelativeUrl);
				} catch (Exception $e) {
					// Usually means "not in URL list"
					continue;
				}
				$transformer = new StaticSiteFileTransformer;
				$result = $transformer->transform(
					$importFile,
					$parentObject,
					$duplicateStrategy
				);
				$file = File::get()->filter('StaticSiteURL', $fileAbsoluteUrl)->First();
				if ( !$file ) {
					$utils->log('Failed :-(');
						return;
				}
				$utils->log('Imported linked file - File:'.$image->ID);
			} else {
				$utils->log('Found linked file - File:'.$image->ID);
			}
			$f->attr('href', $file->Filename);
		}
	}

}
