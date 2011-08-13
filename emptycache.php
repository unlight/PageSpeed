<?php
require_once implode(DIRECTORY_SEPARATOR, array(dirname(__FILE__), '..', '..', 'plugins', 'UsefulFunctions', 'bootstrap.console.php'));

$Directory = new RecursiveDirectoryIterator('cache/ps');
foreach(new RecursiveIteratorIterator($Directory) as $File) {
	$CachedFile = $File->GetPathName();
	unlink($CachedFile);
	Console::Message('Removed ^3%s', $CachedFile);
}

// + DtCss
$DirectoryAry = array(PATH_APPLICATIONS, PATH_PLUGINS, PATH_THEMES);
foreach($DirectoryAry as $DirectoryPath) {
	$Directory = new RecursiveDirectoryIterator($DirectoryPath);
	foreach(new RecursiveIteratorIterator($Directory) as $File){
		$Basename = $File->GetBasename();
		$Extension = pathinfo($Basename, 4);
		$Filename = pathinfo($Basename, 8);
		if ($Extension != 'css') continue;
		if (!preg_match('/^[\.\w\-]+\-c\-[a-z0-9]{5,7}$/', $Filename)) continue;
		$CachedFile = $File->GetPathName();
		unlink($CachedFile);
		Console::Message('Removed ^3%s', $CachedFile);
	}
}