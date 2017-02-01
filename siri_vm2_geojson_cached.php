<?php
require("config.php");

ob_start("ob_gzhandler"); // enable gzip-compression of data sent to client

// http://stackoverflow.com/a/14554381/2252177
function xml_to_array($root) {
    $result = array();

    if ($root->hasAttributes()) {
        $attrs = $root->attributes;
        foreach ($attrs as $attr) {
            $result['@attributes'][$attr->name] = $attr->value;
        }
    }

    if ($root->hasChildNodes()) {
        $children = $root->childNodes;
        if ($children->length == 1) {
            $child = $children->item(0);
            if ($child->nodeType == XML_TEXT_NODE) {
                $result['_value'] = $child->nodeValue;
                return count($result) == 1
                    ? $result['_value']
                    : $result;
            }
        }
        $groups = array();
        foreach ($children as $child) {
            if (!isset($result[$child->nodeName])) {
                $result[$child->nodeName] = xml_to_array($child);
            } else {
                if (!isset($groups[$child->nodeName])) {
                    $result[$child->nodeName] = array($result[$child->nodeName]);
                    $groups[$child->nodeName] = 1;
                }
                $result[$child->nodeName][] = xml_to_array($child);
            }
        }
    }
    return $result;
}

function errordie($message, $statusCode = 504, $truncateCache = false) {
    global $cachefil;
    // 504 Gateway Timeout
    //   The server was acting as a gateway or proxy and did not receive 
    //   a timely response from the upstream server.
    http_response_code($statusCode);

    if ($truncateCache) {
        truncateFile($cachefil);
    }

    die($message);
}

function fetchData() {
    global $url, $requestorref;  

    $xml = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:siri="http://www.siri.org.uk/siri">
   <soapenv:Header/>
   <soapenv:Body>
      <siri:GetVehicleMonitoring>
         <ServiceRequestInfo>
            <siri:RequestTimestamp>' . date('c') . '</siri:RequestTimestamp>
            <siri:RequestorRef>' . $requestorref . '</siri:RequestorRef>
         </ServiceRequestInfo>
         <Request version="1.4">
            <siri:RequestTimestamp>' . date('c') . '</siri:RequestTimestamp>
         </Request>
         <RequestExtension>
            <!--You may enter ANY elements at this point-->
         </RequestExtension>
      </siri:GetVehicleMonitoring>
   </soapenv:Body>
</soapenv:Envelope>';

    $post_data = array(
        "xml" => $xml,
    );

    $stream_options = array(
        'http' => array(
           'method'  => 'POST',
           'header'  => "Content-type: text/xml; charset=UTF-8\r\nSOAPAction: GetVehicleMonitoring\r\n",
           'content' => $xml,
           'timeout' => 5.0
        ),
    );

    // Sjekkar tid det tar å spørre SIRI-server
    $time_start = microtime(true);

    $context  = stream_context_create($stream_options);
    $response = file_get_contents($url, null, $context)
      or errordie("Kunne ikkje hente data frå Kolumbus.", 504, true); // fjern denne linja for å få debug-info.

    // Summerer opp tid
    $time_end = microtime(true);
    $time = number_format($time_end - $time_start, 4);
    header('Kolumbus-query-time: ' . $time);

    $ob = simplexml_load_string($response);
    $json = json_encode($ob);
    $array = json_decode($json, true);

    $dom = new DOMDocument();
    $dom->loadXML($response);

    $data = xml_to_array($dom);

    $geojson = array();

    // sjekkar først om ein har fått innhald i responsen
    if (isset($data["s:Envelope"]["s:Body"]["GetVehicleMonitoringResponse"]["Answer"]["VehicleMonitoringDelivery"]["VehicleActivity"])) {
    $va = $data["s:Envelope"]["s:Body"]["GetVehicleMonitoringResponse"]["Answer"]["VehicleMonitoringDelivery"]["VehicleActivity"];

    foreach($va as $vehicle) {
        $v = $vehicle["MonitoredVehicleJourney"];
            if (isset($v["VehicleLocation"]["Longitude"])) {
                $details = "";
                foreach ($v as $key => $value) {
                    if (!is_array($v[$key]))
                        $details .= "$key: $value<br/>\n";
                }
                
                $bus = array("type" => "Feature", "id" => $v["VehicleRef"]); // , "id" => 
                $bus["geometry"] = @array(
                    "type" => "Point",
                    "coordinates" => array($v["VehicleLocation"]["Longitude"], $v["VehicleLocation"]["Latitude"])
                    );
                $bus["properties"] = $v;
                $bus["properties"]["id"] = $v["VehicleRef"];
                $bus["properties"]["RecordedAtTime"] = $vehicle["RecordedAtTime"];
                $geojson[] = $bus;
            } else {
                // echo "Feil!";
                // print_r($v);
            }
    }

    $geojson_struct = array("type" => "FeatureCollection", "features" => $geojson);
    }
    return json_encode($geojson);
}

function isCacheValid($cache, $ttl) {
    if (! file_exists($cache)) {
        return false;
    }
    //check if the file needs to be refreshed or not ?
    $last_modified = new DateTime('@'.filemtime($cache));
    $expire = new DateTime('-'.$ttl, new DateTimezone('UTC'));
    return $last_modified > $expire;
}

function truncateFile($file) {
    $fh = fopen($file, 'w');
    fclose($fh);
}

function hasServerBeenCalledRecently($cache, $ttl) {
    if (! file_exists($cache)) {
        errordie("API-cachefile does not exist!", 500);
        return false;
    }
    //check if the file needs to be refreshed or not ?
    $last_modified = new DateTime('@'.filemtime($cache));
    $expire = new DateTime('-'.$ttl, new DateTimezone('UTC'));
    header('X-API-time: ' . $last_modified->format('H:i:s'));
    header('X-API-expi: ' . $expire->format('H:i:s'));
    return $last_modified > $expire;
}

function getCachedData($file) {
    header('Hurtigbuffer-size: ' . filesize($file));
    if (filesize($file) == 0) {
        errordie("Kunne ikkje hente data frå Kolumbus. (hurtigbuffer-treff)");
    }
    return file_get_contents($file);
}

// Cache-settings
// Recipe from: http://nyamsprod.com/blog/2014/tutorial-how-to-cache-a-resource-using-php/
$geojson = "";

header('Content-Type: application/vnd.geo+json; charset=utf-8');
if (!isCacheValid($cachefil, $ttl)) {

    if (hasServerBeenCalledRecently($cacheFileApi, $ttl)) {
        // API has already been called - use cache
        header('Hurtigbuffer: treff - API allereie spurt');
        $geojson = getCachedData($cachefil);                
    } else {
        // Ask server for new data
        truncateFile($cacheFileApi);
        header('Hurtigbuffer: bom');
        $geojson = fetchData();
        file_put_contents($cachefil, $geojson);
    }

} else {
    header('Hurtigbuffer: treff');
    $geojson = getCachedData($cachefil);
}

// print_r($dp);
print($geojson);


/*
Sample data (PHP multidimensional array, print_r)

    [4] => Array
        (
            [RecordedAtTime] => 2015-04-24T21:05:03+02:00
            [ValidUntilTime] => 2015-04-24T22:06:04.6629029+02:00
            [ProgressBetweenStops] => Array
                (
                    [LinkDistance] => 9
                    [Percentage] => 3.7500
                )

            [MonitoredVehicleJourney] => Array
                (
                    [LineRef] => 1005
                    [DirectionRef] => go
                    [VehicleMode] => bus
                    [PublishedLineName] => 4
                    [OriginRef] => 11031530
                    [OriginName] => Rosenli
                    [DestinationRef] => 11031182
                    [DestinationName] => Madlakrossen hpl. 4
                    [OriginAimedDepartureTime] => 2015-04-24T20:33:00+02:00
                    [DestinationAimedArrivalTime] => 2015-04-24T21:11:00+02:00
                    [Monitored] => true
                    [VehicleLocation] => Array
                        (
                            [Longitude] => 5.684893
                            [Latitude] => 58.9429
                        )

                    [Delay] => PT-4S
                    [CourseOfJourneyRef] => 10051591
                    [VehicleRef] => 1155
                    [MonitoredCall] => Array
                        (
                            [StopPointRef] => 11031174
                            [VisitNumber] => 1
                            [StopPointName] => Madlamark kirke
                        )

                )

        )

*/
?>