<?php
ini_set('error_log', dirname(__FILE__).'/error.log');
if (ini_get('date.timezone') == '') ini_set('date.timezone', 'Europe/Moscow');

define('PATH_ROOT', realpath(dirname(__FILE__).'/../..'));

$Path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$FilePath = PATH_ROOT . '/' . trim($Path, '/');
if (!file_exists($FilePath)) trigger_error("No such file ($FilePath).");


$Extension = pathinfo($Path, PATHINFO_EXTENSION);
switch ($Extension) {
	case 'js': $ContentType = 'text/javascript'; break; // application/x-javascript
	case 'css': $ContentType = 'text/css'; break;
	default: trigger_error("Unknown file type ($Filename/$Extension)"); exit();
}

# http://viralpatel.net/blogs/2009/02/compress-php-css-js-javascript-optimize-website-performance.html
if (!ob_start("ob_gzhandler")) ob_start();


header("Content-Type: {$ContentType}; charset=utf-8");
header('Cache-Control: must-revalidate');
$Offset = '+7 days';
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', strtotime($Offset)));


/* =========================================================
If no deflate_module, add following lines in your htaccess file to enable compression for CSS and JS files:
<FilesMatch "\.(css|js)$">
	ForceType application/x-httpd-php
	php_value auto_prepend_file "/absolute/path/to/gzip.handler.php"
</FilesMatch>


*/







