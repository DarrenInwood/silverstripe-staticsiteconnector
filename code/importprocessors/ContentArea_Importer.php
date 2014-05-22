<?php

// Imports whatever is left in the #content-area after other processors have run
// into the Content field

class ContentArea_Importer implements StaticSiteContentSource_ImportProcess {
	
	public static function process(StaticSiteContentItem $item, DataObject $object, $parentObject, $duplicateStrategy) {
		$utils = singleton('StaticSiteUtils');

		$contentArea = $item->getContentExtractor()->getPhpQuery()->find("#content-area");

		// Clean up - replace out double BR tags with P tags
		$contentHtml = $contentArea->html();
		$contentHtml = '<p>'.preg_replace('#(?:<br\s*/?>\s*?){2,}#', '</p><p>', $contentHtml).'</p>';
		$contentArea->html($contentHtml);

		// Remove any leftover BR or HR tags
		$contentArea->find('br')->remove();
		$contentArea->find('hr')->remove();

		$contentHtml = $contentArea->html();

		$utils->log('Content set by ContentArea_Importer: length='.strlen($contentHtml));

		$object->Content = $contentHtml;
	}

}