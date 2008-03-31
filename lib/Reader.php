<?php
// TODO Error handling

class Reader{
	const FILE_FORMAT_VERSION = 4;

	const NR_FORMAT = 'V';
	const NR_SIZE = 4;
	const INVOCATION_LENGTH = 8;
	const SUBCALL_LENGTH = 4;
	

	const ENTRY_POINT = '{main}';
	
	
	private $headersPos, $functionPos, $headers=null;
	
	function __construct($dataFile){
		$this->fp = @fopen($dataFile,'rb');
		if(!$this->fp)
			throw new Exception('Error opening file!');
		$this->init();
	}
	
	private function read($numbers=1){
		$values = unpack(self::NR_FORMAT.$numbers,fread($this->fp,self::NR_SIZE*$numbers));
		if($numbers==1)
			return $values[1];
		else 
			return array_values($values); // reindex and return
	}
	
	private function readLine(){
		$result = fgets($this->fp);
		if($result)
			return trim($result);
		else
			return $result;
	}
	
	private function seek($offset, $whence=SEEK_SET){
		return fseek($this->fp, $offset, $whence);
	}
	
	// Read initial information from datafile
	private function init(){
		list($version, $this->headersPos, $functionCount) = $this->read(3);
		if($version!=self::FILE_FORMAT_VERSION)
			throw new Exception('Datafile not correct version. Found '.$version.' expected '.self::FILE_FORMAT_VERSION);
		$this->functionPos = $this->read($functionCount);		
	}
	
	function getFunctionCount(){
		return sizeof($this->functionPos);
	}

	function getFunctionInfo($nr, $costFormat = 'absolute'){
		$this->seek($this->functionPos[$nr]);
		list($totalSelfCost, $totalInclusiveSelfCost, $totalCallCost, $invocationCount) = $this->read(4);
		$this->seek(self::NR_SIZE*self::INVOCATION_LENGTH*$invocationCount, SEEK_CUR);
		$file = $this->readLine();
		$function = $this->readLine();

	    if ($costFormat == 'percentual') {
	        $totalTime = $this->getHeader('summary');
    		return array(
    		    'file'=>$file, 
    		    'functionName'=>$function, 
    		    'totalSelfCost'=>percentCost($totalSelfCost, $totalTime), 
    		    'totalInclusiveSelfCost'=>percentCost($totalInclusiveSelfCost, $totalTime), 
    		    'totalCallCost'=>$totalCallCost, 
    		    'invocationCount'=>$invocationCount
    		);            
        } else {
    		return array(
    		    'file'=>$file, 
    		    'functionName'=>$function, 
    		    'totalSelfCost'=>$totalSelfCost, 
    		    'totalInclusiveSelfCost'=>$totalInclusiveSelfCost, 
    		    'totalCallCost'=>$totalCallCost, 
    		    'invocationCount'=>$invocationCount
    		);            
        }
	}
	
	function getInvocation($functionNr, $invocationNr, $costFormat = 'absolute'){
		$this->seek($this->functionPos[$functionNr]+self::NR_SIZE*(self::INVOCATION_LENGTH*$invocationNr+4));
		$data = $this->read(self::INVOCATION_LENGTH);
		
		if ($costFormat == 'percentual') {
	        $totalTime = $this->getHeader('summary');
	        $result = array(
	            'selfCost'=>percentCost($data[0], $totalTime), 
	            'inclusiveSelfCost'=>percentCost($data[1], $totalTime), 
	            'callCost'=>$data[2], 
	            'calledFromFunction'=>$data[3], 
	            'calledFromInvocation'=>$data[4], 
	            'calledFromLine'=>$data[5], 
	            'subCalls'=>array()
	        );
	    } else {
		    $result = array(
		        'selfCost'=>$data[0], 
		        'inclusiveSelfCost'=>$data[1], 
		        'callCost'=>$data[2], 
		        'calledFromFunction'=>$data[3], 
		        'calledFromInvocation'=>$data[4], 
		        'calledFromLine'=>$data[5], 
		        'subCalls'=>array()
		    );
		}
		$this->seek($data[7]);
		for($i=0;$i<$data[6];$i++){
			$scData = $this->read(self::SUBCALL_LENGTH);
			$result['subCalls'][] = array('functionNr'=>$scData[0], 'invocationNr'=>$scData[1], 'line'=>$scData[2], 'cost'=>$scData[3]);
		}
		return $result;
	}
	
	function getHeaders(){
		if($this->headers==null){
			$this->seek($this->headersPos);
			while($line=$this->readLine()){
				$parts = explode(': ',$line);
				$this->headers[$parts[0]] = $parts[1];
			}
		}
		return $this->headers;
	}
	
	function getHeader($header){
		$headers = $this->getHeaders();
		return $headers[$header];
	}
	
}
