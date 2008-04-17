<?php
/*

Preprocessed file format v4:

file_contents: version_number header_address function_count function_addresses functions subcalls headers
version_number: number
header_address: number
function_count: number
function_addresses: {number}
functions: {total_self_cost total_inclusive_self_cost total_call_cost invocation_count invocations file_name function_name}
total_self_cost: number
total_inclusive_self_cost: number
total_call_cost: number
invocation_count: number
invocations:  self_cost inclusive_self_cost called_from subcall_count subcall_address 
file_name: string_newline
function_name: string_newline
self_cost: number
inclusive_self_cost: number
called_from: call_cost function invocation line_number
call_cost: number
function: number
invocation: number
line_number: number
subcall_count: number
subcall_address: number
subcalls: {function invocation line_number call_cost}
headers: {string_newline}
string_newline: any string terminated by a newline character
number: unsigned long (always 32 bit, little endian byte order)

*/



class CallgrindPreprocessor{
	const FILE_FORMAT_VERSION = 4;

	const NR_FORMAT = 'V';
	const NR_SIZE = 4;
	const INVOCATION_LENGTH = 8;
	const SUBCALL_LENGTH = 4;

	const ENTRY_POINT = '{main}';

	private $in, $out;
	private $nextFuncNr = 0;
	private $functions = array();
	private $headers = array();
	
	function __construct($inFile, $outFile){
		$this->in = fopen($inFile, 'rb');
		$this->out = fopen($outFile, 'w+b');
	}
	
	
	function parse(){
		/*
		* Pass 1
		* Find function information and count function invocations
		*/
		while(($line = fgets($this->in))){
			if(substr($line,0,3)==='fl='){
				list($function) = fscanf($this->in,"fn=%s");
				if(!isset($this->functions[$function])){
					// New function
					$this->functions[$function] = array('filename'=>substr(trim($line),3), 'invocations'=>1,'nr'=>$this->nextFuncNr++,'stack'=>array(),'count'=>0,'selfCost'=>0,'inclusiveSelfCost'=>0,'callCost'=>0);
				} else {
					$this->functions[$function]['invocations']++;
				}
			} else if(strpos($line,': ')!==false){
				$this->headerFound($line);
			}
		}
		// Write function information
		$functionCount = sizeof($this->functions);
		fwrite($this->out,pack(self::NR_FORMAT.'*',self::FILE_FORMAT_VERSION,0,$functionCount));
		// Position where function addresses must be written
		$funcAddrMark = ftell($this->out);
		fseek($this->out,self::NR_SIZE*$functionCount, SEEK_CUR);
		foreach($this->functions as $function=>&$data){
			$data['position'] = ftell($this->out);
			fwrite($this->out,pack(self::NR_FORMAT.'*',0,0,0,$data['invocations']));
			$data['nextInvocationPosition'] = ftell($this->out);
			fseek($this->out,self::NR_SIZE*self::INVOCATION_LENGTH*$data['invocations'], SEEK_CUR);
			fwrite($this->out,$data['filename']."\n".$function."\n");			
		}
		$callAddress = ftell($this->out);
		fseek($this->out,$funcAddrMark,SEEK_SET);
		foreach($this->functions as $func){
			fwrite($this->out,pack(self::NR_FORMAT,$func['position']));
		}
		fseek($this->out,$callAddress,SEEK_SET);
		rewind($this->in);
		
		/*
		* Pass 2
		* Parse invocations and insert address information in data created above
		*/
		while(($line = fgets($this->in))){
			if(substr($line,0,3)==='fl='){
				list($function) = fscanf($this->in,"fn=%s");
				
				if($function==self::ENTRY_POINT){
					// The entry point function has a summary header in the middle of no where. Header has been read above
					fgets($this->in);					
					fgets($this->in);
					fgets($this->in);
				}
				
				$stackSize = sizeof($this->functions[$function]['stack']);
				$count = $this->functions[$function]['count'];
				
				if($stackSize>0 && $this->functions[$function]['stack'][$stackSize-1]['nr']==$count-1)
					$this->functions[$function]['stack'][$stackSize-1]['nr']++;
				else
					$this->functions[$function]['stack'][] = array('min'=>$count, 'nr'=>$count);
				
				$this->functions[$function]['count']++;
				list($lnr, $selfCost) = fscanf($this->in,"%d %d");
				$inclusiveSelfCost = $selfCost;
				$this->functions[$function]['selfCost'] += $selfCost;
				$callAddr = ftell($this->out);
				$callCount = 0;				
				
				
				$callsPos = ftell($this->in);
				$functionCalls = array();
				while(($line=fgets($this->in))!="\n"){
						if(substr($line,0,4)==='cfn='){
							$calledFunctionName = substr(trim($line),4);
							if(!isset($functionCalls[$calledFunctionName]))
								$functionCalls[$calledFunctionName]=0;
							$functionCalls[$calledFunctionName]++;
						}
				}
				
				fseek($this->in, $callsPos, SEEK_SET);
				while(($line=fgets($this->in))!="\n"){
					if(substr($line,0,4)==='cfn='){
						// Skip call line
						fgets($this->in);
						// Cost line
						list($lnr, $cost) = fscanf($this->in,"%d %d");
						$calledFunctionName = substr(trim($line),4);
						$inclusiveSelfCost += $cost;
						$this->functions[$calledFunctionName]['callCost'] += $cost;
						
						$stackTop = sizeof($this->functions[$calledFunctionName]['stack'])-1;

						$invocationNr = $this->functions[$calledFunctionName]['stack'][$stackTop]['nr']--;
						if($invocationNr == $this->functions[$calledFunctionName]['stack'][$stackTop]['min'])
							array_pop($this->functions[$calledFunctionName]['stack']);
							
						//echo 'Function '.$function.' calls '.$calledFunctionName.' invocation '.$invocationNr."\n";
						
						$here = ftell($this->out);
						$pos = $this->functions[$calledFunctionName]['position'];
						// Seek past total selfcost, total invocation cost, total callcost, invocationcount and invocations and self cost and inclusive self cost
						$pos = $pos+(6+self::INVOCATION_LENGTH*$invocationNr)*self::NR_SIZE;
						fseek($this->out, $pos, SEEK_SET);
						fwrite($this->out, pack(self::NR_FORMAT.'*', $cost, $this->functions[$function]['nr'], $this->functions[$function]['count']-1, $lnr));
						fseek($this->out, $here, SEEK_SET);
						
						fwrite($this->out, pack(self::NR_FORMAT.'*',$this->functions[$calledFunctionName]['nr'],$invocationNr,$lnr, $cost));
						$callCount++;	
					}
				}
				
				$this->functions[$function]['inclusiveSelfCost'] += $inclusiveSelfCost;
				
				$this->addInvocationInfo($function, $selfCost, $inclusiveSelfCost, $callCount, $callAddr);
			}
		}
		
		foreach($this->functions as $func){
			fseek($this->out, $func['position'], SEEK_SET);
			fwrite($this->out,pack(self::NR_FORMAT.'*',$func['selfCost'],$func['inclusiveSelfCost'],$func['callCost']));			
		}
		
		fseek($this->out,0,SEEK_END);
		$headersPos = ftell($this->out);
		// Write headers
		foreach($this->headers as $header){
			fwrite($this->out,$header);
		}
		fseek($this->out,self::NR_SIZE, SEEK_SET);
		fwrite($this->out, pack('L',$headersPos));
	}
	
	private function addInvocationInfo($function, $selfCost, $inclusiveSelfCost, $subCallCount, $subCallsAddr){
		$here = ftell($this->out);	
			
		$pos = &$this->functions[$function]['nextInvocationPosition'];
		fseek($this->out, $pos, SEEK_SET);
		fwrite($this->out, pack(self::NR_FORMAT.'*',$selfCost, $inclusiveSelfCost, 0, -1, 0, 0, $subCallCount, $subCallsAddr));	
		$pos = ftell($this->out);

		fseek($this->out, $here, SEEK_SET);						
	}
	
	
	private function headerFound($line){
		$this->headers[] = $line;
	}
}

