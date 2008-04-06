<?php
function highlight_num($file, $line){ 
	$lineCount = count(file($file));
	$numbers = '<code class="num">';
	for($i = 1; $i<=$lineCount; $i++){
		$numbers .= ($i==$line)? '<span id="line_emph">&nbsp;</span>' : '';
		$numbers .= $i.'<br />';
	}
	$numbers .= '</code>'; 
	
	return $numbers . highlight_file($file, true); 
}



if(isset($_GET['file']) && $_GET['file']!=''){
	$file = $_GET['file'];
	$line = $_GET['line'];
	if(!file_exists($file))
		$content = 'File('.$file.') does not exist.';
	if(!is_readable($file))
		$content = 'File('.$file.') is not readable.';
	else
		$content = highlight_num($file, $line);
		
} else {
	$content = 'No file to view';
}

require 'templates/fileviewer.phtml';