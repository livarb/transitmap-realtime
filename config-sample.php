<?php
// Rename/copy this file to config.php

// URL for SIRI Vehicle Monitoring(VM) SOAP service
// Kolumbus: http://sis.kolumbus.no:90/VMWS/VMService.svc
$url = '';

// RequestorRef - is sent in the SOAP request message body
// Cannot be empty.
// Unless you have to use a specific value given by the data-vendor, I suggest e-mail or name.
$requestorref = '';

// Duration of server cache - Time To Live
$ttl = '5 SECONDS';

// Cache file. Must be writable (and readable) by PHP
$cachefil = "vm_geojson.cache";
?>