<?php
function highlight_num($file){ 
  $numbers = '<code class="num">'. implode(range(1, count(file($file))), '<br />') . '</code>'; 
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
		$content = highlight_num($file);
		
} else {
	$content = 'No file to view';
}

require 'templates/fileviewer.phtml';