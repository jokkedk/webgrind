<?php
require 'Reader.php';
require 'Preprocessor.php';

/**
 * Class handling access to data-files(original and preprocessed) for webgrind.
 * @author Jacob Oettinger
 * @author Joakim Nygård
 */
class Webgrind_FileHandler{
	
	private static $singleton = null;
	
	
	/**
	 * @return Singleton instance of the filehandler
	 */
	public static function getInstance(){
		if(self::$singleton==null)
			self::$singleton = new self();
		return self::$singleton;
	}
		
	private function __construct(){
		// Get list of files matching the defined format
		$files = $this->getFiles(Webgrind_Config::$xdebugOutputFormat, Webgrind_Config::$xdebugOutputDir);
		
		// Get list of preprocessed files
		$prepFiles = $this->getFiles('/\\'.Webgrind_Config::$preprocessedSuffix.'$/', Webgrind_Config::$storageDir);
		
		// Loop over the preprocessed files. 
		foreach($prepFiles as $fileName=>$prepFile){
			$fileName = str_replace(Webgrind_Config::$preprocessedSuffix,'',$fileName);
			
			// If it is older than its corrosponding original: delete it.
			// If it's original does not exist: delete it
			if(!isset($files[$fileName]) || $files[$fileName]['mtime']>$prepFile['mtime'] )
				unlink($prepFile['absoluteFilename']);
			else
				$files[$fileName]['preprocessed'] = true;
		}
		// Sort by mtime
		uasort($files,array($this,'mtimeCmp'));
		
		$this->files = $files;
	}
	
	/**
	 * Get the value of the cmd header in $file
	 *
	 * @return void string
	 */	
	private function getInvokeUrl($file){
		// Grab name of invoked file. 
		// TODO: Makes assumptions about where the "cmd"-header is in a trace file. Not so cool, but a fast way to do it.
	    $fp = fopen($file, 'r');
	    fgets($fp);
	    $invokeUrl = trim(substr(fgets($fp), 5));
	    fclose($fp);
		return $invokeUrl;
	}
	
	/**
	 * List of files in $dir whose filename has the format $format
	 *
	 * @return array Files
	 */
	private function getFiles($format, $dir){
		$list = preg_grep($format,scandir($dir));
		$files = array();
		
		$scriptFilename = $_SERVER['SCRIPT_FILENAME'];
		
		foreach($list as $file){
			$absoluteFilename = $dir.$file;
			// Make sure that script never parses the profile currently being generated. (infinite loop)
			if(function_exists('xdebug_get_profiler_filename') && xdebug_get_profiler_filename()==$absoluteFilename)
				continue;
				
			$invokeUrl = $this->getInvokeUrl($absoluteFilename);

			$files[$file] = array('absoluteFilename'=>$absoluteFilename, 'mtime'=>filemtime($absoluteFilename), 'preprocessed'=>false, 'invokeUrl'=>$invokeUrl);
		}		
		return $files;
	}
	
	/**
	 * Get list of available trace files. Optionally including traces of the webgrind script it self
	 *
	 * @return array Files
	 */
	public function getTraceList(){
		$result = array();
		foreach($this->files as $fileName=>$file){
			$result[] = array('filename' => $fileName, 'invokeUrl' => str_replace($_SERVER['DOCUMENT_ROOT'].'/', '', $file['invokeUrl']));
		}
		return $result;
	}
	
	/**
	 * Get a trace reader for the specific file.
	 * 
	 * If the file has not been preprocessed yet this will be done first.
	 *
	 * @param string File to read
	 * @param Cost format for the reader
	 * @return Webgrind_Reader Reader for $file
	 */
	public function getTraceReader($file, $costFormat){
		$prepFile = Webgrind_Config::$storageDir.$file.Webgrind_Config::$preprocessedSuffix;
		try{
			$r = new Webgrind_Reader($prepFile, $costFormat);
		} catch (Exception $e){
			// Preprocessed file does not exist or other error
			Webgrind_Preprocessor::parse(Webgrind_Config::$xdebugOutputDir.$file, $prepFile);
			$r = new Webgrind_Reader($prepFile, $costFormat);
		}
		return $r;
	}
	
	/**
	 * Comparison function for sorting
	 *
	 * @return boolean
	 */
	private function mtimeCmp($a, $b){
		if ($a['mtime'] == $b['mtime'])
		    return 0;

		return ($a['mtime'] > $b['mtime']) ? -1 : 1;
	}
	
}