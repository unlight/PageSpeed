<?php
require_once dirname(__FILE__) . '/../../plugins/UsefulFunctions/bootstrap.console.php';

$Directory = new RecursiveDirectoryIterator('cache/ps');
foreach(new RecursiveIteratorIterator($Directory) as $File) {
	$CachedFile = $File->GetPathName();
	unlink($CachedFile);
	Console::Message('Removed ^3%s', $CachedFile);
}
