<?php
ob_start("ob_gzhandler");

header('Content-Type: application/vnd.geo+json; charset=utf-8');
echo(file_get_contents("test3.geojson"));
?>