<?php

/**
 * Class for preprocessing callgrind files.
 *
 * Information from the callgrind file is extracted and written in a binary format for
 * fast random access.
 *
 * @see     http://code.google.com/p/webgrind/wiki/PreprocessedFormat
 * @see     http://valgrind.org/docs/manual/cl-format.html
 * @package Webgrind
 * @author  Jacob Oettinger
 * @author  Kotlyarov Nikolay
 **/
class Webgrind_Preprocessor {

    /**
     * Fileformat version. Embedded in the output for parsers to use.
     */
    const FILE_FORMAT_VERSION = 8;

    /**
     * Binary number format used.
     * @see http://php.net/pack
     */
    const NR_FORMAT = 'V';

    /**
     * String name of main function
     */
    const ENTRY_POINT = '{main}';


    /**
     * Extract information from $inFile and store in preprocessed form in $outFile
     *
     * @param string $inFile  Callgrind file to read
     * @param string $outFile File to write preprocessed data to
     * @throws Exception
     * @return void
     */
    static function parse($inFile, $outFile) {
        set_time_limit(0);

        if (!($in  = @fopen($inFile,   'rb'))) throw new Exception('Could not open '.$inFile.' for reading.');
        if (!($out = @fopen($outFile, 'w+b'))) throw new Exception('Could not open '.$outFile.' for writing.');
        $nr_size = strlen(pack(self::NR_FORMAT, 65536));

        $nextFuncNr = 0;
        $functions  = $headers = $calls = array();
        $function   = '';

        $cur_function = null;

        // Read information into memory
        while ($line = fgets($in)) {
            if (substr($line, 0, 3) === 'fl=') {
                // Found invocation of function. Read functionname
                list($function) = fscanf($in, "fn=%[^\n\r]s");
                $cur_function = &$functions[$function];
                if (empty($cur_function)) {
                    $cur_function = array(
                        'filename'              => substr(trim($line), 3),
                        'invocationCount'       => 0,
                        'nr'                    => $nextFuncNr++,
                        'count'                 => 0,
                        'summedSelfCost'        => 0,
                        'summedInclusiveCost'   => 0,
                        'calledFromInformation' => array(),
                        'subCallInformation'    => array()
                    );
                }

                $cur_function['invocationCount']++;

                // Special case for ENTRY_POINT - it contains summary header
                if (self::ENTRY_POINT == $function) {
                    fgets($in);
                    $headers[] = fgets($in);
                    fgets($in);
                }

                // Cost line
                list($lnr, $cost) = fscanf($in, "%d %d");
                //if ($cost > 4000000000) $cost = 4294967296 - $cost;

		$cur_function['line'] = $lnr;
                $cur_function['summedSelfCost'] += $cost;
                $cur_function['summedInclusiveCost'] += $cost;
            } else if (substr($line, 0, 4) === 'cfn=') {
                // Found call to function. ($function should contain function call originates from)
                $calledFunctionName = substr(trim($line), 4);

                // Skip call line
                fgets($in);

                // Cost line
                list($lnr, $cost) = fscanf($in,"%d %d");
                //if ($cost > 4000000000) $cost = 4294967296 - $cost;

                $cur_function['summedInclusiveCost'] += $cost;

                $calledFromInformation = &$functions[$calledFunctionName]['calledFromInformation'][$function.':'.$lnr];

                if (empty($calledFromInformation)) {
                    $calledFromInformation = array(
                        'functionNr'     => $cur_function['nr'],
                        'line'           => $lnr,
                        'callCount'      => 0,
                        'summedCallCost' => 0
                    );
                }

                $calledFromInformation['callCount']++;
                $calledFromInformation['summedCallCost'] += $cost;

                $subCallInformation = &$cur_function['subCallInformation'][$calledFunctionName.':'.$lnr];

                if (empty($subCallInformation)) {
                    $subCallInformation = array(
                        'functionNr'     => $functions[$calledFunctionName]['nr'],
                        'line'           => $lnr,
                        'callCount'      => 0,
                        'summedCallCost' => 0
                    );
                }

                $subCallInformation['callCount']++;
                $subCallInformation['summedCallCost'] += $cost;
            } else if (strpos($line, ': ') !== false) {
                // Found header
                $headers[] = $line;
            }
        }
        // END of Read information into memory

        // Write output
        $functionCount = count($functions);
        fwrite($out, pack(self::NR_FORMAT.'*', self::FILE_FORMAT_VERSION, 0, $functionCount));

        // Make room for function addresses
        fseek($out, $nr_size * $functionCount, SEEK_CUR);
        $functionAddresses = array();
        foreach ($functions as $functionName => $function) {
            $functionAddresses[] = ftell($out);
            $calledFromCount     = count($function['calledFromInformation']);
            $subCallCount        = count($function['subCallInformation']);
            fwrite($out, pack(self::NR_FORMAT.'*', $function['line'], $function['summedSelfCost'], $function['summedInclusiveCost'], $function['invocationCount'], $calledFromCount, $subCallCount));
            // Write called from information
            foreach ((array)$function['calledFromInformation'] as $call) {
                fwrite($out, pack(self::NR_FORMAT.'*', $call['functionNr'], $call['line'], $call['callCount'], $call['summedCallCost']));
            }
            // Write sub call information
            foreach ((array)$function['subCallInformation'] as $call) {
                fwrite($out, pack(self::NR_FORMAT.'*', $call['functionNr'], $call['line'], $call['callCount'], $call['summedCallCost']));
            }

            fwrite($out, $function['filename']."\n".$functionName."\n");
        }

        $headersPos = ftell($out);

        // Write headers
        foreach ($headers as $header) fwrite($out, $header);

        // Write addresses
        fseek($out, $nr_size, SEEK_SET);
        fwrite($out, pack(self::NR_FORMAT, $headersPos));

        // Skip function count
        fseek($out, $nr_size, SEEK_CUR);

        // Write function addresses
        foreach ($functionAddresses as $address) fwrite($out, pack(self::NR_FORMAT, $address));
    }
}
