<?php
$date = strtotime($_REQUEST["date"]);
$format = isset($_REQUEST["format"]) ? $_REQUEST["format"] : "U";
echo date($format, $date);
