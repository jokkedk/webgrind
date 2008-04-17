<?php

class Webgrind_Reader{
	const FILE_FORMAT_VERSION = 5;

	const NR_FORMAT = 'V';
	const NR_SIZE = 4;
	const CALLINFORMATION_LENGTH = 4;

	const ENTRY_POINT = '{main}';
	
	
	private $headersPos, $functionPos, $headers=null;
	
	function __construct($dataFile){
		$this->fp = @fopen($dataFile,'rb');
		if(!$this->fp)
			throw new Exception('Error opening file!');
		$this->init();
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
		
		list($summedSelfCost, $summedInclusiveCost, $invocationCount, $calledFromCount) = $this->read(4);
		
		$this->seek(self::NR_SIZE*self::CALLINFORMATION_LENGTH*$calledFromCount, SEEK_CUR);
		$file = $this->readLine();
		$function = $this->readLine();

	   	$result = array(
    	    'file'=>$file, 
   		    'functionName'=>$function, 
   		    'summedSelfCost'=>$summedSelfCost,
   		    'summedInclusiveCost'=>$summedInclusiveCost, 
   		    'invocationCount'=>$invocationCount,
			'callInfoCount'=>$calledFromCount
   		);            
        if ($costFormat == 'percentual') {
	        $result['summedSelfCost'] = $this->percentCost($result['summedSelfCost']);
	        $result['summedInclusiveCost'] = $this->percentCost($result['summedInclusiveCost']);
	    }

		return $result;
	}
	
	function getCallInfo($functionNr, $calledFromNr, $costFormat = 'absolute'){
		// 4 = number of numbers beforea call information
		$this->seek($this->functionPos[$functionNr]+self::NR_SIZE*(self::CALLINFORMATION_LENGTH*$calledFromNr+4));
		$data = $this->read(self::CALLINFORMATION_LENGTH);

	    $result = array(
	        'functionNr'=>$data[0], 
	        'line'=>$data[1], 
	        'callCount'=>$data[2], 
	        'summedCallCost'=>$data[3]
	    );
		
		if ($costFormat == 'percentual') {
	        $result['summedCallCost'] = $this->percentCost($result['summedCallCost']);
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
	
	function percentCost($cost){
		$total = $this->getHeader('summary');
		$result = ($total==0) ? 0 : ($cost*100)/$total;
		return number_format($result, 3, '.', '');
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
	
}
