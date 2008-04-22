<?php
/**
 * @author Jacob Oettinger
 * @author Joakim Nygård
 */

require 'config.php';
require 'library/FileHandler.php';
// Errorhandling.
// No files, outputdir not writabel
// Show self traces in option group
set_time_limit(0);

// Make sure we have a timezone for date functions.
if (ini_get('date.timezone') == '')
    date_default_timezone_set( Webgrind_Config::$defaultTimezone );


switch(get('op')){
	case 'file_list':
		echo json_encode(Webgrind_FileHandler::getInstance()->getTraceList());
		break;	
	case 'function_list':
		$dataFile = get('dataFile');
		if($dataFile=='0'){
			$files = Webgrind_FileHandler::getInstance()->getTraceList();
			$dataFile = $files[0]['filename'];
		}
		$reader = Webgrind_FileHandler::getInstance()->getTraceReader($dataFile);
		$count = $reader->getFunctionCount();
		$functions = array();
        $shownTotal = 0;

		for($i=0;$i<$count;$i++) {
		    $functionInfo = $reader->getFunctionInfo($i,'absolute');

		    if (!(int)get('hideInternals', 0) || strpos($functionInfo['functionName'], 'php::') === false) {
    			$shownTotal += $functionInfo['summedSelfCost'];
				$functions[$i] = $functionInfo;
    			$functions[$i]['nr'] = $i;
    		}
		}
		usort($functions,'costCmp');
		
		$remainingCost = $shownTotal*get('showFraction');
		
		$result['functions'] = array();
		foreach($functions as $function){
			$remainingCost -= $function['summedSelfCost'];
			
			if(get('costFormat')=='percentual'){
				$function['summedSelfCost'] = $reader->percentCost($function['summedSelfCost']);
				$function['summedInclusiveCost'] = $reader->percentCost($function['summedInclusiveCost']);
			}
			
			$result['functions'][] = $function;
			if($remainingCost<0)
				break;
		}
		$result['summedInvocationCount'] = $count;
        $result['summedRunTime'] = $reader->getHeader('summary');
		$result['dataFile'] = $dataFile;
		$result['invokeUrl'] = $reader->getHeader('cmd');
		$result['mtime'] = date(Webgrind_Config::$dateFormat,filemtime(Webgrind_Config::$xdebugOutputDir.$dataFile));
		echo json_encode($result);
	break;
	case 'callinfo_list':
		$reader = Webgrind_FileHandler::getInstance()->getTraceReader(get('file'));
		$functionNr = get('functionNr');
 		$function = $reader->getFunctionInfo($functionNr);
			
		$result = array('invocations'=>array());
		$foundInvocations = 0;
		for($i=0;$i<$function['callInfoCount'];$i++){
			$invo = $reader->getCallInfo($functionNr, $i, get('costFormat', 'absolute'));
			$foundInvocations += $invo['callCount'];
			$callerInfo = $reader->getFunctionInfo($invo['functionNr'], get('costFormat', 'absolute'));
			$invo['callerFile'] = $callerInfo['file'];
			$invo['callerFunctionName'] = $callerInfo['functionName'];
			$result['invocations'][] = $invo;
		}
		$result['calledByHost'] = ($foundInvocations<$function['invocationCount']);
		echo json_encode($result);
		
	break;
	case 'fileviewer':
		$file = get('file');
		$line = get('line');
	
		if($file && $file!=''){
			$message = '';
			if(!file_exists($file)){
				$message = $file.' does not exist.';
			} else if(!is_readable($file)){
				$message = $file.' is not readable.';
			} else if(is_dir($file)){
				$message = $file.' is a directory.';
			} 		
		} else {
			$message = 'No file to view';
		}
		require 'templates/fileviewer.phtml';
	
	break;
	default:
		require 'templates/index.phtml';
}


function get($param, $default=false){
	return (isset($_GET[$param])? $_GET[$param] : $default);
}


function costCmp($a, $b){
	$a = $a['summedSelfCost'];
	$b = $b['summedSelfCost'];

	if ($a == $b) {
	    return 0;
	}
	return ($a > $b) ? -1 : 1;
}
