<!DOCTYPE html>
<html>
  <head>
    <title>Sanntid i Rogaland</title>
    <meta name="viewport" content="initial-scale=1.0, user-scalable=no">
    <meta charset="utf-8">
    <style>
      html, body, #map {
        height: 100%;
        margin: 0px;
        padding: 0px
      }
    </style>
    <link rel="stylesheet" href="http://cdn.leafletjs.com/leaflet-0.7.3/leaflet.css" />
    <link rel="stylesheet" href="//maxcdn.bootstrapcdn.com/font-awesome/4.3.0/css/font-awesome.min.css">
    <!-- <link rel="stylesheet" href="http://code.ionicframework.com/ionicons/1.5.2/css/ionicons.min.css"> -->
    <!-- <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/css/bootstrap.min.css"> -->
    <link rel="stylesheet" href="leaflet.awesome-markers.css">
    <link rel="stylesheet" href="style.css">
  </head>
  <body>
    <div id="map"></div>

    <script src="http://cdn.leafletjs.com/leaflet-0.7.3/leaflet.js"></script>
    <!-- <script src="//cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/0.4.0/leaflet.markercluster.js"></script> -->
    <script src="leaflet-realtime.js"></script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
    <!-- <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.4/js/bootstrap.min.js"></script> -->
    <script src="leaflet.awesome-markers.js"></script>
    <script src="//cdnjs.cloudflare.com/ajax/libs/moment.js/2.10.2/moment.min.js"></script>

    <script>
      (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
      (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
      m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
      })(window,document,'script','//www.google-analytics.com/analytics.js','ga');

      ga('create', 'UA-463806-11', 'auto');
      ga('send', 'pageview');

    </script>

    <script>
    var started = false;
    var mNow = moment();

    var total, monitored, onTime, delayed, active, resetCount;
    var errors = 0;

    function resetCounters() {
      total = 0;
      monitored = 0;
      onTime = 0;
      delayed = 0;
      active = 0;
      resetCount = false;
    };

    resetCounters();

    // basert på:
    // http://stackoverflow.com/a/8212878/2252177
    function millisecondsToStr(milliseconds, nulltext) {

        function numberEnding (number) {
            return (number > 1) ? 'ar' : '';
        }

        var temp = Math.floor(milliseconds / 1000);
        var years = Math.floor(temp / 31536000);
        if (years) {
            return years + ' år';
        }

        var days = Math.floor((temp %= 31536000) / 86400);
        if (days) {
            return days + ' dag' + numberEnding(days);
        }
        var hours = Math.floor((temp %= 86400) / 3600);
        if (hours) {
            if (hours > 1) {
              return hours + ' timar';
            } else {
              return hours + ' time';
            }
        }
        var minutes = Math.floor((temp %= 3600) / 60);
        if (minutes) {
            return minutes + ' minutt';
        }
        var seconds = temp % 60;
        if (seconds) {
            return seconds + ' sekund';
        }
        return nulltext; //'just now' //or other string you like;
    }

    function showDemo(asdf) {
      realtime.stop();
      started = false;
      realtime = L.realtime(
        'siri_vm2_geojson_demo.php'
      , 
      {
        interval: 10 * 1000,
        onEachFeature: function(feature, layer) {
          if (resetCount)
            resetCounters();

          var popuptext = '';
          var del = -9999;

          total++;
          monitored++;

          // Logikk for popuptext på ikonet
          if (feature.properties.PublishedLineName) {
            popuptext += "<b>Linje " + feature.properties.PublishedLineName + " (buss " 
                + feature.properties.VehicleRef + ")</b><br/>";
          } else {
            popuptext += "<b>Buss " + feature.properties.VehicleRef + "</b><br/>\n";
          }

          if (feature.properties.OriginName) {
              popuptext += feature.properties.OriginName + " - " + feature.properties.DestinationName + "<br/>\n";
          //   if (feature.properties.DirectionRef == "go") {
          //     popuptext += feature.properties.OriginName + " - " + feature.properties.DestinationName + "<br/>\n";
          //   } else {
          //     popuptext += feature.properties.DestinationName + " - " + feature.properties.OriginName + "<br/>\n";
          //   }
          }

          if (feature.properties.Delay) {
            del = parseInt(feature.properties.Delay.slice(2,-1));
            if (del < 0) del = 0; // for bussar som er foran tida
            popuptext += "Forsinkelse: " + millisecondsToStr(del*1000, 'ingen') + "<br/>\n"
          }

          // if (feature.properties.MonitoredCall.StopPointName)
          //   popuptext += "Neste haldeplass: " + feature.properties.MonitoredCall.StopPointName + "<br/>\n";

          if (feature.properties.MonitoringError)
            popuptext += "MonitoringError: " + feature.properties.MonitoringError + "<br/>\n";

          var lastRecorded = feature.properties.RecordedAtTime;
          var lastRecorded = moment(lastRecorded);

          // TODO: korrigere for at lastRecordet er eit øyeblikk i framtida.
          var lastRecordedDiff = mNow.diff(lastRecorded);
          if (lastRecordedDiff < 0) lastRecordedDiff = 0;
          popuptext += "Posisjon registrert: " + millisecondsToStr(lastRecordedDiff, '0 sekund') + " sidan<br/>\n";

          if (started) {
            var layerz = realtime.getLayer(feature.id);
            // layerz.unbindPopup();
            layerz.setPopupContent(popuptext);
          }
          else {
            layer.bindPopup(popuptext);
          }

          if (mNow.diff(lastRecorded) <= 300000)
            active++;


          if (feature.properties.Monitored == "false") {
            layer.setIcon(grayMarker);
            monitored--;
            if (mNow.diff(lastRecorded) > 300000) // dersom meir enn 5 min. sidan posisjon registrert
              layer.setOpacity(0.4);
            else {
              layer.setOpacity(1.0);
            }
          } else if (del != -9999) {
            if (del < 120) {
              layer.setIcon(greenMarker);
            } else if (del < 300) {
              layer.setIcon(yellowMarker);
            } else if (del > 600) {
              layer.setIcon(redspinMarker);
              delayed++;
            } else if (del > 300) {
              layer.setIcon(redMarker);
              delayed++;
            }
          } else {
            layer.setIcon(blueMarker);
          }
        }
      }
      ).addTo(map);

      realtime.on('update', function() {
          // console.log("Updated!");
          if (!started) {
            if (realtime.getLayers().length > 0) {
              map.fitBounds(realtime.getBounds(), {maxZoom: 16});
            }
          }
          started = true;
          resetCount = true;
          errors = 0;

          // Sjekkar om ein har fått data eller ikkje
          if (realtime.getLayers().length > 0) {
            ga('send', 'event', 'refresh', 'demo');

            $(".legend").html(
              "<b>" + total + "</b> køyretøy totalt" + "<br>\n"
              + "<b>" + monitored + "</b> køyretøy med sporing (Monitored)" + "<br>\n"
              + "<b>" + delayed + "</b> meir enn 5 minutt forseinka" + "<br>\n"
              + "<b>" + active + "</b> køyretøy som har rapportert<br/>&nbsp; posisjon innan siste 5 minutt" + "<br><br>\n"
              + "<b>" + "Forklaring på markørar" + "</b><br/>\n"
              + "Grønn" + " - i rute (maks 2 min. forseinking) <br/>\n"
              + "Gul" + " - meir enn 2 minutt forseinka<br/>\n"
              + "Mørk raud" + " - over 5 minutt forseinka<br/>\n"
              + "Raud" + " - over 10 minutt forseinka<br/>\n"
              + "Blå" + " - monitored, men Delay er ikkje angitt.<br/>\n"
              + "Grå" + " - ikkje monitored<br/>\n"                              
              + "Grå og gjennomsiktig" + " - ikkje monitored, <br/>&nbsp; meir enn 5 min. sidan siste posisjon.<br/>\n" 
              + "<br/><b>OBS</b> - viser DEMO-data.<br/><a href=\"#\" onClick=\"location.reload();\">Last inn nettsida på nytt</a> for å hente sanntidsdata."
              );
          } else {
            ga('send', 'event', 'refresh', 'nodata');
            $(".legend").html(
            '<b>Feil</b>: mottok ingen data. Prøver igjen.<br/>'
            + '<a href="#" onClick="showDemo(realtime);">Vis demo-data</a>'
            );
          }
      });

      realtime.stop();
    }


    var osm = L.tileLayer('https://{s}.tiles.mapbox.com/v3/{id}/{z}/{x}/{y}.png', {
      maxZoom: 18,
      attribution: 'Map data &copy; <a href="http://openstreetmap.org">OpenStreetMap</a> contributors, ' +
        '<a href="http://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, ' +
        'Imagery © <a href="http://mapbox.com">Mapbox</a>',
      id: 'examples.map-i875mjb7'
    });

    mapLink = '<a href="http://openstreetmap.org">OpenStreetMap</a>';
    translink = '<a href="http://thunderforest.com/">Thunderforest</a>';
    var OSMtransport = L.tileLayer(
    'http://{s}.tile.thunderforest.com/transport/{z}/{x}/{y}.png', {
        attribution: '&copy; '+mapLink+' Contributors & '+translink,
        maxZoom: 18,
    });

    // Sjølve kartet. Layers er dei som er skrudd på når ein opnar kartet
    var map = L.map('map', {
      layers: [OSMtransport]
    });

    // Markers
    var redMarker = L.AwesomeMarkers.icon({
      prefix: 'fa',
      icon: 'bus',
      markerColor: 'darkred'
    });

    var redspinMarker = L.AwesomeMarkers.icon({
      prefix: 'fa',
      icon: 'bus',
      markerColor: 'red'
      // spin: 'true'
    });

    var yellowMarker = L.AwesomeMarkers.icon({
      prefix: 'fa',
      icon: 'bus',
      markerColor: 'orange'
    });

    var greenMarker = L.AwesomeMarkers.icon({
      prefix: 'fa',
      icon: 'bus',
      markerColor: 'green'
    });    

    var blueMarker = L.AwesomeMarkers.icon({
      prefix: 'fa',
      icon: 'bus',
      markerColor: 'blue'
    });        

    var grayMarker = L.AwesomeMarkers.icon({
      prefix: 'fa',
      icon: 'bus',
      markerColor: 'lightgray'
    });        

    var realtime = L.realtime(
    {
      // url: 'test2.geojson',
      url: 'siri_vm2_geojson_cached.php',
      type: 'json',
      error: function (err) {
        // realtime.stop();
        errors++;
        $(".legend").html(
          '<b>Feil</b> ved lasting av data. Prøver igjen (' + errors + ').<br/>\n'
          + '<a href="#" onClick="showDemo(realtime);">Vis demo-data</a>'
          // + 'Last inn denne nettsida på nytt for å prøve igjen.'
          );
        ga('send', 'event', 'refresh', 'error');
      }
    }, 
    {
      interval: 10 * 1000,
      filter: function (feature, layer) {
        return (
            feature.properties.Monitored === "true"
            && feature.properties.PublishedLineName);
      },
      onEachFeature: function(feature, layer) {
        if (resetCount)
          resetCounters();

        var popuptext = '';
        var del = -9999;

        total++;
        monitored++;

        // Logikk for popuptext på ikonet
        if (feature.properties.PublishedLineName) {
          popuptext += "<b>Linje " + feature.properties.PublishedLineName + " (buss " 
              + feature.properties.VehicleRef + ")</b><br/>";
        } else {
          popuptext += "<b>Buss " + feature.properties.VehicleRef + "</b><br/>\n";
        }

        if (feature.properties.OriginName) {
            popuptext += feature.properties.OriginName + " - " + feature.properties.DestinationName + "<br/>\n";
        //   if (feature.properties.DirectionRef == "go") {
        //     popuptext += feature.properties.OriginName + " - " + feature.properties.DestinationName + "<br/>\n";
        //   } else {
        //     popuptext += feature.properties.DestinationName + " - " + feature.properties.OriginName + "<br/>\n";
        //   }
        }

        if (feature.properties.Delay) {
          del = parseInt(feature.properties.Delay.slice(2,-1));
          if (del < 0) del = 0; // for bussar som er foran tida
          popuptext += "Forsinkelse: " + millisecondsToStr(del*1000, 'ingen') + "<br/>\n"
        }

        // if (feature.properties.MonitoredCall.StopPointName)
        //   popuptext += "Neste haldeplass: " + feature.properties.MonitoredCall.StopPointName + "<br/>\n";

        if (feature.properties.MonitoringError)
          popuptext += "MonitoringError: " + feature.properties.MonitoringError + "<br/>\n";

        var lastRecorded = feature.properties.RecordedAtTime;
        var lastRecorded = moment(lastRecorded);

        // TODO: korrigere for at lastRecordet er eit øyeblikk i framtida.
        var lastRecordedDiff = mNow.diff(lastRecorded);
        if (lastRecordedDiff < 0) lastRecordedDiff = 0;
        popuptext += "Posisjon registrert: " + millisecondsToStr(lastRecordedDiff, '0 sekund') + " sidan<br/>\n";

        if (started) {
          var layerz = realtime.getLayer(feature.id);
          // layerz.unbindPopup();
          if (layerz) { // check if item exists or not
            layerz.setPopupContent(popuptext);
          } else { // new item
            layer.bindPopup(popuptext);
          }
        }
        else {
          layer.bindPopup(popuptext);
        }

        if (mNow.diff(lastRecorded) <= 300000)
          active++;


        if (feature.properties.Monitored == "false") {
          layer.setIcon(grayMarker);
          monitored--;
          if (mNow.diff(lastRecorded) > 300000) // dersom meir enn 5 min. sidan posisjon registrert
            layer.setOpacity(0.4);
          else {
            layer.setOpacity(1.0);
          }
        } else if (del != -9999) {
          if (del < 120) {
            layer.setIcon(greenMarker);
          } else if (del < 300) {
            layer.setIcon(yellowMarker);
          } else if (del > 600) {
            layer.setIcon(redspinMarker);
            delayed++;
          } else if (del > 300) {
            layer.setIcon(redMarker);
            delayed++;
          }
        } else {
          layer.setIcon(blueMarker);
        }
      }
    }).addTo(map);

    realtime.on('update', function() {
        // console.log("Updated!");
        if (!started) {
          if (realtime.getLayers().length > 0) {
            map.fitBounds(realtime.getBounds(), {maxZoom: 16});
          }
        }
        started = true;
        resetCount = true;
        errors = 0;

        // Sjekkar om ein har fått data eller ikkje
        if (realtime.getLayers().length > 0) {
          ga('send', 'event', 'refresh', 'success');

          $(".legend").html(
            "<b>Sanntidskart for bussar i Rogaland</b><br>\n"
            + "<b>" + monitored + "</b> køyretøy med sporing" + "<br>\n"
            + "<b>" + delayed + "</b> meir enn 5 minutt forseinka" + "<br>\n"
            // + "<b>" + active + "</b> køyretøy som har rapportert<br/>&nbsp; posisjon innan siste 5 minutt" + "<br><br>\n"
            + "<b>" + "<a href=\"#\" onClick=\"$('#forklaring').toggle();\">Forklaring på markørar</a></b><br/>" 
            + "<div id=\"forklaring\">\n"
            + "Grøn" + " - i rute (maks 2 min. forseinking) <br/>\n"
            + "Gul" + " - meir enn 2 minutt forseinka<br/>\n"
            + "Mørk raud" + " - over 5 minutt forseinka<br/>\n"
            + "Raud" + " - over 10 minutt forseinka<br/>\n"
            + "Blå" + " - monitored, men Delay er ikkje angitt.<br/>\n"
            + "Grå" + " - ikkje monitored<br/>\n"                              
            + "Grå og gjennomsiktig" + " - ikkje monitored, <br/>&nbsp; meir enn 5 min. sidan siste posisjon.</div>\n" 
            + "<br/>"
            + "App i beta - Livar Bergheim<br/>"
            + "Inneholder <a href=\"https://data.norge.no/data/kolumbus/sanntidsdata-kolumbus-buss-i-rogaland\">data under Norsk lisens for<br/> offentlige data (NLOD) tilgjengeliggjort<br/> av Kolumbus<br/>"
            + "<a href=\"https://github.com/livarb/transitmap-realtime\">Kjeldekode på GitHub</a>"
            );
        } else {
          ga('send', 'event', 'refresh', 'nodata');
          $(".legend").html(
          '<b>Feil</b>: mottok ingen data. Prøver igjen.<br/>'
          + '<a href="#" onClick="showDemo(realtime);">Vis demo-data</a>'
          );
        }
    }); 

    // Legg til boks med tekst nede til venstre i kartet
    var legend = L.control({position: 'bottomleft'});
    legend.onAdd = function (map) {
      var div = L.DomUtil.create('div', 'info legend');
      var tekst = "Lastar informasjon..."
      div.innerHTML = tekst;
      return div;
    };
    legend.addTo(map);

    // Set opp Layer-kontroll
    var baseLayers = {
      "Standard (OpenStreetMap)": osm,
      "Transport map (OpenStreetMap)": OSMtransport
    };
    var overlays = {
      "Sanntidsposisjonar for bussar (Kolumbus)": realtime
    };

    L.control.layers(
      baseLayers,
      overlays
    ).addTo(map);

  </script>

  </body>
</html>