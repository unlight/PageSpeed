<?php if (!defined('APPLICATION')) exit();

$PluginInfo['PageSpeed'] = array(
	'Name' => 'Page Speed',
	'Description' => 'Minimizes payload size (compressing css/js files), minimizes round-trip times (loads JQuery library from CDN, combines external JavaScript/CSS files). Inspired by Google Page Speed rules. See readme.txt for details.',
	'Version' => '1.3.15',
	'Date' => '18 Mar 2011',
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
	
	protected static function HashSumFiles($Files) {
		$Files = array_unique($Files);
		$HashSum = array_sum(array_map('crc32', $Files));
		$NewHash = sprintf('%u', crc32($HashSum));
		return $NewHash;
	}
	
	public function HeadModule_BeforeToString_Handler($Head) {
		
		$Configuration = C('Plugins.PageSpeed');

		if (defined('DEBUG') && !GetValue('IgnoreDebug', $Configuration)) return;
		
		$CombinedJavascript = array('library' => array());
		$CombinedCss = array();
		$RemoveIndex = array();
		
		$Tags = $Head->Tags();
		usort($Tags, array('HeadModule', 'TagCmp')); // BeforeToString fires before sort
		
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
				// TODO: Add news library js (v2.1.0)
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
							if ($UrlImage[0] == '/' || self::IsUrl($UrlImage) || substr($UrlImage, 0, 5) == 'data:') continue;
							$File = dirname($FilePath).'/'.$UrlImage;
							if (!file_exists($File)) trigger_error("Error while fix background image url path. No such file ($File).");
							$Asset = Asset(substr($File, strlen(PATH_ROOT)+1));
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
				// TODO: ATTENTION! "screen" changed to "all" in unstable
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
				$Hash = self::HashSumFiles($Files);
				$CachedFilePath = "cache/ps/{$Group}.{$Hash}.css";
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
		// TODO: IF ONE FILE IN GROUP NO NEED TO PARSE/COMBINE IT
		if (count($CombinedJavascript) > 1) {
			foreach ($CombinedJavascript as $Group => $Files) {
				$RemoveIndex[] = array_keys($Files);
				$Hash = self::HashSumFiles($Files);
				$CachedFilePath = "cache/ps/{$Group}.{$Hash}.js";
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
	
	protected static function StaticMinify($css) {
		// creadit: http://www.phpsnippets.info/compress-css-files-using-php
		/* remove comments */
		$css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
		/* remove tabs, spaces, newlines, etc. */
		$css = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $css);
		$css = str_replace( '; ', ';', $css );
		$css = str_replace( ': ', ':', $css );
		$css = str_replace( ' {', '{', $css );
		$css = str_replace( '{ ', '{', $css );
		$css = str_replace( ', ', ',', $css );
		$css = str_replace( '} ', '}', $css );
		$css = str_replace( ';}', "}\n", $css );
		$css = trim($css);
		return $css;

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
	
	protected static function MinifyCssFile($Filepath) {
		return self::MinifyCssText(file_get_contents($Filepath));
	}
	
	protected static function MinifyCssText($Text) {
		return self::StaticMinify($Text);
	}
}





















