<?php
// Rename/copy this file to config.php

// URL for SIRI Vehicle Monitoring(VM) SOAP service
// Kolumbus: http://sis.kolumbus.no:90/VMWS/VMService.svc
$url = '';

// RequestorRef - is sent in the SOAP request message body
// Cannot be empty.
// Unless you have to use a specific value given by the data-vendor, I suggest e-mail or name.
$requestorref = '';

// Duration of cache - Time To Live
$ttl = '10 SECONDS';

// Cache file. Must be writable (and readable) by PHP
$cachefil = "vm_geojson.cache";

// File to hold timestamp for when API was last called.
// Used for safer check of whether API has been called.
$cacheFileApi = "vm_apicall.cache";
?>