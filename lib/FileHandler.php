<?php
require 'Reader.php';
require 'CallgrindPreprocessor.php';
class FileHandler{
	private static $singleton = null;
	
	public static function getInstance(){
		if(self::$singleton==null)
			self::$singleton = new FileHandler();
		return self::$singleton;
	}
		
	private function __construct(){
		
		$files = $this->getFiles(Config::$xdebugOutputFormat, Config::$xdebugOutputDir);
		
		if(file_exists(Config::$storageDir.Config::$selftrace))
			$selftraceFiles = unserialize(file_get_contents(Config::$storageDir.Config::$selftrace));
		else
			$selftraceFiles = array();
			
		$this->selftraceFiles = array();
		foreach($files as &$file){
		    $file['invokeUrl'] = $this->getInvokeUrl($file['absoluteFilename']);
		
			if(isset($selftraceFiles[$file['absoluteFilename']]) && $selftraceFiles[$file['absoluteFilename']]==$file['mtime']){
				$file['selftrace']=true;
				$this->selftraceFiles[$file['absoluteFilename']] = $selftraceFiles[$file['absoluteFilename']];
			}
		}

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
	
	public function __destruct(){
		file_put_contents(Config::$storageDir.Config::$selftrace, serialize($this->selftraceFiles));
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
		foreach($list as $file){
			$absoluteFilename = $dir.$file;
			$files[$file] = array('absoluteFilename'=>$absoluteFilename, 'mtime'=>filemtime($absoluteFilename), 'preprocessed'=>false, 'selftrace'=>false);
		}		
		return $files;
	}
	
	
	public function markAsSelftrace($file){
		$this->selftraceFiles[$file] = filemtime($file);
		// Newer allow the file currently beeing generated to be parsed
		unset($this->files[basename($file)]);
	}
	
	public function getTraceList($selftraces=false){
		$result = array();
		foreach($this->files as $fileName=>$file){
			if(!$file['selftrace'])
				$result[] = array('filename' => $fileName, 'invokeUrl' => str_replace($_SERVER['DOCUMENT_ROOT'].'/', '', $file['invokeUrl']));
		}
		return $result;
	}
	
	public function getTraceReader($file){
		$prepFile = Config::$storageDir.$file.Config::$preprocessedSuffix;
		try{
			$r = new Reader($prepFile);
		} catch (Exception $e){
			// Preprocessed file does not exist or other error
			$cg = new CallgrindPreprocessor(Config::$xdebugOutputDir.$file, $prepFile);
			$cg->parse();
			$r = new Reader($prepFile);
		}
		return $r;
	}
	
	private function mtimeCmp($a, $b){
		if ($a['mtime'] == $b['mtime'])
		    return 0;

		return ($a['mtime'] > $b['mtime']) ? -1 : 1;
	}
	
}