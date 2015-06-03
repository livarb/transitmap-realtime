<?php
include("config.php");

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

function errordie($message) {
    truncateCache();

    // 504 Gateway Timeout
    //   The server was acting as a gateway or proxy and did not receive 
    //   a timely response from the upstream server.
    http_response_code(504);

    return $message;
}

function fetchData() {
    global $url, $requestorref;

    $xml = '<SOAP-ENV:Envelope xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns1="http://www.kolumbus.no/siri" version="1.4" xmlns:ns2="http://www.siri.org.uk/siri">
      <SOAP-ENV:Body>
        <ns1:GetVehicleMonitoring version="1.4">
          <ServiceRequestInfo version="1.4">
            <ns2:RequestTimestamp>' . date('c') . '</ns2:RequestTimestamp>
            <ns2:RequestorRef>' . $requestorref . '</ns2:RequestorRef>
          </ServiceRequestInfo>
          <Request version="1.4">
            <VehicleMonitoringRequest version="1.4">
              <RequestTimestamp>' . date('c') . '</RequestTimestamp>
              <VehicleMonitoringRef>VEHICLES_ALL</VehicleMonitoringRef>
            </VehicleMonitoringRequest>
          </Request>
          <RequestExtension version="1.4"/>
        </ns1:GetVehicleMonitoring>
      </SOAP-ENV:Body>
    </SOAP-ENV:Envelope>';

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

    $context  = stream_context_create($stream_options);
    $response = @file_get_contents($url, null, $context)
     or die(errordie("Kunne ikkje hente data frå Kolumbus.")); // fjern denne linja for å få debug-info.

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
        // print_r($vehicle["MonitoredVehicleJourney"]);
        $v = $vehicle["MonitoredVehicleJourney"];
        // if ($v["Monitored"] == "true" && isset($v["LineRef"])) {
            // print_r($v);
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
                    // "text" => $v["VehicleMode"] . " " . $v["VehicleRef"] . " - Linje " . $v["PublishedLineName"],
                    // "details" => $details
                    );
                $bus["properties"] = $v;
                // $bus["properties"]["name"] = $v["VehicleMode"] . " " . $v["VehicleRef"] . " - Linje " . $v["PublishedLineName"];
                $bus["properties"]["id"] = $v["VehicleRef"];
                $bus["properties"]["RecordedAtTime"] = $vehicle["RecordedAtTime"];
                // $bus["properties"]["details"] = $details;
                // array(
                //     "name" => $v["VehicleMode"] . " " . $v["VehicleRef"] . " - Linje " . $v["PublishedLineName"]
                //     // ,"details" => $details
                //     );
                // $bus["properties"][] = $v;
                $geojson[] = $bus;
            } else {
                // echo "Feil!";
                // print_r($v);
            }
        // }
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

function truncateCache() {
    global $cachefil;
    $fh = fopen($cachefil, 'w');
    fclose($fh);
}

// Cache-settings
// Recipe from: http://nyamsprod.com/blog/2014/tutorial-how-to-cache-a-resource-using-php/
$geojson = "";

if (!isCacheValid($cachefil, $ttl)) {
    header('Hurtigbuffer: bom');

    // if last cache hit was an error, invalidate immediately
    // so that no more requests to source is triggered by request by another user
    // happening before fetchData()-timeout.
    if (filesize($cachefil) == 0) {
        truncateCache();
    }

    $geojson = fetchData();

    header('Content-Type: application/vnd.geo+json; charset=utf-8');

    file_put_contents($cachefil, $geojson);
} else {
    header('Hurtigbuffer: treff');
    header('Content-Type: application/vnd.geo+json; charset=utf-8');
    header('Hurtigbuffer-size: ' . filesize($cachefil));
    if (filesize($cachefil) == 0) {
        errordie("Kunne ikkje hente data frå Kolumbus. (hurtigbuffer-treff)");
    } else {
        // header('Hurtigbuffer-size: ' . filesize($cachefil));
        $geojson = file_get_contents($cachefil);
    }
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