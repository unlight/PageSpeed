<?php if (!defined('APPLICATION')) exit();

$PluginInfo['PageSpeed'] = array(
	'Name' => 'Page Speed',
	'Description' => 'Minimizes payload size (compressing css/js files), minimizes round-trip times (loads JQuery library from CDN, combines external JavaScript/CSS files). Inspired by Google Page Speed rules. See readme for details.',
	'Version' => '1.53.2.0.17',
	'Date' => 'Summer 2011',
	'Author' => 'Nobody',
	'AuthorUrl' => 'https://github.com/search?type=Repositories&language=php&q=PageSpeed',
	'RequiredApplications' => array('Dashboard' => '>=2.0.17'),
	'RequiredTheme' => False, 
	'RequiredPlugins' => array('UsefulFunctions' => '>=2.3.60'),
	'RegisterPermissions' => False,
	'SettingsPermission' => False
);

// TODO: REMOVE WHEN 2.0.18
if (!function_exists('AbsoluteSource')) {
/**
* Takes a source path (ie. an image src from an html page), and an
* associated URL (ie. the page that the image appears on), and returns the
* absolute source (including url & protocol) path.
* @param string $SrcPath The source path to make absolute (if not absolute already).
* @param string $Url The full url to the page containing the src reference.
* @return string Absolute source path.
*/
	function AbsoluteSource($SrcPath, $Url) {
		// If there is a scheme in the srcpath already, just return it.
		if (!is_null(parse_url($SrcPath, PHP_URL_SCHEME))) return $SrcPath;
		
		// Does SrcPath assume root?
		if (in_array(substr($SrcPath, 0, 1), array('/', '\\')))
			return parse_url($Url, PHP_URL_SCHEME)
				.'://'
				.parse_url($Url, PHP_URL_HOST)
				.$SrcPath;
	
		// Work with the path in the url & the provided src path to backtrace if necessary
		$UrlPathParts = explode('/', str_replace('\\', '/', parse_url($Url, PHP_URL_PATH)));
		$SrcParts = explode('/', str_replace('\\', '/', $SrcPath));
		$Result = array();
		foreach ($SrcParts as $Part) {
			if (!$Part || $Part == '.') continue;
			if ($Part == '..') array_pop($UrlPathParts);
			else $Result[] = $Part;
		}
		// Put it all together & return
		return parse_url($Url, PHP_URL_SCHEME)
			.'://'
			.parse_url($Url, PHP_URL_HOST)
			.'/'.implode('/', array_filter(array_merge($UrlPathParts, $Result)));
	}
}

class PageSpeedPlugin implements Gdn_IPlugin {
	
	private $bRenderInitialized = False;
	private $Configuration = array();
	
	public function __construct() {
		$this->Configuration = C('Plugins.PageSpeed');
	}
	
	public function Base_Render_Before($Sender) {
		$EnablePostProcessing = GetValue('SetImageDimensions', $this->Configuration)
			&& $Sender->DeliveryMethod() == DELIVERY_METHOD_XHTML
			&& $Sender->DeliveryType() == DELIVERY_TYPE_ALL 
			&& $Sender->SyndicationMethod == SYNDICATION_NONE;
		if ($EnablePostProcessing) {
			ob_start();
			$this->bRenderInitialized = True;
		}
	}
	
	public function Base_Render_After($Sender) {
		if ($this->bRenderInitialized) {
			$String = ob_get_contents();
			ob_end_clean();
			self::StaticDomDocumentReplace($String);
			//$Gdn_Timer = new Gdn_Timer();
/*			$Gdn_Timer->Start();
			self::StaticDomDocumentReplace($String);
			$Gdn_Timer->Finish('self::StaticDomDocumentReplace($String);');*/
			/*$Gdn_Timer->Start();
			self::StaticPhpQueryReplace($String);
			$Gdn_Timer->Finish('self::StaticPhpQueryReplace($String);');*/
			echo $String;
		}
	}
	
	protected static function ChangeBackgroundUrl(&$CssText, $FilePath) {
		// Change background image url in css
		if (preg_match_all('/url\((.+?)\)/', $CssText, $Match)) {
			foreach($Match[1] as $N => $UrlImage) {
				$UrlImage = trim($UrlImage, '"\'');
				if ($UrlImage[0] == '/' || self::IsUrl($UrlImage) || substr($UrlImage, 0, 5) == 'data:') continue;
				$File = dirname($FilePath).'/'.$UrlImage;
				if (!file_exists($File)) {
					if (C('Debug')) trigger_error("Error while fix background image url path. No such file ($File).");
				}
				$Asset = Asset(substr($File, strlen(PATH_ROOT)+1));
				$CssText = str_replace($Match[0][$N], "url($Asset)", $CssText);
			}
		}
	}
	
	public function HeadModule_BeforeToString_Handler($Head) {

		$Configuration =& $this->Configuration;
		$Debug = C('Debug');
		if ($Debug && !GetValue('IgnoreDebug', $Configuration)) return;

		$Tags = $Head->Tags();
		usort($Tags, array('HeadModule', 'TagCmp')); // BeforeToString fires before sort
		
		$CombinedJavascript = array('library' => array());
		$CombinedCss = array();
		$RemoveIndex = array();
		
		$AllInOne = GetValue('AllInOne', $Configuration);
		
		foreach ($Tags as $Index => &$Tag) {
			// JavaScript (script tag)
			if (GetValue(HeadModule::TAG_KEY, $Tag) == 'script') {
				if (!isset($JsTag)) $JsTag = $Tag;
				$CachedFilePath = $this->GetCachedFilePath($Tag, 'src', $FilePath);
				if ($CachedFilePath === False) continue;
				
				if (!file_exists($CachedFilePath)) {
					if (!isset($Snoopy)) $Snoopy = Gdn::Factory('Snoopy');
					$Snoopy->Submit('http://marijnhaverbeke.nl/uglifyjs', array(
						'code_url' => '',
						'download' => '',
						'js_code' => file_get_contents($FilePath)
					));
					if ($Snoopy->error) {
						if ($Debug) trigger_error($Snoopy->error);
						$Snoopy->error = '';
						continue;
					}
					file_put_contents($CachedFilePath, $Snoopy->results);
				}
				if (!$AllInOne) {
					$GroupName = self::GetGroupName($FilePath);
					if ($GroupName == 'js' || $GroupName == 'themes') $GroupName = 'library';
					elseif (!in_array($GroupName, array('plugins', 'applications', 'library'))) {
						// Unknown group, move it to application group.
						$GroupName = 'applications';
					}
					$CombinedJavascript[$GroupName][$Index] = $CachedFilePath;
				} else {
					$CombinedJavascript[$Index] = $CachedFilePath;
				}
				
			} elseif (GetValue(HeadModule::TAG_KEY, $Tag) == 'link' && GetValue('rel', $Tag) == 'stylesheet') {

				$CachedFilePath = $this->GetCachedFilePath($Tag, 'href', $FilePath);
				if ($CachedFilePath === False) continue;

				if (!file_exists($CachedFilePath)) {
					$Css = file_get_contents($FilePath);
					$CssText = self::ProcessImportCssText($Css, $FilePath);
					if ($CssText === False) $CssText = $Css;
					if (GetValue('MinifyCss', $Configuration, True)) $CssText = self::MinifyCssText($CssText);
					self::ChangeBackgroundUrl($CssText, $FilePath);
					// TODO: COMBINE CSS (WE MUST CHECK MEDIA)
					// style.css + custom.css, admin.css + customadmin.css
					// TODO: MORE EFFECTIVE COMBINE colorbox.css + custom-colorbox.css
					file_put_contents($CachedFilePath, $CssText);
				}
				
				if (!$AllInOne) {
					$GroupName = self::GetGroupName($FilePath);
					// combine in two group applications and plugins
					// TODO: REMOVE if (!isset($CssTag)) $CssTag = $Tag;
					if (!isset($CssTag)) $CssTag = $Tag;
					if (!in_array($GroupName, array('plugins', 'applications'))) $GroupName = 'applications';
					$CombinedCss[$GroupName][$Index] = $CachedFilePath;
				} else {
					$CombinedCss[$Index] = $CachedFilePath;
				}
			}
		}
		
		if ($AllInOne) {
			unset($CombinedJavascript['library']);
			$RemoveIndex = array(array_keys($CombinedCss), array_keys($CombinedJavascript));
			// Css
			$CombinedCss = array_unique($CombinedCss);
			$CachedFilePath = 'cache/ps/style.' . self::HashSumFiles($CombinedCss) . '.css';
			if (!file_exists($CachedFilePath)) {
				$Combined = '';
				//$IncludeBasename = Gdn::Session()->CheckPermission('Garden.Admin.Only');
				foreach ($CombinedCss as $File) {
					//if ($IncludeBasename) $Combined .= '/*' . basename($File) . "*/\n";
					$Combined .= file_get_contents($File) . "\n";
				}
				file_put_contents($CachedFilePath, $Combined);
			}
			$Tags[] = array(
				HeadModule::TAG_KEY => 'link',
				'rel' => 'stylesheet',
				'type' => 'text/css',
				'href' => Asset($CachedFilePath, False, False),
				'media' => 'all',
				HeadModule::SORT_KEY => 98
			);

			// Js
			$CombinedJavascript = array_unique($CombinedJavascript);
			$CachedFilePath = 'cache/ps/functions.' . self::HashSumFiles($CombinedJavascript) . '.js';
			if (!file_exists($CachedFilePath)) {
				$Combined = '';
				foreach ($CombinedJavascript as $File) $Combined .= file_get_contents($File) . ";\n";
				file_put_contents($CachedFilePath, $Combined);
			}
		
			$Tags[] = array(
				HeadModule::TAG_KEY => 'script',
				'type' => 'text/javascript',
				'src' => Asset($CachedFilePath, False, False),
				//'_path' => $CachedFilePath,
				HeadModule::SORT_KEY => 99
			);

			//d($RemoveIndex, Flatten($RemoveIndex), @$CombinedCss, @$CombinedJavascript);
		} else {
			if (count($CombinedCss) > 0) {
				// TODO: array_unique
				foreach ($CombinedCss as $Group => $Files) {
					$RemoveIndex[] = array_keys($Files);
					$Files = array_unique($Files);
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
					// This is not works...
					// $CssTag['href'] = Asset($CachedFilePath);
					// $Tags[] = $CssTag;
					// This works...
					$Tags[] = array_merge($CssTag, array('href' => Asset($CachedFilePath, False, False)));
				}
			}
			// TODO: IF ONE FILE IN GROUP NO NEED TO PARSE/COMBINE IT
			if (count($CombinedJavascript) > 1) {
				// TODO: array_unique
				foreach ($CombinedJavascript as $Group => $Files) {
					$RemoveIndex[] = array_keys($Files);
					$Files = array_unique($Files);
					$Hash = self::HashSumFiles($Files);
					$CachedFilePath = "cache/ps/{$Group}.{$Hash}.js";
					if (!file_exists($CachedFilePath)) {
						$Combined = '';
						foreach ($Files as $Index => $File) {
							$Combined .= '//' . basename($File) . "\n" . file_get_contents($File) . ";\n";
						}
						file_put_contents($CachedFilePath, $Combined);
					}
					
					$JsTag['src'] = Asset($CachedFilePath, False, False);
					$JsTag[HeadModule::SORT_KEY] += 1;
					$Tags[] = $JsTag;
				}
			}
		}
		
		if (count($RemoveIndex) > 0) $Tags = self::RemoveKeyFromArray($Tags, Flatten($RemoveIndex));

		$Head->Tags($Tags);
	}
	
	public function Tick_Match_00_Minutes_05_Hours_1_Day_Handler() {
		$Directory = new RecursiveDirectoryIterator('cache/ps');
		foreach (new RecursiveIteratorIterator($Directory) as $File) {
			$CachedFile = $File->GetRealPath();
			unlink($CachedFile);
			Console::Message('Removed ^3%s', $CachedFile);
		}
	}
	
	public function Setup() {
		if (!is_dir('cache/ps')) mkdir('cache/ps', 0777, True);
		
	}
	
	protected function GetCachedFilePath(&$Tag, $FileKey, &$FilePath) {
		$Url =& $Tag[$FileKey];
		$FilePath = Null;
		if (self::IsUrl($Url)) return False;
		// Since 2.0.18 we have _path key.
		if (array_key_exists('_path', $Tag)) $FilePath = $Tag['_path'];
		else $FilePath = PATH_ROOT . parse_url($Url, PHP_URL_PATH);
		$Basename = pathinfo($FilePath, PATHINFO_BASENAME);
		// TODO: custom-
		// if (substr($Basename, 0, 6) == 'custom') d($Tags, $Basename);	
		switch ($Basename) {
			case 'jquery.js': {
				$Version = GetValueR('CDN.jquery', $this->Configuration, '1.4.2');
				$Url = 'http://ajax.googleapis.com/ajax/libs/jquery/'.$Version.'/jquery.min.js';
				return False;
			}
			//case 'jquery.ui.packed.js': 
			case 'jqueryui.js': {
				$Version = GetValueR('CDN.jqueryui', $this->Configuration, '1.7.1');
				$Url = 'http://ajax.googleapis.com/ajax/libs/jqueryui/'.$Version.'/jquery-ui.min.js';
				return False;
			}
			case 'jquery-ui.css': {
				$Version = GetValueR('CDN.jqueryui', $Configuration, '1.7.1');
				$Theme = GetValueR('CDN.jqueryui-theme', $Configuration, 'smoothness');
				$Url = 'http://ajax.googleapis.com/ajax/libs/jqueryui/'.$Version.'/themes/'.$Theme.'/jquery-ui.css';
				return False;
			}
			default: // Nothing
		}
		
		$Hash = sprintf('%u', crc32($FilePath.filemtime($FilePath)));
		$CachedFilePath = "cache/ps/{$Hash}.{$Basename}";
		return $CachedFilePath;
	}
	
	protected static function GetGroupName($FilePath) {
		static $WebRootLength;
		if (is_null($WebRootLength)) $WebRootLength = strlen(Gdn_Url::WebRoot());
		$GroupName = GetValue(1, explode('/', substr($FilePath, $WebRootLength)));
		return $GroupName;
	}
	
	protected static function StaticMinify($css) {
		//$css = str_replace('  ', ' ', $css);
		// credit: http://www.phpsnippets.info/compress-css-files-using-php
		/* remove comments */
		$css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
		/* remove tabs, spaces, newlines, etc. */
		//$css = str_replace(array("\r\n", "\r", "\n", "\t", '  ', '    ', '    '), '', $css);
		$css = preg_replace('/\s+/', ' ', $css); // my
		$css = str_replace( '; ', ';', $css );
		$css = str_replace( ': ', ':', $css );
		$css = str_replace( ' {', '{', $css );
		$css = str_replace( '{ ', '{', $css );
		$css = str_replace( ', ', ',', $css );
		$css = str_replace( '} ', '}', $css );
		$css = str_replace( ';}', "}\n", $css );
		$css = trim($css);
		// TODO: REMOVE EMPTY RULES
		return $css;
	}
	
	protected static function ProcessImportCssText($CssText = '', $Filepath) {
		if (!$CssText) $CssText = file_get_contents($Filepath);
		preg_match_all('/(@import\s+url\(((.+?)\)).*)/i', $CssText, $Match);
		if (!(isset($Match[3]) && count($Match[3]) > 0)) return False;
		$CssFiles = $Match[3];
		//$Url = Url('/', True);
		$DocRoot = Gdn::Request()->GetValue('DOCUMENT_ROOT');
		$Replace = array();
		foreach ($CssFiles as $N => $Filename) {
			$Filename = trim($Filename, '"\'');
			if ($Filename{0} == '/') {
				$ImportFilepath = PrefixString($DocRoot, $Filename);
			} else {
				// relative path
				$ImportFilepath = dirname($Filepath) . DS . $Filename;
			}			
			if (!file_exists($ImportFilepath)) trigger_error("File not found ($ImportFilepath)");
			$ImportedCss = file_get_contents($ImportFilepath);
			self::ChangeBackgroundUrl($ImportedCss, $ImportFilepath);
			$ImportMatch = $Match[0][$N];
			$Replace[$ImportMatch] = "\n".$ImportedCss;
		}
		
		$Result = str_replace(array_keys($Replace), array_values($Replace), $CssText);
		
		return $Result;
	}
	
	
	protected static function IsUrl($Url) {
		return (strpos($Url, '//') !== False);
	}
	
	protected static function MinifyCssFile($Filepath) {
		return self::MinifyCssText(file_get_contents($Filepath));
	}
	
	protected static function MinifyCssText($Text) {
		return self::StaticMinify($Text);
	}
	
	protected static function RemoveKeyFromArray($Array, $Keys) {
		// RemoveKeyFromArray in functions.general.php doesnt work as expected
		if (!is_array($Keys)) $Keys = array($Keys);
		if (is_array($Array)) foreach ($Keys as $Key) unset($Array[$Key]);
		return $Array;
	}
	
	protected static function HashSumFiles($Files) {
		$HashSum = array_sum(array_map('crc32', $Files));
		$NewHash = sprintf('%u', crc32($HashSum));
		return $NewHash;
	}
	
	/*
	* Loads/saves image dimension
	*/
	protected static function ImageDimensions($NewImageDimensions = Null) {
		$CacheFile = PATH_CACHE . '/image_dimensions.ini';
		if ($NewImageDimensions !== Null) {
			$PhpCode = "<?php\n\$_ = " . var_export($NewImageDimensions, True) . ';';
			return file_put_contents($CacheFile, $PhpCode);
		}
		$_ = array();
		if (file_exists($CacheFile)) include $CacheFile;
		return $_;
	}
	
	protected static function StaticPhpQueryReplace(&$String) {
		
		$ImageDimensions = self::ImageDimensions();
		$Domain = Gdn::Request()->Domain();
		
		$Doc = PqDocument($String, array('FixHtml' => False));
		foreach (Pq('img[src]') as $ImgNode) {
			$PqImg = Pq($ImgNode);
			if (!$PqImg->Attr('width') && !$PqImg->Attr('height')) {
				// TODO: DONT USE AbsoluteSource() for relative paths
				$Src = AbsoluteSource(trim($PqImg->Attr('src'), '/'), $Domain);
				$CrcKey = crc32($Src);
				if (!array_key_exists($CrcKey, $ImageDimensions)) {
					$ImageSize = getimagesize($Src);
					$ImageDimensions[$CrcKey] = array($ImageSize[0], $ImageSize[1], '_src' => $Src);
				} else {
					$ImageSize = $ImageDimensions[$CrcKey];
				}
				$PqImg->Attr('width', $ImageSize[0]);
				$PqImg->Attr('height', $ImageSize[1]);
			}
		}
		// Save cache.
		self::ImageDimensions($ImageDimensions);
		$String = $Doc->document->saveXML();
	}
	
	protected static function StaticDomDocumentReplace(&$String) {
		
		if (substr(ltrim($String), 0, 5) != '<?xml') $String = '<?xml version="1.0" encoding="UTF-8"?'. ">\n" . $String;
		$DOMDocument = DOMDocument::LoadXML($String, LIBXML_NOERROR);
		if ($DOMDocument === False) return $String;
		
		$ImageDimensions = self::ImageDimensions();
		$BeforeCount = count($ImageDimensions);
		$Domain = Gdn::Request()->Domain();

		$DomNodeList = $DOMDocument->GetElementsByTagName('img');
		for ($i = $DomNodeList->length - 1; $i >= 0; $i--) {
			$Node = $DomNodeList->Item($i);
			$Width = $Node->GetAttribute('width');
			$Height = $Node->GetAttribute('height');
			if ($Width == '' && $Height == '') {
				$Src = $Node->GetAttribute('src');
				if (!$Src) continue;
				$Src = AbsoluteSource($Src, $Domain);
				$CrcKey = crc32($Src);
				if (!array_key_exists($CrcKey, $ImageDimensions)) {
					$ImageSize = getimagesize($Src);
					$ImageDimensions[$CrcKey] = array($ImageSize[0], $ImageSize[1], '_src' => $Src);
				} else {
					$ImageSize = $ImageDimensions[$CrcKey];
				}
				if ($ImageSize[0] && $ImageSize[1]) {
					$Node->SetAttribute('width', $ImageSize[0]);
					$Node->SetAttribute('height', $ImageSize[1]);
				}
			}
		}
		// Save cache.
		if (count($ImageDimensions) != $BeforeCount) self::ImageDimensions($ImageDimensions);
		$String = $DOMDocument->saveXML(); // LIBXML_NOXMLDECL | LIBXML_NOENT 
		//$DOMDocument->formatOutput = True;
		//$String = $DOMDocument->saveXML(Null, LIBXML_NOXMLDECL | LIBXML_NOENT);
		//d($String);
	}
	
}






