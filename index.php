<?php
/**
 * @author Jacob Oettinger
 * @author Joakim Nygård
 */

// Handle static files with PHP built-in webserver
if (PHP_SAPI == 'cli-server') {
    if (is_file(realpath(__DIR__ . $_SERVER['REQUEST_URI']))) {
        return false;
    }
}

class Webgrind_MasterConfig
{
    static $webgrindVersion = '1.5';
}

require './config.php';
require './library/FileHandler.php';

// TODO: Errorhandling:
//         No files, outputdir not writable

set_time_limit(0);

// Make sure we have a timezone for date functions.
if (ini_get('date.timezone') == '')
    date_default_timezone_set( Webgrind_Config::$defaultTimezone );

try {
    switch (get('op')) {
        case 'file_list':
            sendJson(Webgrind_FileHandler::getInstance()->getTraceList());
            break;

        case 'function_list':
            $dataFile = get('dataFile');
            if ($dataFile=='0') {
                $files = Webgrind_FileHandler::getInstance()->getTraceList();
                $dataFile = $files[0]['filename'];
            }
            $reader = Webgrind_FileHandler::getInstance()->getTraceReader($dataFile, get('costFormat', Webgrind_Config::$defaultCostformat));
            $functions = array();
            $shownTotal = 0;
            $breakdown = array('internal' => 0, 'procedural' => 0, 'class' => 0, 'include' => 0);

            for ($i=0; $i<$reader->getFunctionCount(); $i++) {
                $functionInfo = $reader->getFunctionInfo($i);

                if (false !== strpos($functionInfo['functionName'], 'php::')) {
                    $breakdown['internal'] += $functionInfo['summedSelfCostRaw'];
                    $humanKind = 'internal';
                } else if (false !== strpos($functionInfo['functionName'], 'require_once::') ||
                          false !== strpos($functionInfo['functionName'], 'require::') ||
                          false !== strpos($functionInfo['functionName'], 'include_once::') ||
                          false !== strpos($functionInfo['functionName'], 'include::')) {
                    $breakdown['include'] += $functionInfo['summedSelfCostRaw'];
                    $humanKind = 'include';
                } else {
                    if (false !== strpos($functionInfo['functionName'], '->') || false !== strpos($functionInfo['functionName'], '::')) {
                        $breakdown['class'] += $functionInfo['summedSelfCostRaw'];
                        $humanKind = 'class';
                    } else {
                        $breakdown['procedural'] += $functionInfo['summedSelfCostRaw'];
                        $humanKind = 'procedural';
                    }
                }
                if (!(int)get('hideInternals', 0) || strpos($functionInfo['functionName'], 'php::') === false) {
                    $shownTotal += $functionInfo['summedSelfCostRaw'];
                    $functions[$i] = $functionInfo;
                    $functions[$i]['nr'] = $i;
                    $functions[$i]['humanKind'] = $humanKind;
                }
            }
            usort($functions,'costCmp');

            $remainingCost = $shownTotal*get('showFraction');

            $result['functions'] = array();
            foreach ($functions as $function) {
                $remainingCost -= $function['summedSelfCostRaw'];
                $function['file'] = urlencode($function['file']);
                $result['functions'][] = $function;
                if ($remainingCost<0)
                    break;
            }
            $result['summedInvocationCount'] = $reader->getFunctionCount();
            $result['summedRunTime'] = $reader->formatCost($reader->getHeader('summary'), 'msec');
            $result['dataFile'] = $dataFile;
            $result['invokeUrl'] = $reader->getHeader('cmd');
            $result['runs'] = $reader->getHeader('runs');
            $result['breakdown'] = $breakdown;
            $result['mtime'] = date(Webgrind_Config::$dateFormat,filemtime(Webgrind_Config::xdebugOutputDir().$dataFile));

            $creator = preg_replace('/[^0-9\.]/', '', $reader->getHeader('creator'));
            $result['linkToFunctionLine'] = version_compare($creator, '2.1') > 0;

            sendJson($result);
        break;

        case 'callinfo_list':
            $reader = Webgrind_FileHandler::getInstance()->getTraceReader(get('file'), get('costFormat', Webgrind_Config::$defaultCostformat));
            $functionNr = get('functionNr');
            $function = $reader->getFunctionInfo($functionNr);

            $result = array('calledFrom'=>array(), 'subCalls'=>array());
            $foundInvocations = 0;
            for ($i=0; $i<$function['calledFromInfoCount']; $i++) {
                $invo = $reader->getCalledFromInfo($functionNr, $i);
                $foundInvocations += $invo['callCount'];
                $callerInfo = $reader->getFunctionInfo($invo['functionNr']);
                $invo['file'] = urlencode($callerInfo['file']);
                $invo['callerFunctionName'] = $callerInfo['functionName'];
                $result['calledFrom'][] = $invo;
            }
            $result['calledByHost'] = ($foundInvocations<$function['invocationCount']);

            for ($i=0; $i<$function['subCallInfoCount']; $i++) {
                $invo = $reader->getSubCallInfo($functionNr, $i);
                $callInfo = $reader->getFunctionInfo($invo['functionNr']);
                $invo['file'] = urlencode($function['file']); // Sub call to $callInfo['file'] but from $function['file']
                $invo['callerFunctionName'] = $callInfo['functionName'];
                $result['subCalls'][] = $invo;
            }
            sendJson($result);
        break;

        case 'fileviewer':
            $file = get('file');

            if ($file && $file!='') {
                $message = '';
                if (!file_exists($file)) {
                    $message = $file.' does not exist.';
                } else if (!is_readable($file)) {
                    $message = $file.' is not readable.';
                } else if (is_dir($file)) {
                    $message = $file.' is a directory.';
                }
            } else {
                $message = 'No file to view';
            }
            require 'templates/fileviewer.phtml';
        break;

        case 'function_graph':
            $dataFile = get('dataFile');
            $showFraction = 100 - intval(get('showFraction') * 100);
            if ($dataFile == '0') {
                $files = Webgrind_FileHandler::getInstance()->getTraceList();
                $dataFile = $files[0]['filename'];
            }

            $filename = Webgrind_Config::storageDir().$dataFile.'-'.$showFraction.Webgrind_Config::$preprocessedSuffix.'.'.Webgrind_Config::$graphImageType;
            if (!file_exists($filename)) {
                // Add enclosing quotes if needed
                foreach (array('pythonExecutable', 'dotExecutable') as $exe) {
                    $item =& Webgrind_Config::$$exe;
                    if (strpos($item, ' ') !== false && !preg_match('/^".+"$/', $item)) {
                        $item = '"'.$item.'"';
                    }
                }
                shell_exec(Webgrind_Config::$pythonExecutable.' library/gprof2dot.py -n '.$showFraction
                           .' -f callgrind '.escapeshellarg(Webgrind_Config::xdebugOutputDir().$dataFile).' | '
                           .Webgrind_Config::$dotExecutable.' -T'.Webgrind_Config::$graphImageType.' -o '.escapeshellarg($filename));
            }

            if (!file_exists($filename)) {
                $file = $filename;
                $message = 'Unable to generate <u>'.$file.'</u> via python: <u>'.Webgrind_Config::$pythonExecutable
                          .'</u> and dot: <u>'.Webgrind_Config::$dotExecutable.'</u>. Please update config.php.';
                require 'templates/fileviewer.phtml';
                break;
            }

            if (Webgrind_Config::$graphImageType == 'svg') {
                header('Content-Type: image/svg+xml');
            } else {
                header('Content-Type: image/'.Webgrind_Config::$graphImageType);
            }
            readfile($filename);
        break;

        case 'version_info':
            $response = @file_get_contents('http://jokkedk.github.io/webgrind/webgrindupdate.json?version='.Webgrind_Config::$webgrindVersion);
            if ($response) {
                header('Content-type: application/json');
                echo $response;
            }
        break;

        case 'clear_files':
            $files = Webgrind_FileHandler::getInstance()->getTraceList();
            if (!$files) {
                sendJson(array('done' => 'no files found'));
                break;
            }
            $format = array();
            foreach ($files as $file) {
                unlink(Webgrind_Config::xdebugOutputDir().$file['filename']);
                $format[] = preg_quote($file['filename'], '/');
            }
            $files = preg_grep('/'.implode('|', $format).'/', scandir(Webgrind_Config::storageDir()));
            foreach ($files as $file) {
                unlink(Webgrind_Config::storageDir().$file);
            }
            sendJson(array('done' => true));
        break;

        default:
            $welcome = '';
            if (!file_exists(Webgrind_Config::storageDir()) || !is_writable(Webgrind_Config::storageDir())) {
                $welcome .= 'Webgrind $storageDir does not exist or is not writeable: <code>'.Webgrind_Config::storageDir().'</code><br>';
            }
            if (!file_exists(Webgrind_Config::xdebugOutputDir()) || !is_readable(Webgrind_Config::xdebugOutputDir())) {
                $welcome .= 'Webgrind $profilerDir does not exist or is not readable: <code>'.Webgrind_Config::xdebugOutputDir().'</code><br>';
            }

            if ($welcome == '') {
                $welcome = 'Select a cachegrind file above<br>(looking in <code>'.Webgrind_Config::xdebugOutputDir().'</code> for files matching <code>'.Webgrind_Config::xdebugOutputFormat().'</code>)';
            }
            require 'templates/index.phtml';
    }
} catch (Exception $e) {
    sendJson(array('error' => $e->getMessage().'<br>'.$e->getFile().', line '.$e->getLine()));
    return;
}

function get($param, $default=false) {
    return (isset($_GET[$param])? $_GET[$param] : $default);
}

function costCmp($a, $b) {
    $a = $a['summedSelfCostRaw'];
    $b = $b['summedSelfCostRaw'];

    if ($a == $b) {
        return 0;
    }
    return ($a > $b) ? -1 : 1;
}

function sendJson($object) {
    header('Content-type: application/json');
    echo json_encode($object);
}
