<?php
/**
 * Class for preprocessing callgrind files.
 *
 * Information from the callgrind file is extracted and written in a binary format for
 * fast random access.
 *
 * @see https://github.com/jokkedk/webgrind/wiki/Preprocessed-Format
 * @see http://valgrind.org/docs/manual/cl-format.html
 * @package Webgrind
 * @author Jacob Oettinger
 */
class Webgrind_Preprocessor
{

    /**
     * Fileformat version. Embedded in the output for parsers to use.
     */
    const FILE_FORMAT_VERSION = 7;

    /**
     * Binary number format used.
     * @see http://php.net/pack
     */
    const NR_FORMAT = 'V';

    /**
     * Size, in bytes, of the above number format
     */
    const NR_SIZE = 4;

    /**
     * String name of main function
     */
    const ENTRY_POINT = '{main}';


    /**
     * Extract information from $inFile and store in preprocessed form in $outFile
     *
     * @param string $inFile Callgrind file to read
     * @param string $outFile File to write preprocessed data to
     * @return void
     */
    static function parse($inFile, $outFile)
    {
        $in = @fopen($inFile, 'rb');
        if (!$in)
            throw new Exception('Could not open '.$inFile.' for reading.');
        $out = @fopen($outFile, 'w+b');
        if (!$out)
            throw new Exception('Could not open '.$outFile.' for writing.');

        $nextFuncNr = 0;
        $functionNames = array();
        $functions = array();
        $headers = array();

        // Read information into memory
        while (($line = fgets($in))) {
            if (substr($line,0,3)==='fl=') {
                // Found invocation of function. Read functionname
                fscanf($in,"fn=%[^\n\r]s",$function);
                $function = self::getCompressedName($function, false);
                // Special case for ENTRY_POINT - it contains summary header
                if (self::ENTRY_POINT == $function) {
                    fgets($in);
                    $headers[] = fgets($in);
                    fgets($in);
                }
                // Cost line
                fscanf($in,"%d %d",$lnr,$cost);

                if (!isset($functionNames[$function])) {
                    $index = $nextFuncNr++;
                    $functionNames[$function] = $index;
                    $functions[$index] = array(
                        'filename'              => self::getCompressedName(substr(trim($line),3), true),
                        'line'                  => $lnr,
                        'invocationCount'       => 1,
                        'summedSelfCost'        => $cost,
                        'summedInclusiveCost'   => $cost,
                        'calledFromInformation' => array(),
                        'subCallInformation'    => array()
                    );
                } else {
                    $index = $functionNames[$function];
                    $functions[$index]['invocationCount']++;
                    $functions[$index]['summedSelfCost'] += $cost;
                    $functions[$index]['summedInclusiveCost'] += $cost;
                }
            } else if (substr($line,0,4)==='cfn=') {
                // Found call to function. ($function should contain function call originates from)
                $calledFunctionName = self::getCompressedName(substr(trim($line),4), false);
                // Skip call line
                fgets($in);
                // Cost line
                fscanf($in,"%d %d",$lnr,$cost);

                $functions[$index]['summedInclusiveCost'] += $cost;

                $calledIndex = $functionNames[$calledFunctionName];
                $key = $index.$lnr;
                if (!isset($functions[$calledIndex]['calledFromInformation'][$key])) {
                    $functions[$calledIndex]['calledFromInformation'][$key] = array('line'=>$lnr,'callCount'=>0,'summedCallCost'=>0);
                }

                $functions[$calledIndex]['calledFromInformation'][$key]['callCount']++;
                $functions[$calledIndex]['calledFromInformation'][$key]['summedCallCost'] += $cost;

                $calledKey = $calledIndex.$lnr;
                if (!isset($functions[$index]['subCallInformation'][$calledKey])) {
                    $functions[$index]['subCallInformation'][$calledKey] = array('functionNr'=>$calledIndex,'line'=>$lnr,'callCount'=>0,'summedCallCost'=>0);
                }

                $functions[$index]['subCallInformation'][$calledKey]['callCount']++;
                $functions[$index]['subCallInformation'][$calledKey]['summedCallCost'] += $cost;

            } else if (strpos($line,': ')!==false) {
                // Found header
                $headers[] = $line;
            }
        }

        $functionNames = array_flip($functionNames);

        // Write output
        $functionCount = sizeof($functions);
        fwrite($out, pack(self::NR_FORMAT.'*', self::FILE_FORMAT_VERSION, 0, $functionCount));
        // Make room for function addresses
        fseek($out,self::NR_SIZE*$functionCount, SEEK_CUR);
        $functionAddresses = array();
        foreach ($functions as $index=>$function) {
            $functionAddresses[] = ftell($out);
            $calledFromCount = sizeof($function['calledFromInformation']);
            $subCallCount = sizeof($function['subCallInformation']);
            fwrite($out, pack(self::NR_FORMAT.'*', $function['line'], $function['summedSelfCost'], $function['summedInclusiveCost'], $function['invocationCount'], $calledFromCount, $subCallCount));
            // Write called from information
            foreach ((array)$function['calledFromInformation'] as $call) {
                fwrite($out, pack(self::NR_FORMAT.'*', $index, $call['line'], $call['callCount'], $call['summedCallCost']));
            }
            // Write sub call information
            foreach ((array)$function['subCallInformation'] as $call) {
                fwrite($out, pack(self::NR_FORMAT.'*', $call['functionNr'], $call['line'], $call['callCount'], $call['summedCallCost']));
            }

            fwrite($out, $function['filename']."\n".$functionNames[$index]."\n");
        }
        $headersPos = ftell($out);
        // Write headers
        foreach ($headers as $header) {
            fwrite($out,$header);
        }

        // Write addresses
        fseek($out,self::NR_SIZE, SEEK_SET);
        fwrite($out, pack(self::NR_FORMAT, $headersPos));
        // Skip function count
        fseek($out,self::NR_SIZE, SEEK_CUR);
        // Write function addresses
        foreach ($functionAddresses as $address) {
            fwrite($out, pack(self::NR_FORMAT, $address));
        }
    }

    /**
     * Extract information from $inFile and store in preprocessed form in $outFile
     *
     * @param string $name String to parse (either a filename or function name line)
     * @param int $isFile True if this is a filename line (since files and functions have their own symbol tables)
     * @return void
     **/
    static function getCompressedName($name, $isFile) {
        global $compressedNames;
        if (!preg_match("/\((\d+)\)(.+)?/", $name, $matches)) {
            return $name;
        }
        $functionIndex = $matches[1];
        if (!isset($compressedNames[$isFile][$functionIndex]) || isset($matches[2])) {
            $compressedNames[$isFile][$functionIndex] = trim($matches[2]);
        }
        return $compressedNames[$isFile][$functionIndex];
    }

}
