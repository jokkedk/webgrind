<?php

require 'config.php';
require 'lib/FileHandler.php';
// Errorhandling.
// No files, outputdir not writabel
// Show self traces in option group
set_time_limit(0);

// Make sure we have a timezone for date functions.
if (ini_get('date.timezone') == '')
    date_default_timezone_set( Config::$defaultTimezone );


switch(get('op')){
	case 'file_list':
		echo json_encode(FileHandler::getInstance()->getTraceList());
		break;	
	case 'function_list':
		$dataFile = get('dataFile');
		if($dataFile=='0'){
			$files = FileHandler::getInstance()->getTraceList();
			$dataFile = $files[0]['filename'];
		}
		$reader = FileHandler::getInstance()->getTraceReader($dataFile);
		$count = $reader->getFunctionCount();
		$functions = array();
		$summedCost = $shownTotal = 0;
        $result['summedRunTime'] = $reader->getHeader('summary');

		for($i=0;$i<$count;$i++) {
		    $functionInfo = $reader->getFunctionInfo($i,'absolute');
			$summedCost += $functionInfo['summedSelfCost'];

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
		$result['dataFile'] = $dataFile;
		$result['invokeUrl'] = $reader->getHeader('cmd');
		$result['mtime'] = date(Config::$dateFormat,filemtime(Config::$xdebugOutputDir.$dataFile));
		$result['summedSelfTime'] = $summedCost;
		echo json_encode($result);
	break;
	case 'callinfo_list':
		$reader = FileHandler::getInstance()->getTraceReader(get('file'));
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
	default:
		require 'templates/index.phtml';
}

function get($param, $default=false){
	return (isset($_GET[$param])? $_GET[$param] : $default);
}

function unsetMultiple(&$array, $fields){
	foreach($fields as $field)
		unset($array[$field]);
}

function costCmp($a, $b){
	$a = $a['summedSelfCost'];
	$b = $b['summedSelfCost'];

	if ($a == $b) {
	    return 0;
	}
	return ($a > $b) ? -1 : 1;
}
