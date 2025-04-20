<?php
	$db 			= "";
	$mysql_host 	= "172.16.13.166";
	$mysql_user		= "user1";
	$mysql_pass		= "user123";
	$mysql_db 		= "employee_db";
	try{
		$db = mysqli_connect($mysql_host,$mysql_user,$mysql_pass,$mysql_db);	#Try to connect to server
	}catch(Exception $e){
		echo '<p style="color: #ff6161; margin: 8px 0px;">Cannot connect to server</p>';	#Print the error
		exit();
	}
	mysqli_set_charset($db, 'UTF8');

?>
