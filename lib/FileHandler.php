<?php
require 'Reader.php';
require 'Preprocessor.php';

class Webgrind_FileHandler{
	private static $singleton = null;
	
	public static function getInstance(){
		if(self::$singleton==null)
			self::$singleton = new self();
		return self::$singleton;
	}
		
	private function __construct(){
		
		$files = $this->getFiles(Config::$xdebugOutputFormat, Config::$xdebugOutputDir);
		
		$prepFiles = $this->getFiles('/\\'.Config::$preprocessedSuffix.'$/', Config::$storageDir);
		foreach($prepFiles as $fileName=>$prepFile){
			$fileName = str_replace(Config::$preprocessedSuffix,'',$fileName);
			
			if(!isset($files[$fileName]) || $files[$fileName]['mtime']>$prepFile['mtime'] )
				unlink($prepFile['absoluteFilename']);
			else
				$files[$fileName]['preprocessed'] = true;
		}
		uasort($files,array($this,'mtimeCmp'));
		$this->files = $files;
	}
	
	
	public function getInvokeUrl($file){
		# Grab name of invoked file
	    $fp = fopen($file, 'r');
	    fgets($fp);
	    $invokeUrl = trim(substr(fgets($fp), 5));
	    fclose($fp);
		return $invokeUrl;
	}
	
	
	private function getFiles($format, $dir){
		$list = preg_grep($format,scandir($dir));
		$files = array();
		
		$scriptFilename = $_SERVER['SCRIPT_FILENAME'];
		
		foreach($list as $file){
			$absoluteFilename = $dir.$file;
			$invokeUrl = $this->getInvokeUrl($absoluteFilename);

			$files[$file] = array('absoluteFilename'=>$absoluteFilename, 'mtime'=>filemtime($absoluteFilename), 'preprocessed'=>false, 'invokeUrl'=>$invokeUrl, 'selftrace'=>false);
			if($invokeUrl == $scriptFilename)
				$files[$file]['selftrace'] = true;
			
		}		
		return $files;
	}
	
	public function getTraceList($selftraces=false){
		$result = array();
		foreach($this->files as $fileName=>$file){
			if(!$file['selftrace'] || $selftraces)
				$result[] = array('filename' => $fileName, 'invokeUrl' => str_replace($_SERVER['DOCUMENT_ROOT'].'/', '', $file['invokeUrl']));
		}
		return $result;
	}
	
	public function getTraceReader($file){
		$prepFile = Config::$storageDir.$file.Config::$preprocessedSuffix;
		try{
			$r = new Webgrind_Reader($prepFile);
		} catch (Exception $e){
			// Preprocessed file does not exist or other error
			$cg = new Webgrind_Preprocessor(Config::$xdebugOutputDir.$file, $prepFile);
			$cg->parse();
			$r = new Webgrind_Reader($prepFile);
		}
		return $r;
	}
	
	private function mtimeCmp($a, $b){
		if ($a['mtime'] == $b['mtime'])
		    return 0;

		return ($a['mtime'] > $b['mtime']) ? -1 : 1;
	}
	
}