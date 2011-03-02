<?php if (!defined('APPLICATION')) exit();

$PluginInfo['PageSpeed'] = array(
	'Name' => 'Page Speed',
	'Description' => 'Minimizes payload size (compressing css/js files), minimizes round-trip times (loads JQuery library from CDN, combines external JavaScript/CSS files). Inspired by Google Page Speed rules. See readme.txt for details.',
	'Version' => '1.2.12',
	'Date' => '2 Mar 2011',
	'Author' => 'S',
	'AuthorUrl' => 'http://www.google.com',
	'RequiredApplications' => False,
	'RequiredTheme' => False, 
	'RequiredPlugins' => array('PluginUtils' => '>=2.3.60'),
	'RegisterPermissions' => False,
	'SettingsPermission' => False
);

class PageSpeedPlugin implements Gdn_IPlugin {
	
	protected static function RemoveKeyFromArray($Array, $Keys) {
		// RemoveKeyFromArray in functions.general.php doesnt work as expected
		if (!is_array($Keys)) $Keys = array($Keys);
		if (is_array($Array)) foreach($Keys as $Key) unset($Array[$Key]);
		return $Array;
	}
	
	public function HeadModule_BeforeToString_Handler($Head) {
		
		$Configuration = C('Plugins.PageSpeed');

		if (defined('DEBUG') && !GetValue('IgnoreDebug', $Configuration)) return;
		
		$CombinedJavascript = array('library' => array());
		$CombinedCss = array();
		$RemoveIndex = array();
		
		$Tags = $Head->Tags();
		
		foreach ($Tags as $Index => &$Tag) {
			// JavaScript (script tag)
			if (GetValue(HeadModule::TAG_KEY, $Tag) == 'script') {
				if (!isset($JsTag)) $JsTag = $Tag;
				
				$Src =& $Tag['src']; 
				if (self::IsUrl($Src)) continue;
				$Path = parse_url($Src, PHP_URL_PATH);
				$Basename = pathinfo($Path, PATHINFO_BASENAME);
				if ($Basename == 'jquery.js') {
					//if (Gdn_Statistics::IsLocalhost()) continue; // TODO: CONF TO DISABLE
					$Src = 'http://ajax.googleapis.com/ajax/libs/jquery/1.4.4/jquery.min.js';
					continue;
				}
				
				$FilePath = PATH_ROOT.$Path; // MAYBE USE $_SERVER['DOCUMENT_ROOT']?
				if (!file_exists($FilePath)) trigger_error("No such file ($FilePath)");
				$Hash = Crc32Value(md5_file($FilePath), filemtime($FilePath));
				$CachedFilePath = "cache/ps/{$Hash}.$Basename";
				
				if (!file_exists($CachedFilePath)) {
					if (!isset($Snoopy)) $Snoopy = Gdn::Factory('Snoopy');
					$Snoopy->Submit('http://marijnhaverbeke.nl/uglifyjs', array(
						'code_url' => '',
						'download' => '',
						'js_code' => file_get_contents($FilePath)
					));
					if ($Snoopy->error) {
						trigger_error($Snoopy->error);
						$Snoopy->error = Null;
						continue;
					}
					file_put_contents($CachedFilePath, $Snoopy->results);
				}
				
				$GroupName = self::GetGroupName($Path);
				if ($GroupName == 'js' || 
					in_array($Basename, array('global.js', 'queue.js', 'slice.js'))) $GroupName = 'library';
				elseif ($GroupName == 'themes') $GroupName = 'plugins';
				elseif (!in_array($GroupName, array('plugins', 'applications', 'library')))
					$GroupName = 'applications'; // Unknown group, move it to application group
				$CombinedJavascript[$GroupName][$Index] = $CachedFilePath;
				
			} elseif (GetValue(HeadModule::TAG_KEY, $Tag) == 'link' && GetValue('rel', $Tag) == 'stylesheet') {
				// Css (link/stylesheet tag)
				// We must save to same directory becase url(relativepath)
				// You can cleanup cached files by running script

				$Href =& $Tag['href'];
				if (self::IsUrl($Href)) continue;
				
				$Path = parse_url($Href, PHP_URL_PATH);
				$Basename = pathinfo($Path, PATHINFO_BASENAME);
				
				$FilePath = PATH_ROOT.$Path;
				if (!file_exists($FilePath)) trigger_error("No such file ($FilePath)");
				$Hash = Crc32Value(md5_file($FilePath), filemtime($FilePath));
				
				
				$CachedFilePath = "cache/ps/{$Hash}.$Basename";
				// OLD
				//$Filename = pathinfo($Basename, PATHINFO_FILENAME);
				//if (substr($Filename, 0, 2) != '__') $Filename = '__'.$Filename;
				//$CachedFilePath = dirname($FilePath)."/{$Filename}-c-{$Hash}.css";
				
				if (!file_exists($CachedFilePath)) {
					$Css = file_get_contents($FilePath);
					$CssText = self::ProcessImportCssText($Css, $FilePath);
					if ($CssText === False) $CssText = $Css;
					if (GetValue('MinifyCss', $Configuration, True)) $CssText = self::MinifyCssText($CssText);
					
					// Check background image url
					if (preg_match_all('/url\((.+?)\)/', $CssText, $Match)) {
						foreach($Match[1] as $N => $UrlImage) {
							$UrlImage = trim($UrlImage, '"\'');
							if ($UrlImage[0] == '/' || self::IsUrl($UrlImage)) continue;
							$File = dirname($FilePath).'/'.$UrlImage;
							$ImageFilepath = realpath($File);
							if (!$ImageFilepath) trigger_error("Error while fix background image url path. No such file ($File).");
							$Asset = Asset(substr($ImageFilepath, strlen(PATH_ROOT)+1));
							$CssText = str_replace($Match[0][$N], "url($Asset)", $CssText);
						}
					}
					
					// TODO: COMBINE CSS (WE MUST CHECK MEDIA)
					// style.css + custom.css, admin.css + customadmin.css
					// TODO: MORE EFFECTIVE COMBINE colorbox.css + custom-colorbox.css
					file_put_contents($CachedFilePath, $CssText);
				}
				
				$GroupName = self::GetGroupName($Path);
				// combine in two group applications and plugins
				if (GetValue('media', $Tag) == 'screen') {
					if (!isset($CssTag)) $CssTag = $Tag;
					if (!in_array($GroupName, array('plugins', 'applications'))) 
						$GroupName = 'applications';
					$CombinedCss[$GroupName][$Index] = $CachedFilePath;
				} else 
					$Href = Asset($CachedFilePath);
				
				// OLD
				//$Href = Asset(substr($CachedFilePath, strlen(PATH_ROOT)+1));
				
				
			} else continue;
			
		}
		
		if (count($CombinedCss) > 0) {
			foreach ($CombinedCss as $Group => $Files) {
				$RemoveIndex[] = array_keys($Files);
				$Files = array_values(array_unique($Files));
				$Hash = Crc32Value($Files);
				$CachedFilePath = "cache/ps/combined.{$Group}.{$Hash}.css";
				if (!file_exists($CachedFilePath)) {
					$Combined = '';
					foreach ($Files as $Index => $File) {
						$Combined .= '/*' . basename($File) . "*/\n" . file_get_contents($File) . "\n";
					}
					file_put_contents($CachedFilePath, $Combined);
				}
				$CssTag[HeadModule::SORT_KEY] += 1;
				$Tags[] = array_merge($CssTag, array('href' => Asset($CachedFilePath)));
			}
			
		}
		
		if (count($CombinedJavascript) > 1) {
			foreach ($CombinedJavascript as $Group => $Files) {
				$RemoveIndex[] = array_keys($Files);
				$Files = array_values(array_unique($Files));
				$Hash = Crc32Value($Files);
				$CachedFilePath = "cache/ps/combined.{$Group}.{$Hash}.js";
				if (!file_exists($CachedFilePath)) {
					$Combined = '';
					foreach ($Files as $Index => $File) {
						$Combined .= '//' . basename($File) . "\n" . file_get_contents($File) . ";\n";
					}
					file_put_contents($CachedFilePath, $Combined);
				}
				
				$JsTag['src'] = Asset($CachedFilePath);
				$JsTag[HeadModule::SORT_KEY] += 1;
				$Tags[] = $JsTag;
			}
		}
		
		if (count($RemoveIndex) > 0) $Tags = self::RemoveKeyFromArray($Tags, Flatten($RemoveIndex));

		$Head->Tags($Tags);
	}
	
	protected static function GetGroupName($Path) {
		static $WebRootLength;
		if (is_null($WebRootLength)) $WebRootLength = strlen(Gdn_Url::WebRoot());
		// TODO: FIX FOR DIRECTORY
		$GroupName = GetValue(1, explode('/', substr($Path, $WebRootLength)));
		return $GroupName;
	}
	
	protected static function MinifyCssFile($Filepath) {
		return self::MinifyCssText(file_get_contents($Filepath));
	}
	
	protected static function MinifyCssText($Text) {
		return self::StaticMinify($Text);
	}
	
	protected static function StaticMinify($Css) {
		# credit: http://www.lateralcode.com/css-minifier/
		$Css = preg_replace('#\s+#', ' ', $Css);
		$Css = preg_replace('#/\*.*?\*/#s', '', $Css);
		$Css = preg_replace('#([;:{},]) #', '\1', $Css);
	
		$Replace = array(
			' {' => '{',
			';}' => '}',
			' 0px' => ' 0',
			':0px' => ':0',
			' !important' => '!important',
		);
		if (defined('DEBUG')) $Replace['}'] = "}\n";
		
		$Css = str_replace(array_keys($Replace), array_values($Replace), $Css);
		return trim($Css);
	}
	
	protected static function ProcessImportCssText($CssText = '', $Filepath) {
		if (!$CssText) $CssText = file_get_contents($Filepath);
		preg_match_all('/(@import\s+url\(((.+?)\)).*)/i', $CssText, $Match);
		if (!(isset($Match[3]) && count($Match[3]) > 0)) return False;
		$CssFiles = $Match[3];
		$Replace = array();
		foreach ($CssFiles as $N => $Filename) {
			$Filename = trim($Filename, '"\'');
			if (strpos($Filename, '/') === False) {
				// relative path
				$ImportFilepath = dirname($Filepath) . DS . $Filename;
				if (!file_exists($ImportFilepath)) trigger_error("No such file ($ImportFilepath)");
				$ImportMatch = $Match[0][$N];
				$Replace[$ImportMatch] = "\n".file_get_contents($ImportFilepath);
			} else {
				if (defined('DEBUG')) trigger_error("FIX ME ($Filename).");
				return $CssText;
			}
		}
		
		$Result = str_replace(array_keys($Replace), array_values($Replace), $CssText);
		
		return $Result;
	}
	
	
	protected static function IsUrl($Url) {
		return (strpos($Url, '//') !== False);
	}
	
	public function Setup() {
		if (!is_dir('cache/ps')) mkdir('cache/ps', 0777, True);
		
	}
}





















